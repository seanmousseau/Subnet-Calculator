<?php
declare(strict_types=1);

// ─── IPv6 ─────────────────────────────────────────────────────────────────────

function is_valid_ipv6(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
}

function ipv6_to_gmp(string $ip): \GMP {
    $bin = inet_pton($ip);
    if ($bin === false || strlen($bin) !== 16) {
        throw new \InvalidArgumentException('Invalid IPv6 address passed to ipv6_to_gmp.');
    }
    return gmp_init(bin2hex($bin), 16);
}

function gmp_to_ipv6(\GMP $n): string {
    $hex = str_pad(gmp_strval($n, 16), 32, '0', STR_PAD_LEFT);
    if (strlen($hex) > 32) {
        throw new \OverflowException('GMP value exceeds 128 bits.');
    }
    $result = inet_ntop(hex2bin($hex));
    if ($result === false) {
        throw new \RuntimeException('inet_ntop failed on computed IPv6 address.');
    }
    return $result;
}

function calculate_subnet6(string $ip, int $prefix): array {
    $ip_int    = ipv6_to_gmp($ip);
    $host_bits = 128 - $prefix;
    $host_mask = $host_bits > 0 ? gmp_sub(gmp_pow(2, $host_bits), 1) : gmp_init(0);
    $net_mask  = gmp_xor(gmp_sub(gmp_pow(2, 128), 1), $host_mask);
    $network   = gmp_and($ip_int, $net_mask);
    $last      = gmp_or($network, $host_mask);
    $total     = $host_bits === 0 ? '1' : '2^' . $host_bits;

    return [
        'network_cidr' => gmp_to_ipv6($network) . '/' . $prefix,
        'prefix'       => '/' . $prefix,
        'first_ip'     => gmp_to_ipv6($network),
        'last_ip'      => gmp_to_ipv6($last),
        'total'        => $total,
    ];
}
