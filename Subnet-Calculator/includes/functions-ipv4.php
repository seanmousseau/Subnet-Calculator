<?php

declare(strict_types=1);

// ─── IPv4 ─────────────────────────────────────────────────────────────────────

function cidr_to_mask(int $cidr): string
{
    $mask = $cidr === 0 ? 0 : (~0 << (32 - $cidr));
    $ip   = long2ip($mask);
    return $ip !== false ? $ip : '0.0.0.0';
}

function mask_to_cidr(string $mask): int
{
    $long = ip2long($mask);
    return strlen(str_replace('0', '', decbin($long !== false ? $long : 0)));
}

function is_valid_ipv4(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

function is_valid_mask_octet(string $mask): bool
{
    if (!is_valid_ipv4($mask)) {
        return false;
    }
    $long = ip2long($mask);
    if ($long === false) {
        return false;
    }
    $inverted = ~$long & 0xFFFFFFFF;
    return ($inverted & ($inverted + 1)) === 0;
}

/**
 * Convert a CIDR prefix to its dotted-quad wildcard mask.
 *
 * Accepts either an int (0-32) or a user-supplied string ("/24" or "24").
 * String input is normalised, validated, and rejected via
 * InvalidArgumentException when out-of-range or non-numeric. Internal callers
 * that already hold an int continue to work unchanged.
 *
 * @param int|string $cidr
 */
function cidr_to_wildcard(int|string $cidr): string
{
    if (is_string($cidr)) {
        $trim = ltrim(trim($cidr), '/');
        if (!ctype_digit($trim)) {
            throw new InvalidArgumentException('CIDR prefix must be numeric');
        }
        $cidr = (int) $trim;
    }
    if ($cidr < 0 || $cidr > 32) {
        throw new InvalidArgumentException('CIDR prefix must be between 0 and 32');
    }
    $mask_long = $cidr === 0 ? 0 : (~0 << (32 - $cidr));
    $ip        = long2ip(~$mask_long & 0xFFFFFFFF);
    return $ip !== false ? $ip : '0.0.0.0';
}

/**
 * Convert a Cisco-style wildcard mask to its CIDR prefix (e.g. "0.0.0.255" → "/24").
 *
 * Only contiguous wildcard masks (the bitwise inverse of a valid netmask) are
 * accepted; non-contiguous masks raise InvalidArgumentException.
 */
function wildcard_to_cidr(string $wildcard): string
{
    $wildcard = trim($wildcard);
    if (filter_var($wildcard, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        throw new InvalidArgumentException('Wildcard must be a valid IPv4 dotted-quad');
    }
    $w_long = ip2long($wildcard);
    if ($w_long === false) {
        throw new InvalidArgumentException('Wildcard must be a valid IPv4 dotted-quad');
    }
    $w       = $w_long & 0xFFFFFFFF;
    $netmask = (~$w) & 0xFFFFFFFF;
    // Contiguity: the inverted-mask (host bits) must be (2^n)-1 — i.e. all
    // host bits live on the right. ((inv)+1) AND inv == 0 ⇒ inv is power-of-two-minus-one.
    $inv = (~$netmask) & 0xFFFFFFFF;
    if ((($inv + 1) & $inv) !== 0) {
        throw new InvalidArgumentException('Wildcard mask is not contiguous');
    }
    $prefix = 0;
    for ($i = 31; $i >= 0; $i--) {
        if (($netmask >> $i) & 1) {
            $prefix++;
        } else {
            break;
        }
    }
    return '/' . $prefix;
}

function cidrs_overlap(string $cidr_a, string $cidr_b): string
{
    [$ip_a, $px_a] = explode('/', $cidr_a);
    [$ip_b, $px_b] = explode('/', $cidr_b);
    $px_a  = (int)$px_a;
    $px_b  = (int)$px_b;
    $net_a = ip2long($ip_a) & 0xFFFFFFFF;
    $net_b = ip2long($ip_b) & 0xFFFFFFFF;
    $test_px = max($px_a, $px_b);
    $mask    = $test_px === 0 ? 0 : ((~0 << (32 - $test_px)) & 0xFFFFFFFF);
    if (($net_a & $mask) !== ($net_b & $mask)) {
        return 'none';
    }
    if ($px_a === $px_b) {
        return 'identical';
    }
    return $px_a < $px_b ? 'a_contains_b' : 'b_contains_a';
}

function ipv4_ptr_zone(string $network_cidr): string
{
    [$ip, $cidr] = explode('/', $network_cidr);
    $cidr = (int)$cidr;
    $o = explode('.', $ip);
    if ($cidr <= 8) {
        return "{$o[0]}.in-addr.arpa";
    }
    if ($cidr <= 16) {
        return "{$o[1]}.{$o[0]}.in-addr.arpa";
    }
    if ($cidr <= 24) {
        return "{$o[2]}.{$o[1]}.{$o[0]}.in-addr.arpa";
    }
    return "{$o[3]}/{$cidr}.{$o[2]}.{$o[1]}.{$o[0]}.in-addr.arpa"; // RFC 2317
}

/** @return array<string, mixed> */
function calculate_subnet(string $ip, int $cidr): array
{
    $ip_long      = ip2long($ip);
    $mask_long    = $cidr === 0 ? 0 : (~0 << (32 - $cidr));
    $network_long = $ip_long & $mask_long;
    $broadcast    = $network_long | (~$mask_long & 0xFFFFFFFF);
    $first        = $cidr >= 31 ? $network_long : $network_long + 1;
    $last         = $cidr >= 31 ? $broadcast    : $broadcast - 1;
    $usable       = $cidr >= 31 ? (1 << (32 - $cidr)) : max(0, (1 << (32 - $cidr)) - 2);

    // Unsigned 32-bit representation of the network address (PHP int is 64-bit signed,
    // so & 0xFFFFFFFF always yields a non-negative value on 64-bit platforms).
    $net_u32 = $network_long & 0xFFFFFFFF;

    $network_cidr = long2ip($network_long) . '/' . $cidr;
    return [
        'network_cidr'    => $network_cidr,
        'netmask_cidr'    => '/' . $cidr,
        'netmask_octet'   => cidr_to_mask($cidr),
        'wildcard'        => cidr_to_wildcard($cidr),
        'first_usable'    => long2ip($first),
        'last_usable'     => long2ip($last),
        'broadcast'       => long2ip($broadcast),
        'usable_hosts'    => $usable,
        'ptr_zone'        => ipv4_ptr_zone($network_cidr),
        'network_hex'     => strtoupper(sprintf(
            '%02X.%02X.%02X.%02X',
            ($net_u32 >> 24) & 0xFF,
            ($net_u32 >> 16) & 0xFF,
            ($net_u32 >>  8) & 0xFF,
            $net_u32        & 0xFF
        )),
        'network_decimal' => $net_u32,
    ];
}
