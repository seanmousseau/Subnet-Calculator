<?php

declare(strict_types=1);

// RFC 3531 sparse-allocation guidance — produce the next-allocation
// order for a reservation field of N bits and apply that order to a
// parent IPv6 prefix. Pure operator math; composes with the IPv6
// prefix-delegation planner (functions-prefix-plan6.php) for assigning
// growth-friendly child prefixes from a delegated parent.
//
// Three strategies:
//   • centermost — bisect outward from the middle (RFC 3531 §3 example).
//   • leftmost   — monotonic 0..2^N-1 (dense sequential allocation).
//   • rightmost  — reverse of leftmost (allocate from the top down).
//
// The cap on $reservation_bits is sourced from $GLOBALS['rfc3531_max_bits']
// (default 8 → 256 children). The hard absolute ceiling is 16, mirroring
// $prefix_plan6_max_count's clamped-cap pattern.

/**
 * Produce the next-allocation order for a reservation field of
 * `$reservation_bits` bits, using the given strategy.
 *
 * Returns 2^N values for leftmost/rightmost; 2^N - 1 values for
 * centermost (omits 0, the default reservation).
 *
 * @param int    $reservation_bits  number of bits in the reservation field (1..16)
 * @param string $strategy          'centermost' | 'leftmost' | 'rightmost'
 *
 * @return list<int>  ordered list of bit-pattern values
 *
 * @throws InvalidArgumentException
 */
function rfc3531_allocation_order(int $reservation_bits, string $strategy): array
{
    if ($reservation_bits < 1) {
        throw new InvalidArgumentException(
            'reservation_bits must be at least 1: ' . $reservation_bits
        );
    }
    // Absolute hard ceiling — 2^16 = 65536 patterns is already excessive for
    // a UI table, but we keep it as a defensive upper bound. The configurable
    // cap below is the practical limit.
    if ($reservation_bits > 16) {
        throw new InvalidArgumentException(
            'reservation_bits exceeds absolute ceiling of 16: ' . $reservation_bits
        );
    }
    $cap = $GLOBALS['rfc3531_max_bits'] ?? 8;
    if (!is_int($cap) || $cap < 1 || $cap > 16) {
        $cap = 8;
    }
    if ($reservation_bits > $cap) {
        throw new InvalidArgumentException(
            sprintf(
                'reservation_bits must not exceed configured cap of %d: %d',
                $cap,
                $reservation_bits
            )
        );
    }
    if (!in_array($strategy, ['leftmost', 'centermost', 'rightmost'], true)) {
        throw new InvalidArgumentException(
            'strategy must be one of "leftmost", "centermost", "rightmost": ' . $strategy
        );
    }

    $size = 1 << $reservation_bits;

    if ($strategy === 'leftmost') {
        return range(0, $size - 1);
    }
    if ($strategy === 'rightmost') {
        return array_reverse(range(0, $size - 1));
    }

    // centermost — BFS over a balanced binary bit-pattern tree.
    // For each level L from 1..bits, emit values at positions
    // (2k+1) * 2^(bits - L) for k = 0..2^(L-1) - 1, in left-to-right order.
    // This produces 8, 4, 12, 2, 6, 10, 14, 1, 3, 5, 7, 9, 11, 13, 15 for bits=4.
    $order = [];
    for ($level = 1; $level <= $reservation_bits; $level++) {
        $step      = 1 << ($reservation_bits - $level); // 2^(bits-L)
        $count     = 1 << ($level - 1);                 // 2^(L-1)
        for ($k = 0; $k < $count; $k++) {
            $order[] = ((2 * $k) + 1) * $step;
        }
    }
    return $order;
}

/**
 * Apply the strategy to a parent prefix to produce concrete child prefixes.
 *
 * Each child block has prefix length `parent_length + reservation_bits`
 * and address `parent_int + value * 2^(128 - parent_length - reservation_bits)`.
 * GMP throughout — child arithmetic must work for any parent prefix length.
 *
 * @param string $parent_prefix     e.g. '2001:db8::/48'
 * @param int    $reservation_bits  e.g. 4 (allocates from /48 down to /52, 16 children)
 * @param string $strategy          'centermost' | 'leftmost' | 'rightmost'
 *
 * @return array{
 *   parent: array{prefix: string, length: int},
 *   strategy: string,
 *   reservation_bits: int,
 *   allocation_order: list<int>,
 *   children: list<array{order: int, value: int, prefix: string}>,
 * }
 *
 * @throws InvalidArgumentException
 */
function rfc3531_apply(string $parent_prefix, int $reservation_bits, string $strategy): array
{
    [$parent_bin, $parent_length] = prefix_plan6_parse_parent($parent_prefix);

    // Validates reservation_bits and strategy and applies the configured cap.
    $order = rfc3531_allocation_order($reservation_bits, $strategy);

    $child_length = $parent_length + $reservation_bits;
    if ($child_length > 128) {
        throw new InvalidArgumentException(
            'parent_length + reservation_bits exceeds 128: '
            . $parent_length . ' + ' . $reservation_bits . ' = ' . $child_length
        );
    }

    // Canonicalise parent: zero host bits beyond $parent_length.
    $parent_int_raw = gmp_init(bin2hex($parent_bin), 16);
    if ($parent_length === 0) {
        $parent_int = gmp_init(0);
    } else {
        $parent_mask = gmp_sub(gmp_pow(2, 128), gmp_pow(2, 128 - $parent_length));
        $parent_int  = gmp_and($parent_int_raw, $parent_mask);
    }
    $parent_canonical = prefix_plan6_format_address($parent_int);

    $host_bits  = 128 - $child_length;       // 0..127
    $block_size = gmp_pow(2, $host_bits);    // 2^host_bits

    $children = [];
    foreach ($order as $idx => $value) {
        $offset_int  = gmp_mul(gmp_init((string) $value, 10), $block_size);
        $child_first = gmp_add($parent_int, $offset_int);
        $children[]  = [
            'order'  => $idx,
            'value'  => $value,
            'prefix' => prefix_plan6_format_address($child_first) . '/' . $child_length,
        ];
    }

    return [
        'parent'           => [
            'prefix' => $parent_canonical . '/' . $parent_length,
            'length' => $parent_length,
        ],
        'strategy'         => $strategy,
        'reservation_bits' => $reservation_bits,
        'allocation_order' => $order,
        'children'         => $children,
    ];
}
