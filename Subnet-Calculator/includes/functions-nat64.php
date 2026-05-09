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
 * DNS64 (RFC 6147) AAAA synthesis. Given an A record (IPv4) and a NAT64
 * prefix + length, return the synthetic AAAA. Defaults to the well-known
 * prefix 64:ff9b::/96.
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
 * RFC 6052 §3.1 globally-unique check. Returns false for RFC 1918,
 * loopback, link-local, multicast, "this network" 0.0.0.0/8,
 * 100.64.0.0/10 (CGN), 192.0.0.0/24, documentation prefixes, broadcast,
 * and 240.0.0.0/4 reserved.
 *
 * @internal
 */
function nat64_ipv4_is_globally_unique(string $ipv4): bool
{
    $bin = @inet_pton($ipv4);
    if ($bin === false || strlen($bin) !== 4) {
        return false;
    }
    // FILTER_FLAG_NO_PRIV_RANGE excludes RFC 1918 and the unique-local
    // ranges; FILTER_FLAG_NO_RES_RANGE excludes loopback, link-local,
    // multicast, 0.0.0.0/8, 240.0.0.0/4, etc.
    $ok = filter_var(
        $ipv4,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
    return $ok !== false;
}
