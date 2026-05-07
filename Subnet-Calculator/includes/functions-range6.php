<?php

declare(strict_types=1);

/**
 * IPv6 range → CIDR conversion (v3.3.0).
 *
 * Pure-GMP implementation of the greedy largest-aligned-block algorithm,
 * mirroring the IPv4 helper in functions-range.php. Inputs are validated as
 * IPv6 (filter_var/inet_pton) — IPv4 addresses are rejected explicitly.
 *
 * Output cap: the global $range_max_cidrs (default 256) caps the number of
 * CIDR blocks emitted. When the cap is reached the result is returned with
 * truncated=true and count==cap, mirroring the v4 backport in
 * functions-range.php.
 */

/**
 * Convert an inclusive IPv6 address range to the minimal list of covering CIDR blocks.
 *
 * @return array{
 *     cidrs?: list<string>,
 *     count?: int,
 *     total_addresses?: int|string,
 *     truncated?: bool,
 *     cap?: int,
 *     error?: string,
 * }
 */
function range6_to_cidrs(string $start, string $end): array
{
    $start = trim($start);
    $end   = trim($end);

    if ($start === '') {
        return ['error' => 'Start IPv6 address is required.'];
    }
    if ($end === '') {
        return ['error' => 'End IPv6 address is required.'];
    }

    if (!filter_var($start, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        // Reject IPv4 explicitly with a clearer message.
        if (filter_var($start, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['error' => 'Start must be an IPv6 address (got IPv4: ' . $start . ').'];
        }
        return ['error' => 'Invalid start IPv6 address: ' . $start];
    }
    if (!filter_var($end, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        if (filter_var($end, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['error' => 'End must be an IPv6 address (got IPv4: ' . $end . ').'];
        }
        return ['error' => 'Invalid end IPv6 address: ' . $end];
    }

    $start_n = ipv6_to_gmp($start);
    $end_n   = ipv6_to_gmp($end);

    if (gmp_cmp($start_n, $end_n) > 0) {
        return ['error' => 'Start must be less than or equal to end.'];
    }

    // Output cap (global, configurable via $range_max_cidrs).
    global $range_max_cidrs;
    $cap = isset($range_max_cidrs) ? max(1, min((int)$range_max_cidrs, 100000)) : 256;

    // Total address count (inclusive). Uses GMP throughout; if the value
    // overflows PHP int, we return it as a "2^N" string consistent with
    // calculate_subnet6() / vlsm6_allocate().
    $total_gmp = gmp_add(gmp_sub($end_n, $start_n), gmp_init(1));
    $total_addresses = _range6_format_count($total_gmp);

    $cidrs = [];
    $cur   = $start_n;
    $truncated = false;

    while (gmp_cmp($cur, $end_n) <= 0) {
        if (count($cidrs) >= $cap) {
            $truncated = true;
            break;
        }

        // Find the largest k such that:
        //   1. $cur is aligned on a 2^k boundary, AND
        //   2. ($cur + 2^k - 1) <= $end_n.
        // k ranges from 128 (covers entire address space) down to 0 (single host).
        $max_k = 0;
        for ($k = 128; $k >= 0; $k--) {
            if ($k === 128) {
                // Special case: /0 only valid when cur == 0 and end == all-ones.
                $all_ones = gmp_sub(gmp_pow(gmp_init(2), 128), gmp_init(1));
                if (gmp_cmp($cur, gmp_init(0)) === 0 && gmp_cmp($end_n, $all_ones) === 0) {
                    $max_k = 128;
                    break;
                }
                continue;
            }
            $block_size = gmp_pow(gmp_init(2), $k);
            $mask       = gmp_sub($block_size, gmp_init(1));
            $aligned    = gmp_cmp(gmp_and($cur, $mask), gmp_init(0)) === 0;
            $end_of_blk = gmp_sub(gmp_add($cur, $block_size), gmp_init(1));
            $fits       = gmp_cmp($end_of_blk, $end_n) <= 0;
            if ($aligned && $fits) {
                $max_k = $k;
                break;
            }
        }

        $prefix  = 128 - $max_k;
        $cidrs[] = gmp_to_ipv6($cur) . '/' . $prefix;

        if ($max_k === 128) {
            // Entire space consumed.
            break;
        }

        $advance = gmp_pow(gmp_init(2), $max_k);
        $next    = gmp_add($cur, $advance);
        // Safety: detect wraparound past the 128-bit ceiling.
        $all_ones_check = gmp_sub(gmp_pow(gmp_init(2), 128), gmp_init(1));
        if (gmp_cmp($next, $all_ones_check) > 0) {
            break;
        }
        $cur = $next;
    }

    return [
        'cidrs'           => $cidrs,
        'count'           => count($cidrs),
        'total_addresses' => $total_addresses,
        'truncated'       => $truncated,
        'cap'             => $cap,
    ];
}

/**
 * Format a GMP address-count as either a PHP int (when it fits) or a
 * "2^N" string (when it overflows). Mirrors calculate_subnet6() conventions.
 *
 * @internal
 * @return int|string
 */
function _range6_format_count(\GMP $n)
{
    // PHP_INT_MAX on 64-bit is 2^63 - 1. Use a strict ceiling so we always
    // emit "2^N" form for values that would not round-trip cleanly.
    $max_safe = gmp_sub(gmp_pow(gmp_init(2), 62), gmp_init(1));
    if (gmp_cmp($n, $max_safe) <= 0) {
        return gmp_intval($n);
    }
    // Find the largest power of 2 that is <= $n. If $n is exactly a power of
    // two, emit "2^N". Otherwise we still emit the closest power-of-two label
    // since user-facing display only needs an order-of-magnitude indicator.
    // For exact ranges (which is what range6_to_cidrs produces from inputs),
    // this is always exact.
    $pow = 0;
    $two = gmp_init(2);
    $cur = gmp_init(1);
    while (gmp_cmp(gmp_mul($cur, $two), $n) <= 0) {
        $cur = gmp_mul($cur, $two);
        $pow++;
    }
    return '2^' . $pow;
}
