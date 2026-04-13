<?php

declare(strict_types=1);

// ─── Subnet allocation tree view ──────────────────────────────────────────────

/**
 * Return true when CIDR $a fully contains CIDR $b.
 *
 * cidrs_overlap() only detects containment when both CIDRs share the same
 * network address at the child's mask level, so it fails for children that
 * start mid-parent (e.g. 10.0.0.128/25 inside 10.0.0.0/24).  This helper
 * applies $a's mask to $b's network address and compares it to $a's network.
 */
function tree_cidr_a_contains_b(string $a, string $b): bool
{
    [$ip_a, $px_a] = explode('/', $a);
    [$ip_b, $px_b] = explode('/', $b);
    $px_a = (int)$px_a;
    $px_b = (int)$px_b;
    if ($px_a > $px_b) {
        return false;  // $a is more specific than $b, cannot contain it
    }
    $net_a  = ip2long($ip_a) & 0xFFFFFFFF;
    $net_b  = ip2long($ip_b) & 0xFFFFFFFF;
    $mask_a = $px_a === 0 ? 0 : ((~0 << (32 - $px_a)) & 0xFFFFFFFF);
    return ($net_b & $mask_a) === $net_a;
}

/**
 * Build a hierarchical allocation tree for a parent CIDR and a set of child CIDRs.
 *
 * Returns a nested structure showing which parts of the parent address space
 * are allocated by the children, which are free (gaps), and how children nest
 * within each other.
 *
 * @param  string   $parent   Parent CIDR (e.g. '10.0.0.0/16').
 * @param  string[] $children List of child CIDR strings.
 * @return array{tree?: array<string, mixed>, error?: string}
 */
function build_subnet_tree(string $parent, array $children): array
{
    // ── Validate parent ───────────────────────────────────────────────────────
    if (!preg_match('#^([0-9.]+)/(\d+)$#', $parent, $pm)) {
        return ['error' => 'Invalid parent CIDR format.'];
    }
    if (!is_valid_ipv4($pm[1]) || (int)$pm[2] > 32) {
        return ['error' => 'Invalid parent CIDR.'];
    }

    // ── Validate and normalise children ──────────────────────────────────────
    $valid_children = [];
    $errors         = [];
    foreach ($children as $child) {
        $child = trim((string)$child);
        if ($child === '') {
            continue;
        }
        if (!preg_match('#^([0-9.]+)/(\d+)$#', $child, $cm)) {
            $errors[] = "Invalid CIDR format: $child";
            continue;
        }
        if (!is_valid_ipv4($cm[1]) || (int)$cm[2] > 32) {
            $errors[] = "Invalid CIDR: $child";
            continue;
        }
        // Ensure the child is actually within the parent.
        if (!tree_cidr_a_contains_b($parent, $child) && $parent !== $child) {
            $errors[] = "CIDR $child is outside the parent $parent.";
            continue;
        }
        $valid_children[] = $child;
    }

    if (!empty($errors)) {
        return ['error' => implode(' ', $errors)];
    }

    // Remove duplicates.
    $valid_children = array_values(array_unique($valid_children));

    // ── Sort: wider networks (smaller prefix) first, then by network address ──
    usort($valid_children, function (string $a, string $b): int {
        [, $pa] = explode('/', $a);
        [, $pb] = explode('/', $b);
        $pa = (int)$pa;
        $pb = (int)$pb;
        if ($pa !== $pb) {
            return $pa - $pb;  // smaller prefix = wider network = sort first
        }
        $la = ip2long(explode('/', $a)[0]) & 0xFFFFFFFF;
        $lb = ip2long(explode('/', $b)[0]) & 0xFFFFFFFF;
        return $la <=> $lb;
    });

    // ── Build containment map: for each child find its direct parent ──────────
    // Direct parent = the tightest CIDR in {$parent} ∪ $valid_children that
    // contains this child (other than the child itself).
    $all_nodes    = array_merge([$parent], $valid_children);
    $direct_parent = [];  // child => direct_parent_cidr

    foreach ($valid_children as $child) {
        $best        = null;
        $best_prefix = -1;
        foreach ($all_nodes as $candidate) {
            if ($candidate === $child) {
                continue;
            }
            if (tree_cidr_a_contains_b($candidate, $child) || $candidate === $child) {
                [, $cp] = explode('/', $candidate);
                if ((int)$cp > $best_prefix) {
                    $best_prefix = (int)$cp;
                    $best        = $candidate;
                }
            }
        }
        $direct_parent[$child] = $best ?? $parent;
    }

    // ── Recursively assemble tree nodes ───────────────────────────────────────
    /**
     * @param  string   $node     CIDR of this node.
     * @param  string[] $all      All validated children.
     * @param  array<string,string> $dp  Direct-parent map.
     * @return array<string, mixed>
     */
    $build_node = null;
    $build_node = function (string $node, array $all, array $dp) use (&$build_node): array {
        $direct_children = [];
        foreach ($all as $c) {
            if ($dp[$c] === $node) {
                $direct_children[] = $c;
            }
        }

        // Sort direct children by network address.
        usort($direct_children, function (string $a, string $b): int {
            $la = ip2long(explode('/', $a)[0]) & 0xFFFFFFFF;
            $lb = ip2long(explode('/', $b)[0]) & 0xFFFFFFFF;
            return $la <=> $lb;
        });

        // Compute gaps: walk from $node network to broadcast, find uncovered ranges.
        $gaps = tree_compute_gaps($node, $direct_children);

        $children_nodes = [];
        foreach ($direct_children as $dc) {
            $children_nodes[] = $build_node($dc, $all, $dp);
        }

        return [
            'cidr'      => $node,
            'allocated' => $node !== '',
            'children'  => $children_nodes,
            'gaps'      => $gaps,
        ];
    };

    $tree = $build_node($parent, $valid_children, $direct_parent);

    return ['tree' => $tree];
}

/**
 * Compute gap CIDRs within $parent that are not covered by any direct child.
 *
 * @param  string   $parent          Parent CIDR.
 * @param  string[] $sorted_children Direct children sorted by network address.
 * @return list<string>
 */
function tree_compute_gaps(string $parent, array $sorted_children): array
{
    if (empty($sorted_children)) {
        return [];
    }

    [, $parent_prefix] = explode('/', $parent);
    $parent_net       = ip2long(explode('/', $parent)[0]) & 0xFFFFFFFF;
    $parent_bits      = 32 - (int)$parent_prefix;
    $parent_broadcast = $parent_net + (1 << $parent_bits) - 1;

    $gaps = [];
    $cur  = $parent_net;

    foreach ($sorted_children as $child) {
        [, $child_prefix] = explode('/', $child);
        $child_net       = ip2long(explode('/', $child)[0]) & 0xFFFFFFFF;
        $child_bits      = 32 - (int)$child_prefix;
        $child_broadcast = $child_net + (1 << $child_bits) - 1;

        if ($cur < $child_net) {
            // There is a gap from $cur to $child_net - 1.
            $gap_start = long2ip((int)$cur);
            $gap_end   = long2ip((int)($child_net - 1));
            if (is_string($gap_start) && is_string($gap_end)) {
                $gap_cidrs = range_to_cidrs($gap_start, $gap_end);
                foreach (($gap_cidrs['cidrs'] ?? []) as $gc) {
                    $gaps[] = $gc;
                }
            }
        }

        // Advance past this child.
        if ($child_broadcast >= $cur) {
            $cur = $child_broadcast + 1;
        }
    }

    // Gap after the last child up to the parent broadcast.
    if ($cur <= $parent_broadcast) {
        $gap_start = long2ip((int)$cur);
        $gap_end   = long2ip((int)$parent_broadcast);
        if (is_string($gap_start) && is_string($gap_end)) {
            $gap_cidrs = range_to_cidrs($gap_start, $gap_end);
            foreach (($gap_cidrs['cidrs'] ?? []) as $gc) {
                $gaps[] = $gc;
            }
        }
    }

    return $gaps;
}
