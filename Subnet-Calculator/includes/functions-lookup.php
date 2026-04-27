<?php

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects -- explicit dependency on ipv6_to_gmp().
require_once __DIR__ . '/functions-ipv6.php';
// phpcs:enable PSR1.Files.SideEffects

// ─── Inverse subnet lookup ────────────────────────────────────────────────────

/**
 * For each IP, find every CIDR (from $cidrs) that contains it. Returns one
 * result row per IP (preserving input order) with the full match list and the
 * deepest (longest-prefix) match.
 *
 * Mixed IPv4/IPv6 inputs are allowed; a CIDR only ever matches an IP of the
 * same family.
 *
 * @param list<string> $cidrs Network CIDRs (IPv4 or IPv6).
 * @param list<string> $ips   IP addresses (IPv4 or IPv6).
 * @return list<array{ip: string, matches: list<string>, deepest: string|null}>
 *
 * @throws InvalidArgumentException If any CIDR or IP is malformed / out of range.
 */
function lookup_ips(array $cidrs, array $ips): array
{
    // Pre-validate and pre-parse every CIDR once. A bad entry throws — we never
    // want to silently skip user input.
    /** @var list<array{cidr: string, family: 'v4'|'v6', net: int|\GMP, prefix: int}> $parsed */
    $parsed = [];
    foreach ($cidrs as $cidr) {
        $parsed[] = lookup_parse_cidr($cidr);
    }

    $results = [];
    foreach ($ips as $ip) {
        if ($ip === '') {
            throw new \InvalidArgumentException('IP address must be a non-empty string.');
        }
        $family = lookup_ip_family($ip);
        if ($family === null) {
            throw new \InvalidArgumentException('Invalid IP address: ' . $ip);
        }

        $matches = [];
        $deepest_prefix = -1;
        $deepest_cidr = null;

        if ($family === 'v4') {
            $ip_long = ip2long($ip);
            // ip2long returned non-false because lookup_ip_family said v4.
            $ip_long = (int)$ip_long & 0xFFFFFFFF;
            foreach ($parsed as $row) {
                if ($row['family'] !== 'v4') {
                    continue;
                }
                /** @var int $net */
                $net = $row['net'];
                $prefix = $row['prefix'];
                $mask = $prefix === 0 ? 0 : ((0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF);
                if (($ip_long & $mask) === ($net & $mask)) {
                    $matches[] = $row['cidr'];
                    if ($prefix > $deepest_prefix) {
                        $deepest_prefix = $prefix;
                        $deepest_cidr = $row['cidr'];
                    }
                }
            }
        } else {
            $ip_gmp = ipv6_to_gmp($ip);
            $all_ones = gmp_sub(gmp_pow(gmp_init(2), 128), gmp_init(1));
            foreach ($parsed as $row) {
                if ($row['family'] !== 'v6') {
                    continue;
                }
                /** @var \GMP $net */
                $net = $row['net'];
                $prefix = $row['prefix'];
                $host_bits = 128 - $prefix;
                $host_mask = $host_bits > 0
                    ? gmp_sub(gmp_pow(gmp_init(2), $host_bits), gmp_init(1))
                    : gmp_init(0);
                $net_mask = gmp_xor($all_ones, $host_mask);
                if (gmp_cmp(gmp_and($ip_gmp, $net_mask), gmp_and($net, $net_mask)) === 0) {
                    $matches[] = $row['cidr'];
                    if ($prefix > $deepest_prefix) {
                        $deepest_prefix = $prefix;
                        $deepest_cidr = $row['cidr'];
                    }
                }
            }
        }

        $results[] = [
            'ip'      => $ip,
            'matches' => $matches,
            'deepest' => $deepest_cidr,
        ];
    }

    return $results;
}

/**
 * Detect IP family from a plain address (no prefix). Returns 'v4', 'v6', or null.
 */
function lookup_ip_family(string $ip): ?string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        return 'v4';
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        return 'v6';
    }
    return null;
}

/**
 * Parse a CIDR string into its family, network address (as int for v4 / GMP for
 * v6), and prefix. Throws on any validation error.
 *
 * @return array{cidr: string, family: 'v4'|'v6', net: int|\GMP, prefix: int}
 */
function lookup_parse_cidr(string $cidr): array
{
    if ($cidr === '' || strpos($cidr, '/') === false) {
        throw new \InvalidArgumentException('Invalid CIDR (missing prefix): ' . $cidr);
    }
    $parts = explode('/', $cidr, 2);
    $ip = $parts[0];
    $prefix_str = $parts[1];
    if ($prefix_str === '' || !ctype_digit($prefix_str)) {
        throw new \InvalidArgumentException('Invalid CIDR prefix: ' . $cidr);
    }
    $prefix = (int)$prefix_str;
    $family = lookup_ip_family($ip);
    if ($family === null) {
        throw new \InvalidArgumentException('Invalid CIDR address: ' . $cidr);
    }
    if ($family === 'v4') {
        if ($prefix < 0 || $prefix > 32) {
            throw new \InvalidArgumentException('IPv4 CIDR prefix out of range (0–32): ' . $cidr);
        }
        $ip_long = ip2long($ip);
        // ip2long can't fail here because filter_var passed.
        $ip_long = (int)$ip_long & 0xFFFFFFFF;
        $mask = $prefix === 0 ? 0 : ((0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF);
        $net = $ip_long & $mask;
        return [
            'cidr'   => $cidr,
            'family' => 'v4',
            'net'    => $net,
            'prefix' => $prefix,
        ];
    }
    if ($prefix < 0 || $prefix > 128) {
        throw new \InvalidArgumentException('IPv6 CIDR prefix out of range (0–128): ' . $cidr);
    }
    $ip_gmp = ipv6_to_gmp($ip);
    $all_ones  = gmp_sub(gmp_pow(gmp_init(2), 128), gmp_init(1));
    $host_bits = 128 - $prefix;
    $host_mask = $host_bits > 0
        ? gmp_sub(gmp_pow(gmp_init(2), $host_bits), gmp_init(1))
        : gmp_init(0);
    $net_mask = gmp_xor($all_ones, $host_mask);
    $net = gmp_and($ip_gmp, $net_mask);
    return [
        'cidr'   => $cidr,
        'family' => 'v6',
        'net'    => $net,
        'prefix' => $prefix,
    ];
}
