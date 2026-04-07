<?php
declare(strict_types=1);

// ─── Subnet splitter ──────────────────────────────────────────────────────────

function split_subnet(string $network_ip, int $cidr, int $new_prefix, int $max = 16): array {
    if ($new_prefix <= $cidr || $new_prefix > 32) {
        return ['subnets' => [], 'total' => 0, 'showing' => 0];
    }
    $count      = 1 << ($new_prefix - $cidr);
    $showing    = min($count, $max);
    $base       = ip2long($network_ip) & 0xFFFFFFFF;
    $block_size = 1 << (32 - $new_prefix);
    $subnets    = [];
    for ($i = 0; $i < $showing; $i++) {
        $subnets[] = long2ip(($base + $i * $block_size) & 0xFFFFFFFF) . '/' . $new_prefix;
    }
    return ['subnets' => $subnets, 'total' => $count, 'showing' => $showing];
}

function split_subnet6(string $network_ip, int $prefix, int $new_prefix, int $max = 16): array {
    if ($new_prefix <= $prefix || $new_prefix > 128) {
        return ['subnets' => [], 'total' => '0', 'showing' => 0];
    }
    $diff       = $new_prefix - $prefix;
    $total_str  = $diff >= 63 ? '2^' . $diff : (string)(1 << $diff);
    $showing    = $diff >= 63 ? $max : min(1 << $diff, $max);
    $base       = ipv6_to_gmp($network_ip);
    $block_size = gmp_pow(2, 128 - $new_prefix);
    $subnets    = [];
    for ($i = 0; $i < $showing; $i++) {
        $start     = gmp_add($base, gmp_mul($block_size, gmp_init($i)));
        $subnets[] = gmp_to_ipv6($start) . '/' . $new_prefix;
    }
    return ['subnets' => $subnets, 'total' => $total_str, 'showing' => $showing];
}
