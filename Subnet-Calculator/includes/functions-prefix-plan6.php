<?php

declare(strict_types=1);

// IPv6 prefix-delegation planner — slice a parent IPv6 prefix into
// child prefixes (e.g. a /48 into /56s). Pure operator math, no detection.
//
// Counts at delegation differences ≥ 64 overflow signed 64-bit ints,
// so the implementation uses GMP throughout and returns the overflow
// form as the string `"2^N"` (consistent with calculate_subnet6() and
// vlsm6_allocate()). Smaller counts are returned as decimal strings.
//
// "contains_64s" surfaces the relationship between each child block
// and the standard /64 LAN sizing:
//   • child_length < 64  → "2^(64-child_length)" or decimal — count of /64s contained.
//   • child_length == 64 → "1" (the block is itself a /64).
//   • child_length > 64  → "subset of /64" (the block is smaller than a /64).

/**
 * Slice a parent IPv6 prefix into child prefixes.
 *
 * @param string $parent_prefix    e.g. '2001:db8::/48'
 * @param int    $child_length     e.g. 56 (must be > parent's prefix length, 1..128)
 * @param int    $count            number of child prefixes to allocate (≥1)
 * @param int    $start_offset     skip the first N child prefixes (≥0)
 * @param bool   $nibble_align     snap child_length up to next nibble boundary
 *
 * @return array{
 *   parent: array{prefix: string, length: int, total_children_str: string},
 *   children: list<array{index: int, prefix: string, first: string, last: string, contains_64s: string}>,
 *   free: array{remaining_str: string},
 *   normalized_child_length: int,
 * }
 *
 * @throws InvalidArgumentException
 */
function plan_prefix_delegation(
    string $parent_prefix,
    int $child_length,
    int $count,
    int $start_offset = 0,
    bool $nibble_align = true
): array {
    [$parent_bin, $parent_length] = prefix_plan6_parse_parent($parent_prefix);

    if ($child_length < 1 || $child_length > 128) {
        throw new InvalidArgumentException(
            'Child prefix length must be 1..128: ' . $child_length
        );
    }
    if ($child_length <= $parent_length) {
        throw new InvalidArgumentException(
            'Child prefix length must be greater than parent prefix length ('
            . $parent_length . '): ' . $child_length
        );
    }

    // Snap up to the next nibble (multiple of 4) when requested.
    $normalized_child_length = $child_length;
    if ($nibble_align && ($normalized_child_length % 4) !== 0) {
        $normalized_child_length += 4 - ($normalized_child_length % 4);
        if ($normalized_child_length > 128) {
            throw new InvalidArgumentException(
                'Nibble-aligned child prefix length exceeds 128: ' . $normalized_child_length
            );
        }
    }
    if ($normalized_child_length <= $parent_length) {
        // Possible only if $parent_length is itself non-nibble; guard anyway.
        throw new InvalidArgumentException(
            'Normalised child prefix length must be greater than parent prefix length ('
            . $parent_length . '): ' . $normalized_child_length
        );
    }

    if ($count < 1) {
        throw new InvalidArgumentException('Count must be at least 1: ' . $count);
    }
    $max_count = $GLOBALS['prefix_plan6_max_count'] ?? 256;
    if ($count > $max_count) {
        throw new InvalidArgumentException(
            sprintf('count must not exceed %d: %d', $max_count, $count)
        );
    }
    if ($start_offset < 0) {
        throw new InvalidArgumentException('Start offset must be ≥ 0: ' . $start_offset);
    }

    $diff = $normalized_child_length - $parent_length;            // 1..128
    $host_bits = 128 - $normalized_child_length;                  // 0..127
    $total_children = gmp_pow(2, $diff);                          // GMP, possibly huge
    $block_size     = gmp_pow(2, $host_bits);                     // GMP

    // Validate offset + count ≤ total_children using GMP comparison.
    $consumed = gmp_add(gmp_init((string) $start_offset, 10), gmp_init((string) $count, 10));
    if (gmp_cmp($consumed, $total_children) > 0) {
        throw new InvalidArgumentException(
            'start_offset + count (' . gmp_strval($consumed) . ') exceeds total children ('
            . prefix_plan6_format_count($total_children) . ').'
        );
    }

    // Mask parent host bits to canonicalise.
    $parent_int_raw = gmp_init(bin2hex($parent_bin), 16);
    if ($parent_length === 0) {
        $parent_int = gmp_init(0);
    } else {
        $parent_mask = gmp_sub(gmp_pow(2, 128), gmp_pow(2, 128 - $parent_length));
        $parent_int  = gmp_and($parent_int_raw, $parent_mask);
    }
    $parent_canonical = prefix_plan6_format_address($parent_int);

    // Walk children.
    $children = [];
    for ($i = 0; $i < $count; $i++) {
        $idx_gmp     = gmp_add(gmp_init((string) $start_offset, 10), gmp_init((string) $i, 10));
        $offset_int  = gmp_mul($idx_gmp, $block_size);
        $child_first = gmp_add($parent_int, $offset_int);
        $child_last  = gmp_sub(gmp_add($child_first, $block_size), 1);

        $children[] = [
            'index'        => (int) gmp_strval($idx_gmp),
            'prefix'       => prefix_plan6_format_address($child_first) . '/' . $normalized_child_length,
            'first'        => prefix_plan6_format_address($child_first),
            'last'         => prefix_plan6_format_address($child_last),
            'contains_64s' => prefix_plan6_contains_64s($normalized_child_length),
        ];
    }

    // Free remaining = total_children - (start_offset + count).
    $remaining = gmp_sub($total_children, $consumed);

    return [
        'parent' => [
            'prefix'             => $parent_canonical . '/' . $parent_length,
            'length'             => $parent_length,
            'total_children_str' => prefix_plan6_format_count($total_children),
        ],
        'children'                => $children,
        'free'                    => [
            'remaining_str' => prefix_plan6_format_count($remaining),
        ],
        'normalized_child_length' => $normalized_child_length,
    ];
}

/**
 * Parse a parent prefix string ("2001:db8::/48") into the 16-byte network
 * address and prefix length.
 *
 * @return array{0: string, 1: int}
 *
 * @throws InvalidArgumentException
 */
function prefix_plan6_parse_parent(string $parent_prefix): array
{
    $raw = trim($parent_prefix);
    if ($raw === '') {
        throw new InvalidArgumentException('Parent IPv6 prefix is required.');
    }
    if (strpos($raw, '/') === false) {
        throw new InvalidArgumentException(
            'Parent IPv6 prefix must include a length (e.g. 2001:db8::/48): ' . $raw
        );
    }
    [$addr, $lenStr] = explode('/', $raw, 2);
    if ($addr === '' || $lenStr === '' || !ctype_digit($lenStr)) {
        throw new InvalidArgumentException('Invalid parent IPv6 prefix: ' . $raw);
    }
    if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
        throw new InvalidArgumentException('Invalid parent IPv6 address: ' . $addr);
    }
    $len = (int) $lenStr;
    if ($len < 0 || $len > 128) {
        throw new InvalidArgumentException(
            'Parent IPv6 prefix length must be 0..128: ' . $len
        );
    }
    $bin = @inet_pton($addr);
    if ($bin === false || strlen($bin) !== 16) {
        throw new InvalidArgumentException('Invalid parent IPv6 address: ' . $addr);
    }
    return [$bin, $len];
}

/**
 * Format a 128-bit GMP integer as a canonical IPv6 address.
 */
function prefix_plan6_format_address(\GMP $int): string
{
    $hex   = str_pad(gmp_strval($int, 16), 32, '0', STR_PAD_LEFT);
    $bytes = hex2bin($hex);
    if ($bytes === false || strlen($bytes) !== 16) {
        throw new InvalidArgumentException('Internal error packing IPv6 address.');
    }
    $canonical = @inet_ntop($bytes);
    if ($canonical === false) {
        throw new InvalidArgumentException('Internal error formatting IPv6 address.');
    }
    return $canonical;
}

/**
 * Render a GMP count as either a decimal string (when it fits comfortably)
 * or the "2^N" overflow form for clean powers of two when N ≥ 63.
 */
function prefix_plan6_format_count(\GMP $count): string
{
    // If count is a clean power of two and the exponent is ≥ 63, surface as "2^N".
    if (gmp_cmp($count, 0) > 0) {
        $maybe_log = gmp_strval($count, 2);
        // Power of two ⇔ binary form is "1" followed by zeros.
        if ($maybe_log[0] === '1' && rtrim($maybe_log, '0') === '1') {
            $exp = strlen($maybe_log) - 1;
            if ($exp >= 63) {
                return '2^' . $exp;
            }
        }
    }
    return gmp_strval($count, 10);
}

/**
 * Given an arbitrary IPv6 prefix, return the nibble-aligned neighbours.
 *
 * "above" = aligned and ≤ input length (less specific, a higher-up nibble
 * boundary in the tree). "below" = aligned and ≥ input length (more
 * specific, a lower nibble boundary). For an input `/49`: above=`/48`,
 * below=`/52`.
 *
 * Edge cases:
 *   • Input length is already nibble-aligned → above stays at the input
 *     length; below moves to the next deeper nibble (input + 4), capped
 *     at /128.
 *   • Input length is /128 → both above and below collapse to /128
 *     (no further refinement is possible).
 *   • Input length 0..3 → above snaps down to /0; below = /4.
 *   • Input length 125..127 → below caps at /128.
 *
 * Both neighbour prefixes are emitted in canonical form: address bits
 * beyond the neighbour's prefix length are zeroed via GMP masking, then
 * formatted with inet_ntop() (lowercase, compressed).
 *
 * `contains_64s` reuses prefix_plan6_contains_64s() so the field's
 * formatting matches the prefix-delegation planner exactly.
 *
 * @return array{
 *   input: array{prefix: string, length: int},
 *   above: array{length: int, prefix: string, contains_64s: string},
 *   below: array{length: int, prefix: string, contains_64s: string},
 * }
 *
 * @throws InvalidArgumentException
 */
function nibble_neighbours(string $prefix): array
{
    [$bin, $length] = prefix_plan6_parse_parent($prefix);

    // Snap-down to nearest nibble boundary at-or-below the input length.
    $above_length = $length - ($length % 4);
    // Snap-up to nearest nibble boundary at-or-above the input length,
    // capped at 128. When the input is already aligned, advance to the
    // next deeper nibble. /128 is its own floor.
    if ($length >= 128) {
        $below_length = 128;
    } elseif (($length % 4) === 0) {
        $below_length = min(128, $length + 4);
    } else {
        $below_length = $length + (4 - ($length % 4));
        if ($below_length > 128) {
            $below_length = 128;
        }
    }

    $addr_int = gmp_init(bin2hex($bin), 16);

    return [
        'input' => [
            'prefix' => prefix_plan6_format_address(
                nibble6_mask_to_length($addr_int, $length)
            ) . '/' . $length,
            'length' => $length,
        ],
        'above' => [
            'length'       => $above_length,
            'prefix'       => prefix_plan6_format_address(
                nibble6_mask_to_length($addr_int, $above_length)
            ) . '/' . $above_length,
            'contains_64s' => prefix_plan6_contains_64s($above_length),
        ],
        'below' => [
            'length'       => $below_length,
            'prefix'       => prefix_plan6_format_address(
                nibble6_mask_to_length($addr_int, $below_length)
            ) . '/' . $below_length,
            'contains_64s' => prefix_plan6_contains_64s($below_length),
        ],
    ];
}

/**
 * Mask a 128-bit GMP integer to the given prefix length (zero host bits).
 * Length 0 returns 0; length 128 returns the address unchanged.
 */
function nibble6_mask_to_length(\GMP $addr_int, int $length): \GMP
{
    if ($length <= 0) {
        return gmp_init(0);
    }
    if ($length >= 128) {
        return $addr_int;
    }
    $mask = gmp_sub(gmp_pow(2, 128), gmp_pow(2, 128 - $length));
    return gmp_and($addr_int, $mask);
}

/**
 * Translate a child prefix length into a description of how it relates to
 * the standard /64 LAN sizing. Three branches:
 *   • child_length < 64  → "2^(64-child_length)" or decimal — number of /64s contained.
 *   • child_length == 64 → "1" — the block is itself a /64.
 *   • child_length > 64  → "subset of /64" — the block is smaller than a /64.
 */
function prefix_plan6_contains_64s(int $child_length): string
{
    if ($child_length === 64) {
        return '1';
    }
    if ($child_length > 64) {
        return 'subset of /64';
    }
    // child_length < 64 → contains 2^(64 - child_length) /64s.
    $count = gmp_pow(2, 64 - $child_length);
    return prefix_plan6_format_count($count);
}
