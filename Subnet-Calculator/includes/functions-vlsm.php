<?php

declare(strict_types=1);

// ─── VLSM ─────────────────────────────────────────────────────────────────────

/**
 * Allocate subnets using VLSM (Variable Length Subnet Masking).
 *
 * Requirements: array of ['name' => string, 'hosts' => int] (any order).
 * Subnets are allocated largest-first within the parent network.
 *
 * @param  array<array{name: string, hosts: int}> $requirements
 * @return array{allocations?: list<array{name: string, hosts_needed: int, subnet: string, usable: int, waste: int}>, error?: string}
 */
function vlsm_allocate(string $network_ip, int $cidr, array $requirements): array
{
    if ($requirements === []) {
        return ['error' => 'No requirements provided.'];
    }

    // Sort largest-first (stable via index)
    $indexed = array_values($requirements);
    usort($indexed, fn($a, $b) => $b['hosts'] <=> $a['hosts']);

    $parent_long  = ip2long($network_ip) & 0xFFFFFFFF;
    $parent_bits  = 32 - $cidr;
    $parent_size  = $cidr < 32 ? (1 << $parent_bits) : 1;

    $current = $parent_long;
    $allocations = [];

    foreach ($indexed as $req) {
        $hosts_needed = (int)$req['hosts'];
        if ($hosts_needed < 1) {
            return ['error' => "Requirement \"{$req['name']}\" has invalid host count."];
        }

        // Find smallest prefix that fits (p < 31 needs hosts+2 addresses; /31 fits 2, /32 fits 1)
        $prefix = 32;
        for ($p = 30; $p >= $cidr; $p--) {
            $usable = (1 << (32 - $p)) - 2;
            if ($usable >= $hosts_needed) {
                $prefix = $p;
                break;
            }
        }
        if ($prefix === 32) {
            // /31 = point-to-point (2 addresses); /32 = loopback (1 address)
            if ($hosts_needed === 1) {
                $prefix = 32;
            } elseif ($hosts_needed <= 2) {
                $prefix = 31;
            } else {
                return ['error' => "Requirement \"{$req['name']}\" needs {$hosts_needed} hosts but no prefix fits within /{$cidr}."];
            }
        }

        $block_size = 1 << (32 - $prefix);

        // Align current pointer to block boundary
        if ($block_size > 1 && ($current % $block_size) !== 0) {
            $current = (int)(ceil($current / $block_size) * $block_size);
        }

        // Check fits within parent
        if (($current - $parent_long) + $block_size > $parent_size) {
            return ['error' => "Not enough space in /{$cidr} for \"{$req['name']}\" (/{$prefix})."];
        }

        $usable = $prefix >= 31 ? $block_size : $block_size - 2;
        $allocations[] = [
            'name'        => $req['name'],
            'hosts_needed' => $hosts_needed,
            'subnet'      => long2ip($current) . '/' . $prefix,
            'usable'      => $usable,
            'waste'       => $usable - $hosts_needed,
        ];

        $current += $block_size;
    }

    return ['allocations' => $allocations];
}
