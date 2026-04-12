<?php

declare(strict_types=1);

// ─── IPv4 ─────────────────────────────────────────────────────────────────────

function cidr_to_mask(int $cidr): string
{
    $mask = $cidr === 0 ? 0 : (~0 << (32 - $cidr));
    return long2ip($mask);
}

function mask_to_cidr(string $mask): int
{
    return strlen(str_replace('0', '', decbin(ip2long($mask))));
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
    $long     = ip2long($mask);
    $inverted = ~$long & 0xFFFFFFFF;
    return ($inverted & ($inverted + 1)) === 0;
}

function cidr_to_wildcard(int $cidr): string
{
    $mask_long = $cidr === 0 ? 0 : (~0 << (32 - $cidr));
    return long2ip(~$mask_long & 0xFFFFFFFF);
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
