<?php

declare(strict_types=1);

// ─── IPv6 Supernet / Route summarisation (v3.3.0) ────────────────────────────
//
// Mirrors the IPv4 `supernet_find()` / `summarise_cidrs()` API in
// functions-supernet.php exactly, but uses GMP arithmetic throughout because
// IPv6 addresses are 128-bit integers that overflow native PHP int. Never use
// `1 << diff` for prefix differences ≥ 63 — use gmp_pow / gmp_mul instead.
//
// Both helpers return ['supernet' => string]/['summaries' => string[]] on
// success or ['error' => string] on validation failure. Output addresses are
// emitted in canonical compressed form via gmp_to_ipv6() (which wraps
// inet_ntop()).

/**
 * Build the 128-bit network mask for a given prefix length.
 */
function supernet6_prefix_mask(int $prefix): \GMP
{
    if ($prefix <= 0) {
        return gmp_init(0);
    }
    if ($prefix >= 128) {
        // 2^128 - 1
        return gmp_sub(gmp_pow(gmp_init(2), 128), gmp_init(1));
    }
    // (2^128 - 1) ^ (2^(128-prefix) - 1)  →  prefix-many top bits set
    $all_ones  = gmp_sub(gmp_pow(gmp_init(2), 128), gmp_init(1));
    $host_bits = 128 - $prefix;
    $host_mask = gmp_sub(gmp_pow(gmp_init(2), $host_bits), gmp_init(1));
    return gmp_xor($all_ones, $host_mask);
}

/**
 * Parse and validate an IPv6 CIDR string.
 *
 * @return array{0: \GMP, 1: int}|array{error: string}  [network, prefix] or error array
 */
function supernet6_parse_cidr(string $cidr): array
{
    if (strpos($cidr, '/') === false) {
        return ['error' => "Invalid CIDR: {$cidr}"];
    }
    [$ip, $px_str] = explode('/', $cidr, 2);
    if (!ctype_digit($px_str)) {
        return ['error' => "Invalid prefix in: {$cidr}"];
    }
    $prefix = (int)$px_str;
    if ($prefix < 0 || $prefix > 128) {
        return ['error' => "Prefix length out of range in: {$cidr}"];
    }
    if (!is_valid_ipv6($ip)) {
        return ['error' => "Invalid IPv6 address in: {$cidr}"];
    }
    $addr = ipv6_to_gmp($ip);
    $mask = supernet6_prefix_mask($prefix);
    $net  = gmp_and($addr, $mask);
    return [$net, $prefix];
}

/**
 * Find the smallest single IPv6 supernet containing all input CIDRs.
 *
 * @param  string[] $cidrs
 * @return array{supernet?: string, error?: string}
 */
function supernet6_find(array $cidrs): array
{
    if ($cidrs === []) {
        return ['error' => 'No CIDRs provided.'];
    }

    $low  = null;  // \GMP|null
    $high = null;  // \GMP|null

    foreach ($cidrs as $cidr) {
        $cidr = trim((string)$cidr);
        if ($cidr === '') {
            continue;
        }
        $parsed = supernet6_parse_cidr($cidr);
        if (isset($parsed['error'])) {
            /** @var string $err */
            $err = $parsed['error'];
            return ['error' => $err];
        }
        /** @var \GMP $net */
        $net    = $parsed[0];
        /** @var int $prefix */
        $prefix = $parsed[1];

        $host_bits = 128 - $prefix;
        $broadcast = $host_bits > 0
            ? gmp_or($net, gmp_sub(gmp_pow(gmp_init(2), $host_bits), gmp_init(1)))
            : $net;

        if ($low === null || gmp_cmp($net, $low) < 0) {
            $low = $net;
        }
        if ($high === null || gmp_cmp($broadcast, $high) > 0) {
            $high = $broadcast;
        }
    }

    if ($low === null || $high === null) {
        return ['error' => 'No valid CIDRs provided.'];
    }

    // Find common prefix from XOR of low and high
    $xor = gmp_xor($low, $high);
    if (gmp_cmp($xor, gmp_init(0)) === 0) {
        $common_prefix = 128;
    } else {
        // Bit length of $xor → bits that differ
        $bit_len = strlen(gmp_strval($xor, 2));
        $common_prefix = 128 - $bit_len;
    }
    if ($common_prefix < 0) {
        $common_prefix = 0;
    }

    $mask = supernet6_prefix_mask($common_prefix);
    $supernet_net = gmp_and($low, $mask);

    return ['supernet' => gmp_to_ipv6($supernet_net) . '/' . $common_prefix];
}

/**
 * Reduce a list of IPv6 CIDRs to the minimal set of covering prefixes.
 *
 * Removes contained duplicates, then iteratively merges adjacent same-size
 * siblings. Inputs are normalised to canonical form (host bits zeroed,
 * lowercased + compressed) before comparison so two textually-different
 * inputs that resolve to the same network are treated as identical.
 *
 * @param  string[] $cidrs
 * @return array{summaries?: string[], error?: string}
 */
function summarise6_cidrs(array $cidrs): array
{
    if ($cidrs === []) {
        return ['error' => 'No CIDRs provided.'];
    }

    /** @var array<array{0: \GMP, 1: int}> $networks */
    $networks = [];
    foreach ($cidrs as $cidr) {
        $cidr = trim((string)$cidr);
        if ($cidr === '') {
            continue;
        }
        $parsed = supernet6_parse_cidr($cidr);
        if (isset($parsed['error'])) {
            /** @var string $err */
            $err = $parsed['error'];
            return ['error' => $err];
        }
        $networks[] = [$parsed[0], $parsed[1]];
    }

    if ($networks === []) {
        return ['error' => 'No valid CIDRs provided.'];
    }

    // Sort by prefix ascending (larger networks first), then by net address
    usort($networks, static function (array $a, array $b): int {
        if ($a[1] !== $b[1]) {
            return $a[1] - $b[1];
        }
        return gmp_cmp($a[0], $b[0]);
    });

    // Remove duplicates and contained networks
    /** @var array<array{0: \GMP, 1: int}> $filtered */
    $filtered = [];
    foreach ($networks as [$net, $px]) {
        $contained = false;
        foreach ($filtered as [$fnet, $fpx]) {
            if ($fpx <= $px) {
                $fmask = supernet6_prefix_mask($fpx);
                if (gmp_cmp(gmp_and($net, $fmask), $fnet) === 0) {
                    $contained = true;
                    break;
                }
            }
        }
        if (!$contained) {
            $filtered[] = [$net, $px];
        }
    }

    // Re-sort by net address for merging
    usort($filtered, static function (array $a, array $b): int {
        return gmp_cmp($a[0], $b[0]);
    });

    // Iteratively merge adjacent same-size siblings
    $changed = true;
    while ($changed) {
        $changed = false;
        $merged  = [];
        $i       = 0;
        $count   = count($filtered);
        while ($i < $count) {
            if ($i + 1 < $count) {
                [$netA, $pxA] = $filtered[$i];
                [$netB, $pxB] = $filtered[$i + 1];
                if ($pxA === $pxB && $pxA > 0) {
                    // The sibling-merge bit lives at position (128 - pxA).
                    $bit = gmp_pow(gmp_init(2), 128 - $pxA);
                    if (
                        gmp_cmp(gmp_and($netA, $bit), gmp_init(0)) === 0
                        && gmp_cmp(gmp_or($netA, $bit), $netB) === 0
                    ) {
                        $merged[] = [$netA, $pxA - 1];
                        $i       += 2;
                        $changed  = true;
                        continue;
                    }
                }
            }
            $merged[] = $filtered[$i];
            $i++;
        }
        $filtered = $merged;

        if ($changed) {
            usort($filtered, static function (array $a, array $b): int {
                return gmp_cmp($a[0], $b[0]);
            });
        }
    }

    $summaries = [];
    foreach ($filtered as [$net, $px]) {
        $summaries[] = gmp_to_ipv6($net) . '/' . $px;
    }

    return ['summaries' => $summaries];
}
