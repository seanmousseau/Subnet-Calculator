<?php

declare(strict_types=1);

// ─── IPv6 VLSM ────────────────────────────────────────────────────────────────

/**
 * Convert a host count input (int, numeric string, or "2^N" string) to a GMP value.
 * Returns null when the input is not a positive integer / 2^N expression.
 */
function vlsm6_parse_hosts(int|string $hosts): ?\GMP
{
    if (is_int($hosts)) {
        if ($hosts < 1) {
            return null;
        }
        return gmp_init((string)$hosts);
    }
    $h = trim($hosts);
    if ($h === '') {
        return null;
    }
    if (preg_match('/^2\^(\d{1,3})$/', $h, $m) === 1) {
        $exp = (int)$m[1];
        if ($exp < 0 || $exp > 128) {
            return null;
        }
        return gmp_pow(gmp_init(2), $exp);
    }
    if (preg_match('/^\d+$/', $h) === 1) {
        $g = gmp_init($h);
        if (gmp_cmp($g, gmp_init(0)) <= 0) {
            return null;
        }
        return $g;
    }
    return null;
}

/**
 * Format a GMP host/usable count as a small int when < 2^31, else as the string "2^N"
 * (using the next power of two ceiling). When the count is exactly a power of two and
 * >= 2^31, the exact "2^N" string is returned.
 */
function vlsm6_format_count(\GMP $size): int|string
{
    $threshold = gmp_pow(gmp_init(2), 31);
    if (gmp_cmp($size, $threshold) < 0) {
        return (int)gmp_strval($size);
    }
    // Find smallest power-of-two >= size
    for ($n = 31; $n <= 128; $n++) {
        if (gmp_cmp(gmp_pow(gmp_init(2), $n), $size) >= 0) {
            return '2^' . $n;
        }
    }
    return '2^128';
}

/**
 * Allocate IPv6 subnets using VLSM (Variable Length Subnet Masking).
 *
 * Requirements: list of ['name' => string, 'hosts' => int|string]. Hosts may be a
 * positive integer, decimal string, or "2^N" string for very large IPv6 sizings.
 *
 * Subnets are allocated largest-first within the parent network. Every IPv6 address
 * is usable (no broadcast / network reservation).
 *
 * @param  array<array{name: string, hosts: int|string}> $requirements
 * @return array{
 *   allocations?: list<array{name: string, hosts_needed: int|string, subnet: string, usable: int|string}>,
 *   error?: string
 * }
 */
function vlsm6_allocate(string $network_ip, int $cidr, array $requirements): array
{
    if ($requirements === []) {
        return ['error' => 'No requirements provided.'];
    }
    if ($cidr < 0 || $cidr > 128) {
        return ['error' => 'CIDR must be between /0 and /128.'];
    }

    // Parse + validate hosts up front; remember parsed GMP for sorting/sizing.
    $indexed = [];
    foreach ($requirements as $req) {
        $name      = $req['name'];
        $hosts     = $req['hosts'];
        $hosts_gmp = vlsm6_parse_hosts($hosts);
        if ($hosts_gmp === null) {
            return ['error' => "Requirement \"{$name}\" has invalid host count."];
        }
        $indexed[] = [
            'name'      => $name,
            'hosts_raw' => $hosts,
            'hosts_gmp' => $hosts_gmp,
        ];
    }

    // Sort largest-first using GMP comparison.
    usort($indexed, static fn(array $a, array $b): int => gmp_cmp($b['hosts_gmp'], $a['hosts_gmp']));

    $parent_start = ipv6_to_gmp($network_ip);
    $parent_size  = gmp_pow(gmp_init(2), 128 - $cidr);
    $current      = $parent_start;
    $allocations  = [];

    foreach ($indexed as $req) {
        $hosts_gmp = $req['hosts_gmp'];

        // Find smallest prefix /p (cidr <= p <= 128) such that 2^(128 - p) >= hosts.
        $prefix = -1;
        for ($p = 128; $p >= $cidr; $p--) {
            $block = gmp_pow(gmp_init(2), 128 - $p);
            if (gmp_cmp($block, $hosts_gmp) >= 0) {
                $prefix = $p;
                break;
            }
        }
        if ($prefix === -1) {
            return ['error' => "Requirement \"{$req['name']}\" does not fit within /{$cidr}."];
        }

        $block_size = gmp_pow(gmp_init(2), 128 - $prefix);

        // Align current pointer up to block boundary.
        $offset = gmp_mod(gmp_sub($current, $parent_start), $block_size);
        if (gmp_cmp($offset, gmp_init(0)) !== 0) {
            $current = gmp_add($current, gmp_sub($block_size, $offset));
        }

        // Check fits within parent.
        $used_after = gmp_add(gmp_sub($current, $parent_start), $block_size);
        if (gmp_cmp($used_after, $parent_size) > 0) {
            return ['error' => "Not enough space in /{$cidr} for \"{$req['name']}\" (/{$prefix})."];
        }

        $subnet_addr = gmp_to_ipv6($current);
        $allocations[] = [
            'name'         => $req['name'],
            'hosts_needed' => $req['hosts_raw'],
            'subnet'       => $subnet_addr . '/' . $prefix,
            'usable'       => vlsm6_format_count($block_size),
        ];

        $current = gmp_add($current, $block_size);
    }

    return ['allocations' => $allocations];
}
