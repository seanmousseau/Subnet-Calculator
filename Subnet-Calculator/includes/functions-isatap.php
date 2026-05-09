<?php

declare(strict_types=1);

// ISATAP interface-ID helper (RFC 5214) — bidirectional encoding/decoding
// of the 64-bit ISATAP interface identifier that embeds an IPv4 address.
//
//   | 32 bits         | 32 bits          |
//   | 0000:5efe       | V4ADDR (raw)     |   locally-administered
//   | 0200:5efe       | V4ADDR (raw)     |   globally-unique (u-bit set)
//
// The IID is appended to any /64 prefix to form a complete IPv6 address.
// `0x5efe` is the IANA-assigned OUI-style value for ISATAP. The leading
// 16 bits encode the universal/local (u) bit per RFC 4291 §2.5.1: 0x0000
// for locally-administered (i.e. the embedded V4 is from a private/
// reserved range and not globally unique), 0x0200 for globally-unique.
//
// RFC 5214 was published in 2008. ISATAP deployment has been very limited
// outside specific enterprise transition scenarios. This tool is provided
// for analysis of legacy traffic and address audits.

/**
 * Decide whether an IPv4 address is globally-unique for ISATAP auto-classify.
 *
 * Mirrors the explicit byte-level checks used by T7's NAT64 helper. PHP's
 * FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE does not reject
 * multicast (224.0.0.0/4), class-E (240.0.0.0/4), 0.0.0.0, CGN
 * (100.64.0.0/10), TEST-NET-1/2/3, 192.0.0.0/24, or benchmarking
 * (198.18.0.0/15) — so we layer those checks on top.
 */
function isatap_ipv4_is_globally_unique(string $ipv4): bool
{
    if (
        filter_var(
            $ipv4,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false
    ) {
        return false;
    }
    $packed = @inet_pton($ipv4);
    if ($packed === false || strlen($packed) !== 4) {
        return false;
    }
    $b = array_map('ord', str_split($packed));
    // 0.0.0.0/8 "this network"
    if ($b[0] === 0) {
        return false;
    }
    // Multicast 224.0.0.0/4
    if (($b[0] & 0xF0) === 0xE0) {
        return false;
    }
    // Reserved / class-E 240.0.0.0/4 (includes 255.255.255.255 broadcast)
    if (($b[0] & 0xF0) === 0xF0) {
        return false;
    }
    // CGN 100.64.0.0/10
    if ($b[0] === 100 && ($b[1] & 0xC0) === 0x40) {
        return false;
    }
    // 192.0.0.0/24 (IETF protocol assignments) + TEST-NET-1 192.0.2.0/24
    if ($b[0] === 192 && $b[1] === 0 && $b[2] === 0) {
        return false;
    }
    if ($b[0] === 192 && $b[1] === 0 && $b[2] === 2) {
        return false;
    }
    // Benchmarking 198.18.0.0/15
    if ($b[0] === 198 && ($b[1] & 0xFE) === 18) {
        return false;
    }
    // TEST-NET-2 198.51.100.0/24
    if ($b[0] === 198 && $b[1] === 51 && $b[2] === 100) {
        return false;
    }
    // TEST-NET-3 203.0.113.0/24
    if ($b[0] === 203 && $b[1] === 0 && $b[2] === 113) {
        return false;
    }
    return true;
}

/**
 * Build an ISATAP interface ID from an IPv4 address per RFC 5214 §6.
 *
 * @param string    $ipv4
 * @param ?bool $globally_unique  When null, infer via
 *                                 isatap_ipv4_is_globally_unique(): public
 *                                 unicast IPv4 → true; private, reserved,
 *                                 multicast, class-E, CGN, TEST-NET, or
 *                                 benchmarking → false.
 *                                 When true, builds '200:5efe:V4ADDR'.
 *                                 When false, builds '0:5efe:V4ADDR'.
 *
 * @return string The 64-bit IID as a colon-grouped string with leading zeros
 *                stripped from each hexadectet (matching inet_ntop()-style
 *                canonical output), e.g. '0:5efe:c000:201' or
 *                '200:5efe:c000:201'.
 *
 * @throws InvalidArgumentException if $ipv4 is not a valid IPv4 literal.
 */
function ipv4_to_isatap_iid(string $ipv4, ?bool $globally_unique = null): string
{
    $raw = trim($ipv4);
    if ($raw === '') {
        throw new InvalidArgumentException('IPv4 address is required.');
    }
    if (filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        throw new InvalidArgumentException('Invalid IPv4 address: ' . $raw);
    }

    $bin = @inet_pton($raw);
    if ($bin === false || strlen($bin) !== 4) {
        throw new InvalidArgumentException('Invalid IPv4 address: ' . $raw);
    }

    if ($globally_unique === null) {
        // Globally-unique means routable on the public internet — excludes
        // private (RFC 1918), reserved (loopback/link-local/etc.), multicast,
        // class-E, CGN, TEST-NET, and benchmarking ranges.
        $globally_unique = isatap_ipv4_is_globally_unique($raw);
    }

    // First two hexadectets: ISATAP magic.
    $hd1 = $globally_unique ? 0x0200 : 0x0000;
    $hd2 = 0x5efe;

    // Bottom two hexadectets: pack the v4 octets as two big-endian 16-bit groups.
    $hd3 = (ord($bin[0]) << 8) | ord($bin[1]);
    $hd4 = (ord($bin[2]) << 8) | ord($bin[3]);

    // Format like inet_ntop() canonical hex: leading zeros stripped per group.
    return sprintf('%x:%x:%x:%x', $hd1, $hd2, $hd3, $hd4);
}

/**
 * Recognise an ISATAP IID and extract the embedded IPv4.
 *
 * Accepts both the canonical compressed form ('0:5efe:c000:201') and the
 * fully zero-padded form ('0000:5efe:c000:0201'). Trailing scope or zone
 * suffixes are not accepted — callers should pass just the bottom-64 IID.
 *
 * @return array{ipv4: string, globally_unique: bool}
 *
 * @throws InvalidArgumentException if the IID does not match the ISATAP
 *         pattern or is otherwise unparseable.
 */
function decode_isatap_iid(string $iid): array
{
    $raw = trim($iid);
    if ($raw === '') {
        throw new InvalidArgumentException('ISATAP IID is required.');
    }

    $parts = explode(':', $raw);
    if (count($parts) !== 4) {
        throw new InvalidArgumentException(
            'ISATAP IID must have exactly four colon-separated hexadectets: ' . $raw
        );
    }
    foreach ($parts as $hd) {
        if ($hd === '' || strlen($hd) > 4 || !ctype_xdigit($hd)) {
            throw new InvalidArgumentException(
                'ISATAP IID contains an invalid hexadectet: ' . $raw
            );
        }
    }

    $hd1 = hexdec($parts[0]);
    $hd2 = hexdec($parts[1]);
    $hd3 = hexdec($parts[2]);
    $hd4 = hexdec($parts[3]);

    if ($hd2 !== 0x5efe) {
        throw new InvalidArgumentException(
            'ISATAP IID magic (second hexadectet) must be 5efe: ' . $raw
        );
    }
    if ($hd1 !== 0x0000 && $hd1 !== 0x0200) {
        throw new InvalidArgumentException(
            'ISATAP IID first hexadectet must be 0000 or 0200: ' . $raw
        );
    }

    // Reassemble the v4 from the bottom two hexadectets.
    $v4Bin = chr(($hd3 >> 8) & 0xFF)
           . chr($hd3 & 0xFF)
           . chr(($hd4 >> 8) & 0xFF)
           . chr($hd4 & 0xFF);
    $v4 = @inet_ntop($v4Bin);
    if ($v4 === false) {
        throw new InvalidArgumentException('Internal error decoding embedded IPv4.');
    }

    return [
        'ipv4'            => $v4,
        'globally_unique' => $hd1 === 0x0200,
    ];
}
