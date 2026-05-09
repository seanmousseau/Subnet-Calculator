<?php

declare(strict_types=1);

// NAT64 (RFC 6052) prefix-length-aware helpers and DNS64 (RFC 6147)
// synthesis. Extends the v3.4.0 mapped6 module (functions-mapped6.php)
// with the full set of RFC 6052 §2.2 prefix lengths {32, 40, 48, 56, 64,
// 96}; the existing /96-only ipv4_to_nat64() / nat64_to_ipv4() are
// preserved for backwards compatibility.
//
// RFC 6052 §2.2 bit layout — the IPv4 address is embedded into the
// 32 bits following the NAT64 prefix, with the constraint that bits
// 64–71 (the 9th octet of the IPv6 address) are reserved as zero (the
// "u-octet"). Concretely:
//
//   PL=32: bits  32– 63 = v4
//   PL=40: bits  40– 63 = v4[0..23]; bits 72– 79 = v4[24..31]
//   PL=48: bits  48– 63 = v4[0..15]; bits 72– 87 = v4[16..31]
//   PL=56: bits  56– 63 = v4[0.. 7]; bits 72– 95 = v4[ 8..31]
//   PL=64:                            bits 72–103 = v4[ 0..31]
//   PL=96: bits  96–127 = v4
//
// At every non-/96 length bits 64–71 stay zero. The remaining bits
// after the embedded v4 are the suffix; per RFC 6052 §2.2 the suffix
// SHOULD be all zeros for synthesised addresses. We always emit zeros
// and we tolerate non-zero suffix on extract (so user-supplied NAT64
// addresses with non-zero interface IDs still decode correctly).

const NAT64_VALID_PREFIX_LENGTHS = [32, 40, 48, 56, 64, 96];
const NAT64_WELL_KNOWN_PREFIX    = '64:ff9b::';

/**
 * Embed an IPv4 into a NAT64 prefix per RFC 6052 §2.2.
 *
 * @param string $ipv4          Dotted-quad IPv4 literal.
 * @param string $nat64_prefix  IPv6 literal of the NAT64 prefix without
 *                              the `/PL` suffix. Bits beyond
 *                              $prefix_length must be zero.
 * @param int    $prefix_length One of 32, 40, 48, 56, 64, 96.
 *
 * @return string Canonical IPv6 literal with embedded IPv4.
 *
 * @throws InvalidArgumentException on any input violation.
 */
function nat64_embed(string $ipv4, string $nat64_prefix, int $prefix_length): string
{
    nat64_assert_prefix_length($prefix_length);
    $v4bin = @inet_pton($ipv4);
    if ($v4bin === false || strlen($v4bin) !== 4) {
        throw new InvalidArgumentException('Invalid IPv4 address');
    }
    if (filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        throw new InvalidArgumentException('Invalid IPv4 address');
    }

    $prefBin = nat64_prefix_bin_validated($nat64_prefix, $prefix_length);

    // RFC 6052 §3.1: the well-known prefix MUST NOT be used to translate
    // non-globally-unique IPv4 addresses (RFC 1918 private-use,
    // loopback, link-local, etc.).
    if (nat64_is_well_known($nat64_prefix) && !nat64_ipv4_is_globally_unique($ipv4)) {
        throw new InvalidArgumentException(
            'RFC 6052 §3.1 forbids embedding non-globally-unique IPv4 addresses '
            . 'into the well-known prefix 64:ff9b::/96'
        );
    }

    $bytes = array_values(unpack('C16', $prefBin) ?: []);
    if (count($bytes) !== 16) {
        throw new InvalidArgumentException('Invalid NAT64 prefix bytes');
    }
    $v4bytes = array_values(unpack('C4', $v4bin) ?: []);
    if (count($v4bytes) !== 4) {
        throw new InvalidArgumentException('Invalid IPv4 bytes');
    }

    // Stuff the IPv4 octets into the byte positions defined by RFC 6052
    // §2.2, skipping byte 8 (bits 64–71, the u-octet).
    switch ($prefix_length) {
        case 32:
            // Bytes 4..7 ← v4[0..3].
            $bytes[4] = $v4bytes[0];
            $bytes[5] = $v4bytes[1];
            $bytes[6] = $v4bytes[2];
            $bytes[7] = $v4bytes[3];
            break;
        case 40:
            // Bytes 5..7 ← v4[0..2]; byte 9 ← v4[3].
            $bytes[5] = $v4bytes[0];
            $bytes[6] = $v4bytes[1];
            $bytes[7] = $v4bytes[2];
            $bytes[9] = $v4bytes[3];
            break;
        case 48:
            // Bytes 6..7 ← v4[0..1]; bytes 9..10 ← v4[2..3].
            $bytes[6]  = $v4bytes[0];
            $bytes[7]  = $v4bytes[1];
            $bytes[9]  = $v4bytes[2];
            $bytes[10] = $v4bytes[3];
            break;
        case 56:
            // Byte 7 ← v4[0]; bytes 9..11 ← v4[1..3].
            $bytes[7]  = $v4bytes[0];
            $bytes[9]  = $v4bytes[1];
            $bytes[10] = $v4bytes[2];
            $bytes[11] = $v4bytes[3];
            break;
        case 64:
            // Bytes 9..12 ← v4[0..3].
            $bytes[9]  = $v4bytes[0];
            $bytes[10] = $v4bytes[1];
            $bytes[11] = $v4bytes[2];
            $bytes[12] = $v4bytes[3];
            break;
        case 96:
            // Bytes 12..15 ← v4[0..3].
            $bytes[12] = $v4bytes[0];
            $bytes[13] = $v4bytes[1];
            $bytes[14] = $v4bytes[2];
            $bytes[15] = $v4bytes[3];
            break;
    }

    /** @var string $packed */
    $packed = pack('C16', ...$bytes);
    $out    = @inet_ntop($packed);
    if ($out === false) {
        throw new InvalidArgumentException('Failed to format NAT64 IPv6 address');
    }
    return $out;
}

/**
 * Extract the embedded IPv4 from a NAT64 IPv6 address.
 *
 * @param string $ipv6          IPv6 literal containing the embedded v4.
 * @param string $nat64_prefix  IPv6 literal of the NAT64 prefix.
 * @param int    $prefix_length One of 32, 40, 48, 56, 64, 96.
 *
 * @return string Dotted-quad IPv4.
 *
 * @throws InvalidArgumentException on any input violation.
 */
function nat64_extract(string $ipv6, string $nat64_prefix, int $prefix_length): string
{
    nat64_assert_prefix_length($prefix_length);
    $bin = @inet_pton($ipv6);
    if ($bin === false || strlen($bin) !== 16) {
        throw new InvalidArgumentException('Invalid IPv6 address');
    }
    $prefBin = nat64_prefix_bin_validated($nat64_prefix, $prefix_length);

    // Verify that the supplied IPv6 actually carries the prefix bits.
    $prefBytes = (int) ($prefix_length / 8);
    if (substr($bin, 0, $prefBytes) !== substr($prefBin, 0, $prefBytes)) {
        throw new InvalidArgumentException(
            'Address is not within the supplied NAT64 prefix'
        );
    }

    $bytes = array_values(unpack('C16', $bin) ?: []);
    if (count($bytes) !== 16) {
        throw new InvalidArgumentException('Failed to unpack IPv6 bytes');
    }

    switch ($prefix_length) {
        case 32:
            $v4 = [$bytes[4], $bytes[5], $bytes[6], $bytes[7]];
            break;
        case 40:
            $v4 = [$bytes[5], $bytes[6], $bytes[7], $bytes[9]];
            break;
        case 48:
            $v4 = [$bytes[6], $bytes[7], $bytes[9], $bytes[10]];
            break;
        case 56:
            $v4 = [$bytes[7], $bytes[9], $bytes[10], $bytes[11]];
            break;
        case 64:
            $v4 = [$bytes[9], $bytes[10], $bytes[11], $bytes[12]];
            break;
        case 96:
        default:
            $v4 = [$bytes[12], $bytes[13], $bytes[14], $bytes[15]];
            break;
    }

    /** @var string $packed */
    $packed = pack('C4', ...$v4);
    $out    = @inet_ntop($packed);
    if ($out === false || filter_var($out, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        throw new InvalidArgumentException('Failed to extract IPv4 from NAT64 address');
    }
    return $out;
}

/**
 * DNS64 synthesis per RFC 6147.
 *
 * Synthesises an AAAA record for the given A-record IPv4 under the given NAT64
 * prefix. Defaults to the well-known prefix 64:ff9b::/96.
 *
 * NOTE: When using the well-known prefix, private IPv4 addresses are rejected
 * per RFC 6052 §3.1. When using a Network-Specific Prefix (NSP), private IPv4
 * synthesis is PERMITTED — it is the operator's responsibility to configure
 * which destinations to synthesize via DNS64 ACLs (RFC 6147 §5.1.4).
 *
 * @param string $a_record_ipv4
 * @param string $nat64_prefix    Defaults to '64:ff9b::'
 * @param int    $prefix_length   Defaults to 96
 * @return string                 Synthesised AAAA
 */
function dns64_synthesize(
    string $a_record_ipv4,
    string $nat64_prefix = NAT64_WELL_KNOWN_PREFIX,
    int $prefix_length = 96
): string {
    return nat64_embed($a_record_ipv4, $nat64_prefix, $prefix_length);
}

/**
 * @throws InvalidArgumentException
 *
 * @internal
 */
function nat64_assert_prefix_length(int $prefix_length): void
{
    if (!in_array($prefix_length, NAT64_VALID_PREFIX_LENGTHS, true)) {
        throw new InvalidArgumentException(
            'NAT64 prefix length must be one of 32, 40, 48, 56, 64, 96 (RFC 6052 §2.2)'
        );
    }
}

/**
 * Return the 16-byte representation of $prefix, asserting that it parses
 * as a valid IPv6 literal AND that all bits beyond $prefix_length are
 * zero (so the prefix is properly aligned to its declared length).
 *
 * @throws InvalidArgumentException
 *
 * @internal
 */
function nat64_prefix_bin_validated(string $prefix, int $prefix_length): string
{
    $prefix = trim($prefix);
    // Tolerate a trailing /PL that matches $prefix_length.
    if (preg_match('#^(.*)/(\d+)$#', $prefix, $m) === 1) {
        $declared = (int) $m[2];
        if ($declared !== $prefix_length) {
            throw new InvalidArgumentException(
                "NAT64 prefix carries /{$declared} but caller requested /{$prefix_length}"
            );
        }
        $prefix = $m[1];
    }
    $bin = @inet_pton($prefix);
    if ($bin === false || strlen($bin) !== 16) {
        throw new InvalidArgumentException('Invalid NAT64 prefix address');
    }

    // All bits beyond $prefix_length must be zero (prefix is aligned).
    $fullBytes = intdiv($prefix_length, 8);
    $remBits   = $prefix_length % 8;
    for ($i = $fullBytes; $i < 16; $i++) {
        $b = ord($bin[$i]);
        if ($i === $fullBytes && $remBits > 0) {
            $mask = (0xFF << (8 - $remBits)) & 0xFF;
            if (($b & ~$mask & 0xFF) !== 0) {
                throw new InvalidArgumentException(
                    "NAT64 prefix has non-zero bits beyond /{$prefix_length}"
                );
            }
        } elseif ($b !== 0) {
            throw new InvalidArgumentException(
                "NAT64 prefix has non-zero bits beyond /{$prefix_length}"
            );
        }
    }

    return $bin;
}

/**
 * Return true if $prefix is the RFC 6052 well-known prefix `64:ff9b::`
 * (with optional `/96`).
 *
 * @internal
 */
function nat64_is_well_known(string $prefix): bool
{
    $prefix = trim($prefix);
    if (preg_match('#^(.*)/\d+$#', $prefix, $m) === 1) {
        $prefix = $m[1];
    }
    $a = @inet_pton($prefix);
    $b = @inet_pton(NAT64_WELL_KNOWN_PREFIX);
    return $a !== false && $b !== false && $a === $b;
}

/**
 * Determine whether the supplied IPv4 is globally unique per RFC 6890.
 *
 * Returns false for:
 *   - RFC 1918 private (10/8, 172.16/12, 192.168/16)
 *   - Loopback (127/8)
 *   - Link-local (169.254/16)
 *   - Multicast (224/4)
 *   - Reserved (240/4) and broadcast (255.255.255.255)
 *   - 0.0.0.0/8 (this network)
 *   - 100.64.0.0/10 (CGN, RFC 6598)
 *   - 192.0.0.0/24 (IETF protocol assignments)
 *   - 192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24 (TEST-NET-1/2/3)
 *   - 198.18.0.0/15 (benchmarking, RFC 2544)
 *
 * Returns true for ordinary global unicast IPv4.
 *
 * @internal
 */
function nat64_ipv4_is_globally_unique(string $ipv4): bool
{
    // FILTER_FLAG_NO_PRIV_RANGE excludes RFC 1918; FILTER_FLAG_NO_RES_RANGE
    // excludes loopback, link-local, multicast, 0.0.0.0/8, 240.0.0.0/4, etc.
    // Neither flag covers CGN, TEST-NET, IETF protocol-assignment, or
    // benchmarking ranges — these are checked explicitly below.
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
    // CGN 100.64.0.0/10 (RFC 6598)
    if ($b[0] === 100 && ($b[1] & 0xC0) === 0x40) {
        return false;
    }
    // 192.0.0.0/24 IETF Protocol Assignments (RFC 6890)
    if ($b[0] === 192 && $b[1] === 0 && $b[2] === 0) {
        return false;
    }
    // 192.0.2.0/24 TEST-NET-1 (RFC 5737)
    if ($b[0] === 192 && $b[1] === 0 && $b[2] === 2) {
        return false;
    }
    // 198.18.0.0/15 Benchmarking (RFC 2544)
    if ($b[0] === 198 && ($b[1] & 0xFE) === 18) {
        return false;
    }
    // 198.51.100.0/24 TEST-NET-2 (RFC 5737)
    if ($b[0] === 198 && $b[1] === 51 && $b[2] === 100) {
        return false;
    }
    // 203.0.113.0/24 TEST-NET-3 (RFC 5737)
    if ($b[0] === 203 && $b[1] === 0 && $b[2] === 113) {
        return false;
    }
    return true;
}
