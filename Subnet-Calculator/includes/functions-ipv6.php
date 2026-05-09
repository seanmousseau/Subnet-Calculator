<?php

declare(strict_types=1);

// ─── IPv6 ─────────────────────────────────────────────────────────────────────

function is_valid_ipv6(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
}

function ipv6_to_gmp(string $ip): \GMP
{
    $bin = inet_pton($ip);
    if ($bin === false || strlen($bin) !== 16) {
        throw new \InvalidArgumentException('Invalid IPv6 address passed to ipv6_to_gmp.');
    }
    return gmp_init(bin2hex($bin), 16);
}

function gmp_to_ipv6(\GMP $n): string
{
    $hex = str_pad(gmp_strval($n, 16), 32, '0', STR_PAD_LEFT);
    if (strlen($hex) > 32) {
        throw new \OverflowException('GMP value exceeds 128 bits.');
    }
    $bin = hex2bin($hex);
    if ($bin === false) {
        throw new \RuntimeException('hex2bin failed on computed IPv6 hex string.');
    }
    $result = inet_ntop($bin);
    if ($result === false) {
        throw new \RuntimeException('inet_ntop failed on computed IPv6 address.');
    }
    return $result;
}

/**
 * Check overlap between two pre-normalised IPv6 CIDRs (network address + prefix).
 * Callers must pass network addresses produced by calculate_subnet6() — not raw user input.
 * Returns: 'none', 'identical', 'a_contains_b', or 'b_contains_a'.
 */
function cidrs_overlap6(string $cidr_a, string $cidr_b): string
{
    [$ip_a, $px_a] = explode('/', $cidr_a);
    [$ip_b, $px_b] = explode('/', $cidr_b);
    $px_a    = (int)$px_a;
    $px_b    = (int)$px_b;
    $net_a   = ipv6_to_gmp($ip_a);
    $net_b   = ipv6_to_gmp($ip_b);
    $test_px = min($px_a, $px_b);
    $host_bits = 128 - $test_px;
    $all_ones  = gmp_sub(gmp_pow(gmp_init(2), 128), gmp_init(1));
    $host_mask = $host_bits > 0 ? gmp_sub(gmp_pow(gmp_init(2), $host_bits), gmp_init(1)) : gmp_init(0);
    $net_mask  = gmp_xor($all_ones, $host_mask);
    if (gmp_cmp(gmp_and($net_a, $net_mask), gmp_and($net_b, $net_mask)) !== 0) {
        return 'none';
    }
    if ($px_a === $px_b) {
        return 'identical';
    }
    return $px_a < $px_b ? 'a_contains_b' : 'b_contains_a';
}

function ipv6_ptr_zone(string $network_cidr): string
{
    [$ip, $prefix] = explode('/', $network_cidr);
    $prefix = (int)$prefix;
    $pton = inet_pton($ip);
    if ($pton === false || strlen($pton) !== 16) {
        throw new \InvalidArgumentException('Invalid IPv6 address passed to ipv6_ptr_zone: ' . $ip);
    }
    $hex = bin2hex($pton);                              // 32 lowercase hex chars
    $nibble_count = (int)floor($prefix / 4);             // significant nibbles
    $significant  = array_slice(str_split($hex), 0, $nibble_count);
    if ($significant === []) {
        return 'ip6.arpa';
    }
    return implode('.', array_reverse($significant)) . '.ip6.arpa';
}

/**
 * @return array{
 *   network_cidr: string,
 *   prefix: string,
 *   first_ip: string,
 *   last_ip: string,
 *   total: string,
 *   ptr_zone: string,
 *   address_expanded: string,
 *   address_compressed: string
 * }
 */
function calculate_subnet6(string $ip, int $prefix): array
{
    $ip_int    = ipv6_to_gmp($ip);
    $host_bits = 128 - $prefix;
    $host_mask = $host_bits > 0 ? gmp_sub(gmp_pow(2, $host_bits), 1) : gmp_init(0);
    $net_mask  = gmp_xor(gmp_sub(gmp_pow(2, 128), 1), $host_mask);
    $network   = gmp_and($ip_int, $net_mask);
    $last      = gmp_or($network, $host_mask);
    $total     = $host_bits === 0 ? '1'
        : ($host_bits <= 20 ? (string)(1 << $host_bits) : '2^' . $host_bits);
    $network_cidr = gmp_to_ipv6($network) . '/' . $prefix;

    // Expanded form: 8 groups of 4 hex digits (e.g. 2001:0db8:0000:…)
    $net_hex32        = str_pad(gmp_strval($network, 16), 32, '0', STR_PAD_LEFT);
    $address_expanded = implode(':', str_split($net_hex32, 4));
    // Compressed form: PHP's inet_ntop normalisation (e.g. 2001:db8::)
    $address_compressed = gmp_to_ipv6($network);

    return [
        'network_cidr'       => $network_cidr,
        'prefix'             => '/' . $prefix,
        'first_ip'           => gmp_to_ipv6($network),
        'last_ip'            => gmp_to_ipv6($last),
        'total'              => $total,
        'ptr_zone'           => ipv6_ptr_zone($network_cidr),
        'address_expanded'   => $address_expanded,
        'address_compressed' => $address_compressed,
    ];
}

// Migrated from functions-embedded-v4.php in v3.6.0 T2.
/**
 * Return true if $address parses as a valid IPv6 literal whose top
 * $prefix_length bits match the $prefix network. GMP throughout for
 * arbitrary prefix lengths.
 *
 * Returns false (no throw) on parse failure or prefix length out of
 * the 0..128 range — the caller should validate inputs first.
 */
function ipv6_in_prefix(string $address, string $prefix, int $prefix_length): bool
{
    if ($prefix_length < 0 || $prefix_length > 128) {
        return false;
    }
    $abin = @inet_pton($address);
    $pbin = @inet_pton($prefix);
    if ($abin === false || $pbin === false || strlen($abin) !== 16 || strlen($pbin) !== 16) {
        return false;
    }
    if ($prefix_length === 0) {
        return true;
    }
    $a = gmp_import($abin);
    $p = gmp_import($pbin);
    $shift = 128 - $prefix_length;
    // Build mask = ((1 << 128) - 1) ^ ((1 << shift) - 1)
    $all  = gmp_sub(gmp_pow(2, 128), 1);
    $low  = $shift === 0 ? gmp_init(0) : gmp_sub(gmp_pow(2, $shift), 1);
    $mask = gmp_xor($all, $low);
    return gmp_cmp(gmp_and($a, $mask), gmp_and($p, $mask)) === 0;
}
