<?php

declare(strict_types=1);

/**
 * Multi-tree comparison / diff for the v3.0.0 tree editor (#322, v3.1.0).
 *
 * Pure function: walks two `type: 'tree'` payloads, canonicalises every CIDR
 * (via tree_canonical_cidr() from functions-tree.php #302), keys by canonical
 * CIDR's network address (the prefix is *not* part of the key — that lets us
 * detect "same network, different prefix" as a 'changed' entry instead of
 * add+remove), then computes set differences.
 *
 * The diff is the canonical reference; the matching client-side `treeDiff()`
 * in app.js mirrors this contract one-to-one.  No new API endpoint — this
 * helper exists for tests + future use.
 */

/**
 * Compute the difference between two tree payloads.
 *
 * @param array<string,mixed> $a Tree A (the "before" payload, includes 'type' and 'root').
 * @param array<string,mixed> $b Tree B (the "after" payload, includes 'type' and 'root').
 * @return array{
 *     added:   list<array{cidr:string,name?:string,notes?:string}>,
 *     removed: list<array{cidr:string,name?:string,notes?:string}>,
 *     changed: list<array{cidr:string,kind:string,before:mixed,after:mixed}>,
 * }
 * @throws \InvalidArgumentException If either payload fails tree_validate().
 */
function tree_diff(array $a, array $b): array
{
    // Canonicalise both trees first so that input like `10.0.0.5/24` is
    // normalised to `10.0.0.0/24` before tree_validate() (which rejects
    // non-canonical CIDRs by design).  This lets the diff treat such inputs
    // as identical instead of throwing.
    $a = tree_diff_canonicalise_payload($a);
    $b = tree_diff_canonicalise_payload($b);

    tree_validate($a);
    tree_validate($b);

    /** @var array<string,mixed> $a_root */
    $a_root = is_array($a['root'] ?? null) ? $a['root'] : [];
    /** @var array<string,mixed> $b_root */
    $b_root = is_array($b['root'] ?? null) ? $b['root'] : [];

    $a_family = tree_node_family($a_root);
    $b_family = tree_node_family($b_root);

    // Build canonical-CIDR-keyed maps (key = "ip/prefix"). This avoids
    // collisions when multiple nodes share a network address but differ in
    // prefix (e.g. root /24 and its first child /25 both at 10.0.0.0).
    $a_map = tree_diff_index($a_root, $a_family);
    $b_map = tree_diff_index($b_root, $b_family);

    $added   = [];
    $removed = [];
    $changed = [];

    // First pass: exact-CIDR matches (same network + same prefix). Detect
    // metadata-only changes (rename/notes).
    $a_unmatched = [];
    foreach ($a_map as $cidr => $a_node) {
        if (isset($b_map[$cidr])) {
            $b_node = $b_map[$cidr];
            if (($a_node['name'] ?? '') !== ($b_node['name'] ?? '')) {
                $changed[] = [
                    'cidr'   => $cidr,
                    'kind'   => 'rename',
                    'before' => (string)($a_node['name'] ?? ''),
                    'after'  => (string)($b_node['name'] ?? ''),
                ];
            }
            if (($a_node['notes'] ?? '') !== ($b_node['notes'] ?? '')) {
                $changed[] = [
                    'cidr'   => $cidr,
                    'kind'   => 'notes',
                    'before' => (string)($a_node['notes'] ?? ''),
                    'after'  => (string)($b_node['notes'] ?? ''),
                ];
            }
        } else {
            $a_unmatched[$cidr] = $a_node;
        }
    }
    $b_unmatched = [];
    foreach ($b_map as $cidr => $b_node) {
        if (!isset($a_map[$cidr])) {
            $b_unmatched[$cidr] = $b_node;
        }
    }

    // Second pass: "prefix changed" detection — a node present on both sides
    // with the same network address but different prefix length, where neither
    // exact-CIDR matched in the first pass.
    foreach ($a_unmatched as $a_cidr => $a_node) {
        $matched_b_cidr = null;
        foreach ($b_unmatched as $b_cidr => $b_node) {
            if ($a_node['network'] === $b_node['network']) {
                $matched_b_cidr = $b_cidr;
                break;
            }
        }
        if ($matched_b_cidr !== null) {
            $changed[] = [
                'cidr'   => $matched_b_cidr,
                'kind'   => 'prefix',
                'before' => $a_cidr,
                'after'  => $matched_b_cidr,
            ];
            // Cascade rename / notes on the same network if either side has them.
            $b_node = $b_unmatched[$matched_b_cidr];
            if (($a_node['name'] ?? '') !== ($b_node['name'] ?? '')) {
                $changed[] = [
                    'cidr'   => $matched_b_cidr,
                    'kind'   => 'rename',
                    'before' => (string)($a_node['name'] ?? ''),
                    'after'  => (string)($b_node['name'] ?? ''),
                ];
            }
            if (($a_node['notes'] ?? '') !== ($b_node['notes'] ?? '')) {
                $changed[] = [
                    'cidr'   => $matched_b_cidr,
                    'kind'   => 'notes',
                    'before' => (string)($a_node['notes'] ?? ''),
                    'after'  => (string)($b_node['notes'] ?? ''),
                ];
            }
            unset($b_unmatched[$matched_b_cidr]);
        } else {
            $removed[] = tree_diff_node_payload($a_node);
        }
    }
    foreach ($b_unmatched as $b_node) {
        $added[] = tree_diff_node_payload($b_node);
    }

    return [
        'added'   => $added,
        'removed' => $removed,
        'changed' => $changed,
    ];
}

/**
 * Walk a tree root and return a map keyed by canonical network address (no
 * prefix), so that prefix-only differences are detected as `changed` instead
 * of add+remove.
 *
 * @param array<string,mixed> $root
 * @param 'ipv4'|'ipv6' $family
 * @return array<string,array{cidr:string,prefix:int,name?:string,notes?:string,network:string}>
 */
function tree_diff_index(array $root, string $family): array
{
    $out = [];
    tree_diff_walk($root, $family, $out);
    return $out;
}

/**
 * @param array<array-key,mixed> $node
 * @param 'ipv4'|'ipv6' $family
 * @param array<string,array{cidr:string,prefix:int,name?:string,notes?:string,network:string}> $out
 */
function tree_diff_walk(array $node, string $family, array &$out): void
{
    $cidr = $node['cidr'] ?? null;
    if (!is_string($cidr)) {
        return;
    }
    $parts = explode('/', $cidr, 2);
    if (count($parts) !== 2) {
        return;
    }
    [$ip, $px_str] = $parts;
    $px = (int)$px_str;
    $canonical = tree_canonical_cidr($ip, $px, $family);
    [$net, ] = explode('/', $canonical, 2);

    $entry = [
        'cidr'    => $canonical,
        'prefix'  => $px,
        'network' => $net,
    ];
    $name = $node['name'] ?? null;
    if (is_string($name) && $name !== '') {
        $entry['name'] = $name;
    }
    $notes = $node['notes'] ?? null;
    if (is_string($notes) && $notes !== '') {
        $entry['notes'] = $notes;
    }
    $out[$canonical] = $entry;

    $children = $node['children'] ?? null;
    if (is_array($children)) {
        foreach ($children as $child) {
            if (is_array($child)) {
                tree_diff_walk($child, $family, $out);
            }
        }
    }
}

/**
 * Return a deep copy of $payload with every node's `cidr` replaced by its
 * canonical form for the payload's family.  Pre-validation step for tree_diff().
 *
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function tree_diff_canonicalise_payload(array $payload): array
{
    if (($payload['type'] ?? null) !== 'tree' || !isset($payload['root']) || !is_array($payload['root'])) {
        return $payload; // tree_validate() will throw with the proper message.
    }
    /** @var array<string,mixed> $root */
    $root = $payload['root'];
    if (!isset($root['cidr']) || !is_string($root['cidr']) || strpos($root['cidr'], '/') === false) {
        return $payload;
    }
    $family = strpos($root['cidr'], ':') !== false ? 'ipv6' : 'ipv4';
    $payload['root'] = tree_diff_canonicalise_node($root, $family);
    return $payload;
}

/**
 * @param array<array-key,mixed> $node
 * @param 'ipv4'|'ipv6' $family
 * @return array<array-key,mixed>
 */
function tree_diff_canonicalise_node(array $node, string $family): array
{
    $cidr = $node['cidr'] ?? null;
    if (is_string($cidr) && strpos($cidr, '/') !== false) {
        $parts = explode('/', $cidr, 2);
        if (count($parts) === 2 && ctype_digit($parts[1])) {
            try {
                $node['cidr'] = tree_canonical_cidr($parts[0], (int)$parts[1], $family);
            } catch (\InvalidArgumentException $e) {
                // Leave as-is; tree_validate() will surface the original error.
            }
        }
    }
    $children = $node['children'] ?? null;
    if (is_array($children)) {
        $kids = [];
        foreach ($children as $child) {
            if (is_array($child)) {
                $kids[] = tree_diff_canonicalise_node($child, $family);
            }
        }
        $node['children'] = $kids;
    }
    return $node;
}

/**
 * Strip internal indexing fields from a node entry before returning it as
 * part of an added/removed list.
 *
 * @param array{cidr:string,prefix:int,name?:string,notes?:string,network:string} $entry
 * @return array{cidr:string,name?:string,notes?:string}
 */
function tree_diff_node_payload(array $entry): array
{
    $payload = ['cidr' => $entry['cidr']];
    if (isset($entry['name'])) {
        $payload['name'] = $entry['name'];
    }
    if (isset($entry['notes'])) {
        $payload['notes'] = $entry['notes'];
    }
    return $payload;
}
