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

// ─── Tree-editor payload validation (#302, v3.0.0) ────────────────────────────

/**
 * Validate a tree-editor session payload against the rules locked in the
 * 2026-05-03 design doc:
 *
 *  - root.cidr is a valid IPv4 or IPv6 CIDR with prefix.
 *  - Each node's CIDR is canonical (host bits zeroed; for IPv6 also lower-case
 *    compressed form).
 *  - Each `children` array, if present, has 2..64 entries (matches schema).
 *  - Every child is contained within its parent (network falls inside parent
 *    and child prefix > parent prefix).
 *  - Children are non-overlapping (gaps allowed; sibling-merge invariant).
 *  - `name` ≤ 128 chars; `notes` ≤ 1024 chars.
 *  - Tree depth ≤ 16.
 *  - Total node count ≤ 1024.
 *  - Whole tree is single-family (all-IPv4 or all-IPv6).
 *
 * Throws \InvalidArgumentException on the first rule violation.  The message
 * is safe to surface in API responses (no PII, no stack info).
 *
 * @param array<string,mixed> $tree The full tree-session payload (with `type`
 *                                   and `root` keys, as accepted by
 *                                   POST /api/v1/sessions).
 * @throws \InvalidArgumentException
 */
function tree_validate(array $tree): void
{
    if (($tree['type'] ?? null) !== 'tree') {
        throw new \InvalidArgumentException('tree_validate: type must be "tree".');
    }
    if (!isset($tree['root']) || !is_array($tree['root'])) {
        throw new \InvalidArgumentException('tree_validate: root must be an object.');
    }

    $count = 0;
    $family = tree_node_family($tree['root']);
    tree_validate_node($tree['root'], null, $family, 0, $count);
}

/**
 * Detect whether the root node CIDR is IPv4 or IPv6.
 * Throws if the root CIDR is malformed.
 *
 * @param array<string,mixed> $node
 * @return 'ipv4'|'ipv6'
 */
function tree_node_family(array $node): string
{
    if (!isset($node['cidr']) || !is_string($node['cidr'])) {
        throw new \InvalidArgumentException('tree_validate: root.cidr is required.');
    }
    if (strpos($node['cidr'], ':') !== false) {
        return 'ipv6';
    }
    return 'ipv4';
}

/**
 * Recursive node validator.  Mutates $count by reference for the global cap.
 *
 * @param array<string,mixed>      $node
 * @param array<string,mixed>|null $parent
 * @param 'ipv4'|'ipv6'            $family
 */
function tree_validate_node(array $node, ?array $parent, string $family, int $depth, int &$count): void
{
    $count++;
    if ($count > 1024) {
        throw new \InvalidArgumentException('tree_validate: total node count exceeds 1024.');
    }
    if ($depth > 16) {
        throw new \InvalidArgumentException('tree_validate: tree depth exceeds 16.');
    }
    if (!isset($node['cidr']) || !is_string($node['cidr'])) {
        throw new \InvalidArgumentException('tree_validate: every node requires a string cidr.');
    }

    $cidr = $node['cidr'];
    [$ip, $px] = tree_split_cidr($cidr, $family);

    // Canonical-form check + family-mismatch rejection.
    $canonical = tree_canonical_cidr($ip, $px, $family);
    if ($canonical !== $cidr) {
        throw new \InvalidArgumentException(
            'tree_validate: cidr "' . $cidr . '" is not canonical (expected "' . $canonical . '").'
        );
    }

    // Containment: child must be inside parent, and child prefix > parent prefix.
    if ($parent !== null) {
        [$pip, $ppx] = tree_split_cidr((string)$parent['cidr'], $family);
        if ($px <= $ppx) {
            throw new \InvalidArgumentException(
                'tree_validate: child "' . $cidr . '" prefix /' . $px
                . ' must be longer than parent /' . $ppx . '.'
            );
        }
        if (!tree_contains($pip, $ppx, $ip, $family)) {
            throw new \InvalidArgumentException(
                'tree_validate: child "' . $cidr . '" is not inside parent "' . $parent['cidr'] . '".'
            );
        }
    }

    // String length caps for name / notes.
    if (isset($node['name'])) {
        if (!is_string($node['name'])) {
            throw new \InvalidArgumentException('tree_validate: name must be a string.');
        }
        if (strlen($node['name']) > 128) {
            throw new \InvalidArgumentException('tree_validate: name exceeds 128 characters.');
        }
    }
    if (isset($node['notes'])) {
        if (!is_string($node['notes'])) {
            throw new \InvalidArgumentException('tree_validate: notes must be a string.');
        }
        if (strlen($node['notes']) > 1024) {
            throw new \InvalidArgumentException('tree_validate: notes exceed 1024 characters.');
        }
    }

    // Children: ≥2 entries, no overlaps, recursively validate.
    if (isset($node['children'])) {
        if (!is_array($node['children']) || count($node['children']) < 2) {
            throw new \InvalidArgumentException(
                'tree_validate: children of "' . $cidr . '" must be a list with at least 2 entries.'
            );
        }
        if (count($node['children']) > 64) {
            throw new \InvalidArgumentException(
                'tree_validate: children of "' . $cidr . '" exceed 64 entries.'
            );
        }

        $ranges = []; // [ [start_gmp_or_int, end_gmp_or_int, cidr_string] ]
        foreach ($node['children'] as $child) {
            if (!is_array($child)) {
                throw new \InvalidArgumentException('tree_validate: each child must be an object.');
            }
            $cstr = isset($child['cidr']) && is_string($child['cidr']) ? $child['cidr'] : '?';
            [$cip, $cpx] = tree_split_cidr($cstr, $family);
            [$start, $end] = tree_range($cip, $cpx, $family);

            // Overlap check against earlier siblings.
            foreach ($ranges as $prev) {
                if (tree_ranges_overlap($start, $end, $prev[0], $prev[1], $family)) {
                    throw new \InvalidArgumentException(
                        'tree_validate: child "' . $cstr . '" overlaps sibling "' . $prev[2] . '".'
                    );
                }
            }
            $ranges[] = [$start, $end, $cstr];

            tree_validate_node($child, $node, $family, $depth + 1, $count);
        }
    }
}

/**
 * Split "ip/prefix" and validate the IP belongs to the expected family.
 *
 * @return array{0:string,1:int}
 */
function tree_split_cidr(string $cidr, string $family): array
{
    $parts = explode('/', $cidr, 2);
    if (count($parts) !== 2) {
        throw new \InvalidArgumentException('tree_validate: cidr "' . $cidr . '" missing prefix.');
    }
    [$ip, $px_str] = $parts;
    if ($px_str === '' || !ctype_digit($px_str)) {
        throw new \InvalidArgumentException('tree_validate: cidr "' . $cidr . '" has non-numeric prefix.');
    }
    $px = (int)$px_str;

    if ($family === 'ipv4') {
        if (!is_valid_ipv4($ip)) {
            throw new \InvalidArgumentException('tree_validate: cidr "' . $cidr . '" is not a valid IPv4 address.');
        }
        if ($px < 0 || $px > 32) {
            throw new \InvalidArgumentException('tree_validate: IPv4 prefix /' . $px . ' out of range (0–32).');
        }
    } else {
        if (!is_valid_ipv6($ip)) {
            throw new \InvalidArgumentException('tree_validate: cidr "' . $cidr . '" is not a valid IPv6 address.');
        }
        if ($px < 0 || $px > 128) {
            throw new \InvalidArgumentException('tree_validate: IPv6 prefix /' . $px . ' out of range (0–128).');
        }
    }
    return [$ip, $px];
}

/**
 * Canonical CIDR string: host bits zeroed; IPv6 lowercased + compressed via
 * inet_ntop().
 */
function tree_canonical_cidr(string $ip, int $px, string $family): string
{
    if ($family === 'ipv4') {
        $long = ip2long($ip);
        if ($long === false) {
            throw new \InvalidArgumentException('tree_validate: ip2long failed for "' . $ip . '".');
        }
        $mask = $px === 0 ? 0 : ((~0 << (32 - $px)) & 0xFFFFFFFF);
        $net  = $long & $mask;
        $canon = long2ip($net & 0xFFFFFFFF);
        if (!is_string($canon)) {
            throw new \InvalidArgumentException('tree_validate: long2ip failed.');
        }
        return $canon . '/' . $px;
    }

    // IPv6: zero host bits via GMP.
    $bin = inet_pton($ip);
    if ($bin === false) {
        throw new \InvalidArgumentException('tree_validate: inet_pton failed for "' . $ip . '".');
    }
    $hex = bin2hex($bin);
    $g = gmp_init($hex, 16);
    if ($px < 128) {
        $shift = 128 - $px;
        $mask = gmp_mul(gmp_sub(gmp_pow(2, $px), 1), gmp_pow(2, $shift));
    } else {
        $mask = gmp_sub(gmp_pow(2, 128), 1);
    }
    $masked = gmp_and($g, $mask);
    $hexOut = str_pad(gmp_strval($masked, 16), 32, '0', STR_PAD_LEFT);
    $bin = hex2bin($hexOut);
    if ($bin === false) {
        throw new \InvalidArgumentException('tree_validate: hex2bin failed.');
    }
    $canon = inet_ntop($bin);
    if (!is_string($canon)) {
        throw new \InvalidArgumentException('tree_validate: inet_ntop failed.');
    }
    return $canon . '/' . $px;
}

/**
 * Return the [start, end] inclusive numeric range covered by a CIDR, as
 * either two ints (IPv4) or two GMP resources (IPv6).
 *
 * @return array{0:int|\GMP,1:int|\GMP}
 */
function tree_range(string $ip, int $px, string $family): array
{
    if ($family === 'ipv4') {
        $long = ip2long($ip) & 0xFFFFFFFF;
        $size = $px === 32 ? 1 : (1 << (32 - $px));
        return [$long, $long + $size - 1];
    }
    $g = ipv6_to_gmp($ip);
    $size = gmp_pow(2, 128 - $px);
    $end = gmp_sub(gmp_add($g, $size), 1);
    return [$g, $end];
}

/**
 * True when parent (pip/ppx) contains child IP.
 */
function tree_contains(string $pip, int $ppx, string $cip, string $family): bool
{
    if ($family === 'ipv4') {
        $pmask = $ppx === 0 ? 0 : ((~0 << (32 - $ppx)) & 0xFFFFFFFF);
        $pnet  = ip2long($pip) & $pmask;
        $cnet  = ip2long($cip) & $pmask;
        return $pnet === $cnet;
    }
    $pg = ipv6_to_gmp($pip);
    $cg = ipv6_to_gmp($cip);
    if ($ppx === 0) {
        return true;
    }
    $shift = 128 - $ppx;
    $mask = gmp_mul(gmp_sub(gmp_pow(2, $ppx), 1), gmp_pow(2, $shift));
    return gmp_cmp(gmp_and($pg, $mask), gmp_and($cg, $mask)) === 0;
}

/**
 * @param int|\GMP $a_start
 * @param int|\GMP $a_end
 * @param int|\GMP $b_start
 * @param int|\GMP $b_end
 */
function tree_ranges_overlap($a_start, $a_end, $b_start, $b_end, string $family): bool
{
    if ($family === 'ipv4') {
        return !($a_end < $b_start || $b_end < $a_start);
    }
    /** @var \GMP $a_start */
    /** @var \GMP $a_end */
    /** @var \GMP $b_start */
    /** @var \GMP $b_end */
    return !(gmp_cmp($a_end, $b_start) < 0 || gmp_cmp($b_end, $a_start) < 0);
}
