<?php

declare(strict_types=1);

// ─── Subnet aggregation diff ──────────────────────────────────────────────────

/**
 * Compute a structural diff between two CIDR lists.
 *
 * Each input CIDR is normalised to canonical form before comparison:
 *   - host bits zeroed (10.0.0.5/24 → 10.0.0.0/24)
 *   - IPv6 lowercased and compressed (2001:DB8::/32 → 2001:db8::/32)
 *
 * The returned structure has four keys:
 *   - unchanged: list<string> — exact intersection (canonical form)
 *   - removed:   list<string> — only in $before (sorted, v4 first)
 *   - added:     list<string> — only in $after  (sorted, v4 first)
 *   - changed:   list<array{from: string, to: string, reason: string}>
 *                — pairs where the network address matches but the prefix
 *                length differs (e.g. /24 → /23). When a network has the
 *                same address but different prefixes in both lists, it is
 *                reported in `changed` instead of `added`/`removed`.
 *
 * @param list<string> $before
 * @param list<string> $after
 * @return array{
 *   added: list<string>,
 *   removed: list<string>,
 *   unchanged: list<string>,
 *   changed: list<array{from: string, to: string, reason: string}>
 * }
 *
 * @throws InvalidArgumentException If any CIDR is malformed / out of range.
 */
function subnet_diff(array $before, array $after): array
{
    $b = [];
    foreach ($before as $cidr) {
        if (!is_string($cidr) || trim($cidr) === '') {
            throw new \InvalidArgumentException('CIDR must be a non-empty string.');
        }
        $b[] = sc_canonicalise_cidr(trim($cidr));
    }
    $a = [];
    foreach ($after as $cidr) {
        if (!is_string($cidr) || trim($cidr) === '') {
            throw new \InvalidArgumentException('CIDR must be a non-empty string.');
        }
        $a[] = sc_canonicalise_cidr(trim($cidr));
    }

    // Dedupe per family while preserving first-seen order.
    $b_set = array_values(array_unique($b));
    $a_set = array_values(array_unique($a));

    // Index by network address (everything left of the slash) for the "changed"
    // detection: same network, different prefix length.
    $b_by_net = [];
    foreach ($b_set as $cidr) {
        [$net, $pfx] = explode('/', $cidr, 2);
        $b_by_net[$net] = ['cidr' => $cidr, 'prefix' => (int)$pfx];
    }
    $a_by_net = [];
    foreach ($a_set as $cidr) {
        [$net, $pfx] = explode('/', $cidr, 2);
        $a_by_net[$net] = ['cidr' => $cidr, 'prefix' => (int)$pfx];
    }

    $unchanged = [];
    $changed   = [];
    $added     = [];
    $removed   = [];

    // First: walk before-side networks.
    foreach ($b_by_net as $net => $row) {
        if (isset($a_by_net[$net])) {
            $a_row = $a_by_net[$net];
            if ($a_row['prefix'] === $row['prefix']) {
                $unchanged[] = $row['cidr'];
            } else {
                $changed[] = [
                    'from'   => $row['cidr'],
                    'to'     => $a_row['cidr'],
                    'reason' => sprintf(
                        'prefix changed /%d → /%d',
                        $row['prefix'],
                        $a_row['prefix']
                    ),
                ];
            }
        } else {
            $removed[] = $row['cidr'];
        }
    }

    // Second: walk after-side networks for pure additions.
    foreach ($a_by_net as $net => $row) {
        if (!isset($b_by_net[$net])) {
            $added[] = $row['cidr'];
        }
    }

    // Sort added/removed: v4 first, then v6, each lexically sensible.
    $added   = sc_sort_cidrs($added);
    $removed = sc_sort_cidrs($removed);

    return [
        'added'     => $added,
        'removed'   => $removed,
        'unchanged' => $unchanged,
        'changed'   => $changed,
    ];
}

/**
 * Canonicalise a CIDR string.
 *
 *   - Validates address family + prefix bounds.
 *   - Zeroes any host bits.
 *   - Returns IPv4 in dotted-quad form, IPv6 in compressed lowercase form.
 *
 * @throws InvalidArgumentException
 */
function sc_canonicalise_cidr(string $cidr): string
{
    if ($cidr === '' || strpos($cidr, '/') === false) {
        throw new \InvalidArgumentException('Invalid CIDR (missing prefix): ' . $cidr);
    }
    $parts = explode('/', $cidr, 2);
    $ip = $parts[0];
    $pfx_str = $parts[1];
    if ($pfx_str === '' || !ctype_digit($pfx_str)) {
        throw new \InvalidArgumentException('Invalid CIDR prefix: ' . $cidr);
    }
    $prefix = (int)$pfx_str;

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        if ($prefix < 0 || $prefix > 32) {
            throw new \InvalidArgumentException('IPv4 CIDR prefix out of range (0–32): ' . $cidr);
        }
        $long = ip2long($ip);
        if ($long === false) {
            throw new \InvalidArgumentException('Invalid IPv4 address: ' . $cidr);
        }
        $long = (int)$long & 0xFFFFFFFF;
        $mask = $prefix === 0 ? 0 : ((0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF);
        $net  = $long & $mask;
        $net_str = long2ip($net);
        if ($net_str === false) {
            throw new \InvalidArgumentException('long2ip failed for: ' . $cidr);
        }
        return $net_str . '/' . $prefix;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        if ($prefix < 0 || $prefix > 128) {
            throw new \InvalidArgumentException('IPv6 CIDR prefix out of range (0–128): ' . $cidr);
        }
        $gmp       = ipv6_to_gmp($ip);
        $all_ones  = gmp_sub(gmp_pow(gmp_init(2), 128), gmp_init(1));
        $host_bits = 128 - $prefix;
        $host_mask = $host_bits > 0
            ? gmp_sub(gmp_pow(gmp_init(2), $host_bits), gmp_init(1))
            : gmp_init(0);
        $net_mask  = gmp_xor($all_ones, $host_mask);
        $net_gmp   = gmp_and($gmp, $net_mask);
        return gmp_to_ipv6($net_gmp) . '/' . $prefix;
    }

    throw new \InvalidArgumentException('Invalid CIDR address: ' . $cidr);
}

/**
 * Sort canonical CIDR strings: IPv4 group first (sorted by numeric address
 * then prefix), IPv6 group second (sorted by canonical hex form, then prefix).
 *
 * @param list<string> $cidrs
 * @return list<string>
 */
function sc_sort_cidrs(array $cidrs): array
{
    $v4 = [];
    $v6 = [];
    foreach ($cidrs as $cidr) {
        [$ip] = explode('/', $cidr, 2);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $v4[] = $cidr;
        } else {
            $v6[] = $cidr;
        }
    }
    usort($v4, static function (string $a, string $b): int {
        [$ipA, $pA] = explode('/', $a, 2);
        [$ipB, $pB] = explode('/', $b, 2);
        $longA = (int)ip2long($ipA) & 0xFFFFFFFF;
        $longB = (int)ip2long($ipB) & 0xFFFFFFFF;
        if ($longA === $longB) {
            return (int)$pA <=> (int)$pB;
        }
        return $longA <=> $longB;
    });
    usort($v6, static function (string $a, string $b): int {
        [$ipA, $pA] = explode('/', $a, 2);
        [$ipB, $pB] = explode('/', $b, 2);
        $cmp = gmp_cmp(ipv6_to_gmp($ipA), ipv6_to_gmp($ipB));
        if ($cmp === 0) {
            return (int)$pA <=> (int)$pB;
        }
        return $cmp < 0 ? -1 : 1;
    });
    return array_values(array_merge($v4, $v6));
}
