<?php

declare(strict_types=1);

// ─── Supernet / Route summarisation ───────────────────────────────────────────

/**
 * Find the smallest single IPv4 supernet containing all input CIDRs.
 *
 * @param  string[] $cidrs  List of IPv4 CIDR strings, e.g. ['10.0.0.0/24', '10.0.1.0/24']
 * @return array{supernet?: string, error?: string}
 */
function supernet_find(array $cidrs): array
{
    if ($cidrs === []) {
        return ['error' => 'No CIDRs provided.'];
    }

    $low  = PHP_INT_MAX;
    $high = 0;

    foreach ($cidrs as $cidr) {
        $cidr = trim((string)$cidr);
        if (!preg_match('/^([0-9]{1,3}(?:\.[0-9]{1,3}){3})\/([0-9]{1,2})$/', $cidr, $m)) {
            return ['error' => "Invalid CIDR: {$cidr}"];
        }
        if (!is_valid_ipv4($m[1])) {
            return ['error' => "Invalid IPv4 address in: {$cidr}"];
        }
        $px = (int)$m[2];
        if ($px < 0 || $px > 32) {
            return ['error' => "Prefix length out of range in: {$cidr}"];
        }
        $mask      = $px === 0 ? 0 : ((~0 << (32 - $px)) & 0xFFFFFFFF);
        $net       = ip2long($m[1]) & $mask & 0xFFFFFFFF;
        $broadcast = $net | (~$mask & 0xFFFFFFFF);
        if ($net < $low) {
            $low = $net;
        }
        if ($broadcast > $high) {
            $high = $broadcast;
        }
    }

    // Find the common prefix length from the differing bits between lowest start and highest end
    $xor = ($low ^ $high) & 0xFFFFFFFF;
    if ($xor === 0) {
        // All inputs have the same address range
        $common_prefix = 32;
        $mask          = 0xFFFFFFFF;
    } else {
        $bit_len       = strlen(decbin($xor));
        $common_prefix = 32 - $bit_len;
        $mask          = $common_prefix === 0 ? 0 : ((~0 << (32 - $common_prefix)) & 0xFFFFFFFF);
    }

    $supernet_net = $low & $mask;

    return ['supernet' => long2ip($supernet_net) . '/' . $common_prefix];
}

/**
 * Reduce a list of IPv4 CIDRs to the minimal set of covering prefixes (route summarisation).
 *
 * Removes contained duplicates, then iteratively merges adjacent same-size siblings.
 *
 * @param  string[] $cidrs
 * @return array{summaries?: string[], error?: string}
 */
function summarise_cidrs(array $cidrs): array
{
    if ($cidrs === []) {
        return ['error' => 'No CIDRs provided.'];
    }

    /** @var array<array{0: int, 1: int}> $networks [net_long, prefix] */
    $networks = [];
    foreach ($cidrs as $cidr) {
        $cidr = trim((string)$cidr);
        if ($cidr === '') {
            continue;
        }
        if (!preg_match('/^([0-9]{1,3}(?:\.[0-9]{1,3}){3})\/([0-9]{1,2})$/', $cidr, $m)) {
            return ['error' => "Invalid CIDR: {$cidr}"];
        }
        if (!is_valid_ipv4($m[1])) {
            return ['error' => "Invalid IPv4 address in: {$cidr}"];
        }
        $px = (int)$m[2];
        if ($px < 0 || $px > 32) {
            return ['error' => "Prefix length out of range in: {$cidr}"];
        }
        $mask     = $px === 0 ? 0 : ((~0 << (32 - $px)) & 0xFFFFFFFF);
        $networks[] = [ip2long($m[1]) & $mask & 0xFFFFFFFF, $px];
    }

    if ($networks === []) {
        return ['error' => 'No valid CIDRs provided.'];
    }

    // Sort by prefix length ascending (larger networks first), then by net address
    usort($networks, static function (array $a, array $b): int {
        if ($a[1] !== $b[1]) {
            return $a[1] - $b[1];
        }
        return $a[0] < $b[0] ? -1 : ($a[0] > $b[0] ? 1 : 0);
    });

    // Remove duplicate and contained networks
    /** @var array<array{0: int, 1: int}> $filtered */
    $filtered = [];
    foreach ($networks as [$net, $px]) {
        $contained = false;
        foreach ($filtered as [$fnet, $fpx]) {
            if ($fpx <= $px) {
                $fmask = $fpx === 0 ? 0 : ((~0 << (32 - $fpx)) & 0xFFFFFFFF);
                if (($net & $fmask) === $fnet) {
                    $contained = true;
                    break;
                }
            }
        }
        if (!$contained) {
            $filtered[] = [$net, $px];
        }
    }

    // Re-sort by net address for merging pass
    usort($filtered, static function (array $a, array $b): int {
        return $a[0] < $b[0] ? -1 : ($a[0] > $b[0] ? 1 : 0);
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
                    $bit = 1 << (32 - $pxA);
                    // Merge if B is exactly the upper sibling of A
                    if (($netA & $bit) === 0 && ($netA | $bit) === $netB) {
                        $merged[]  = [$netA, $pxA - 1];
                        $i        += 2;
                        $changed   = true;
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
                return $a[0] < $b[0] ? -1 : ($a[0] > $b[0] ? 1 : 0);
            });
        }
    }

    $summaries = [];
    foreach ($filtered as [$net, $px]) {
        $summaries[] = long2ip($net) . '/' . $px;
    }

    return ['summaries' => $summaries];
}
