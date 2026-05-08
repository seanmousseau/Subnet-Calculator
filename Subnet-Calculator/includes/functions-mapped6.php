<?php

declare(strict_types=1);

// IPv4-mapped IPv6 (RFC 4291 §2.5.5.2) and NAT64 (RFC 6052) conversion
// helpers.
//
// Supports the IPv4-mapped prefix `::ffff:0:0/96` and the well-known NAT64
// prefix `64:ff9b::/96`, plus operator-supplied custom NAT64 prefixes
// (must be /96 — RFC 6052 §2.2 also defines /32, /40, /48, /56, /64
// variants but this tool covers only the most common /96 form).

const MAPPED6_PREFIX_BIN  = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";
const NAT64_DEFAULT_PREFIX = '64:ff9b::/96';

/**
 * Return true if $address parses as a valid IPv6 literal whose top 96
 * bits are `::ffff:0:0` (the IPv4-mapped IPv6 prefix). Returns false
 * for invalid input rather than throwing.
 */
function is_ipv4_mapped(string $address): bool
{
    $bin = @inet_pton($address);
    return $bin !== false
        && strlen($bin) === 16
        && substr($bin, 0, 12) === MAPPED6_PREFIX_BIN;
}

/**
 * Convert an IPv4 address (dotted-quad) to its IPv4-mapped IPv6 form.
 *
 * @throws InvalidArgumentException if $v4 is not a valid IPv4 literal.
 */
function ipv4_to_mapped(string $v4): string
{
    $bin = @inet_pton($v4);
    if ($bin === false || strlen($bin) !== 4) {
        throw new InvalidArgumentException('Invalid IPv4 address');
    }
    $v6bin = MAPPED6_PREFIX_BIN . $bin;
    $out   = @inet_ntop($v6bin);
    if ($out === false) {
        throw new InvalidArgumentException('Failed to format IPv4-mapped IPv6 address');
    }
    return $out;
}

/**
 * Extract the embedded IPv4 from an IPv4-mapped IPv6 address.
 *
 * @throws InvalidArgumentException if $v6 is not a valid IPv4-mapped
 *         IPv6 literal.
 */
function mapped_to_ipv4(string $v6): string
{
    if (!is_ipv4_mapped($v6)) {
        throw new InvalidArgumentException('Not an IPv4-mapped IPv6 address');
    }
    $bin = inet_pton($v6);
    /** @var string $bin — guaranteed by is_ipv4_mapped() */
    $out = @inet_ntop(substr($bin, 12, 4));
    if ($out === false) {
        throw new InvalidArgumentException('Failed to extract IPv4 from mapped address');
    }
    return $out;
}

/**
 * Parse a `<address>/96` prefix string and return the binary
 * representation of its top 12 bytes.
 *
 * @throws InvalidArgumentException if $prefix is not a /96 literal.
 *
 * @internal helper for the NAT64 functions.
 */
function _nat64_prefix_bin(string $prefix): string
{
    if (preg_match('#^(.+)/96$#', $prefix, $m) !== 1) {
        throw new InvalidArgumentException('NAT64 prefix must be /96');
    }
    $bin = @inet_pton($m[1]);
    if ($bin === false || strlen($bin) !== 16) {
        throw new InvalidArgumentException('Invalid NAT64 prefix address');
    }
    return substr($bin, 0, 12);
}

/**
 * Return true if $address is a valid IPv6 literal whose top 96 bits
 * match the supplied NAT64 prefix. Returns false (no throw) for any
 * invalid input — callers that need strict validation should use
 * nat64_to_ipv4() / ipv4_to_nat64().
 */
function is_nat64(string $address, string $prefix = NAT64_DEFAULT_PREFIX): bool
{
    $bin = @inet_pton($address);
    if ($bin === false || strlen($bin) !== 16) {
        return false;
    }
    try {
        return substr($bin, 0, 12) === _nat64_prefix_bin($prefix);
    } catch (InvalidArgumentException) {
        return false;
    }
}

/**
 * Embed an IPv4 address inside the supplied NAT64 prefix.
 *
 * @throws InvalidArgumentException if $v4 is not a valid IPv4 literal,
 *         or $prefix is not a /96 NAT64 prefix.
 */
function ipv4_to_nat64(string $v4, string $prefix = NAT64_DEFAULT_PREFIX): string
{
    $v4bin = @inet_pton($v4);
    if ($v4bin === false || strlen($v4bin) !== 4) {
        throw new InvalidArgumentException('Invalid IPv4 address');
    }
    $prefBin = _nat64_prefix_bin($prefix);
    $out     = @inet_ntop($prefBin . $v4bin);
    if ($out === false) {
        throw new InvalidArgumentException('Failed to format NAT64 IPv6 address');
    }
    return $out;
}

/**
 * Extract the embedded IPv4 from a NAT64 IPv6 address.
 *
 * @throws InvalidArgumentException if $v6 is not within $prefix or
 *         $prefix is not a /96 NAT64 prefix.
 */
function nat64_to_ipv4(string $v6, string $prefix = NAT64_DEFAULT_PREFIX): string
{
    if (!is_nat64($v6, $prefix)) {
        throw new InvalidArgumentException('Address is not within the supplied NAT64 prefix');
    }
    $bin = inet_pton($v6);
    /** @var string $bin — guaranteed by is_nat64() */
    $out = @inet_ntop(substr($bin, 12, 4));
    if ($out === false) {
        throw new InvalidArgumentException('Failed to extract IPv4 from NAT64 address');
    }
    return $out;
}
