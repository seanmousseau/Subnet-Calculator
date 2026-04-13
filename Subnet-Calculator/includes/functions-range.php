<?php

declare(strict_types=1);

// ─── IP range → CIDR conversion ───────────────────────────────────────────────

/**
 * Convert an inclusive IPv4 address range to the minimal list of covering CIDR blocks.
 *
 * Uses the greedy largest-aligned-block algorithm: repeatedly find the largest
 * power-of-two block that (a) starts at the current pointer, (b) is aligned to
 * its own size, and (c) does not extend past $end; emit that block as a CIDR,
 * advance the pointer, and repeat until the range is exhausted.
 *
 * @return array{cidrs?: list<string>, error?: string}
 */
function range_to_cidrs(string $start, string $end): array
{
    $start_long = ip2long($start);
    $end_long   = ip2long($end);

    if ($start_long === false) {
        return ['error' => 'Invalid start IP address.'];
    }
    if ($end_long === false) {
        return ['error' => 'Invalid end IP address.'];
    }

    // Cast to unsigned 32-bit (ip2long returns a signed int on 64-bit PHP).
    $start_long = $start_long & 0xFFFFFFFF;
    $end_long   = $end_long   & 0xFFFFFFFF;

    if ($start_long > $end_long) {
        return ['error' => 'Start address must be less than or equal to end address.'];
    }

    $cidrs = [];
    $cur   = $start_long;

    while ($cur <= $end_long) {
        // Find the largest prefix (smallest block) aligned at $cur that fits.
        $max_k = 0;
        for ($k = 32; $k >= 0; $k--) {
            if ($k === 32) {
                // Special case: /0 block covers 2^32 addresses.
                // Only valid when cur == 0 and the entire space is requested.
                if ($cur === 0 && $end_long === 0xFFFFFFFF) {
                    $max_k = 32;
                    break;
                }
                continue;
            }
            $block_size = 1 << $k;
            $aligned    = ($cur & ($block_size - 1)) === 0;
            $fits       = ($cur + $block_size - 1) <= $end_long;
            if ($aligned && $fits) {
                $max_k = $k;
                break;
            }
        }

        if ($max_k === 32) {
            $cidrs[] = '0.0.0.0/0';
            break;
        }

        $prefix  = 32 - $max_k;
        $cidrs[] = long2ip((int)$cur) . '/' . $prefix;

        $advance = 1 << $max_k;
        $cur     = ($cur + $advance) & 0xFFFFFFFF;

        // Detect wrap-around (advancing past 255.255.255.255 → 0).
        if ($max_k > 0 && $cur === 0) {
            break;
        }
    }

    return ['cidrs' => $cidrs];
}
