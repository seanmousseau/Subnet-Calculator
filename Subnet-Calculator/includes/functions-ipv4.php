<?php
declare(strict_types=1);

// ─── IPv4 ─────────────────────────────────────────────────────────────────────

function cidr_to_mask(int $cidr): string {
    $mask = $cidr === 0 ? 0 : (~0 << (32 - $cidr));
    return long2ip($mask);
}

function mask_to_cidr(string $mask): int {
    return strlen(str_replace('0', '', decbin(ip2long($mask))));
}

function is_valid_ipv4(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

function is_valid_mask_octet(string $mask): bool {
    if (!is_valid_ipv4($mask)) return false;
    $long     = ip2long($mask);
    $inverted = ~$long & 0xFFFFFFFF;
    return ($inverted & ($inverted + 1)) === 0;
}

function cidr_to_wildcard(int $cidr): string {
    $mask_long = $cidr === 0 ? 0 : (~0 << (32 - $cidr));
    return long2ip(~$mask_long & 0xFFFFFFFF);
}

function calculate_subnet(string $ip, int $cidr): array {
    $ip_long      = ip2long($ip);
    $mask_long    = $cidr === 0 ? 0 : (~0 << (32 - $cidr));
    $network_long = $ip_long & $mask_long;
    $broadcast    = $network_long | (~$mask_long & 0xFFFFFFFF);
    $first        = $cidr >= 31 ? $network_long : $network_long + 1;
    $last         = $cidr >= 31 ? $broadcast    : $broadcast - 1;
    $usable       = $cidr >= 31 ? (1 << (32 - $cidr)) : max(0, (1 << (32 - $cidr)) - 2);

    return [
        'network_cidr'  => long2ip($network_long) . '/' . $cidr,
        'netmask_cidr'  => '/' . $cidr,
        'netmask_octet' => cidr_to_mask($cidr),
        'wildcard'      => cidr_to_wildcard($cidr),
        'first_usable'  => long2ip($first),
        'last_usable'   => long2ip($last),
        'broadcast'     => long2ip($broadcast),
        'usable_hosts'  => $usable,
    ];
}
