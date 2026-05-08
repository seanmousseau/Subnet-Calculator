<?php

declare(strict_types=1);

/**
 * IPv6 reverse-DNS helpers (RFC 3596).
 *
 * Generates ip6.arpa zone names from an IPv6 address and optional
 * prefix length. Prefix length must be a multiple of 4 — the RFC's
 * nibble-boundary requirement for delegation.
 */

/**
 * Convert an IPv6 address (and optional nibble-aligned prefix length) to
 * its ip6.arpa form per RFC 3596.
 *
 * When $prefix is null (or 128), returns the full reverse name (32
 * nibbles + ".ip6.arpa"). When $prefix is a non-128 multiple of 4,
 * returns the corresponding zone-delegation name (the most-significant
 * $prefix/4 nibbles, reversed and dotted, followed by ".ip6.arpa").
 * A prefix of 0 returns the bare suffix "ip6.arpa".
 *
 * @throws InvalidArgumentException if $address is not a valid IPv6
 *         literal, or $prefix is not in [0, 128] or not nibble-aligned.
 */
function ipv6_to_arpa(string $address, ?int $prefix = null): string
{
    $bin = @inet_pton($address);
    if ($bin === false || strlen($bin) !== 16) {
        throw new InvalidArgumentException('Invalid IPv6 address');
    }

    $prefix = $prefix ?? 128;
    if ($prefix < 0 || $prefix > 128) {
        throw new InvalidArgumentException('Prefix length must be 0..128');
    }
    if ($prefix % 4 !== 0) {
        throw new InvalidArgumentException(
            'Prefix length must be a multiple of 4 for ip6.arpa zone delegation'
        );
    }

    $hex         = bin2hex($bin); // 32 nibbles, lowercase
    $nibbles     = (int) ($prefix / 4);
    $significant = substr($hex, 0, $nibbles);
    if ($significant === '') {
        return 'ip6.arpa';
    }
    $reversed = strrev($significant);
    $dotted   = implode('.', str_split($reversed));
    return $dotted . '.ip6.arpa';
}
