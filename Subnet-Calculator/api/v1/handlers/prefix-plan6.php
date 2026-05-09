<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body = api_body();

$raw_parent = $body['parent_prefix'] ?? null;
if (!is_string($raw_parent) || trim($raw_parent) === '') {
    json_err('Field "parent_prefix" (string) is required, e.g. "2001:db8::/48".', 400);
}

$raw_child_len = $body['child_length'] ?? null;
if (!is_int($raw_child_len)) {
    json_err('Field "child_length" (integer 1..128) is required.', 400);
}

$raw_count = $body['count'] ?? null;
if (!is_int($raw_count)) {
    json_err('Field "count" (positive integer) is required.', 400);
}

$start_offset = 0;
if (array_key_exists('start_offset', $body)) {
    $raw_offset = $body['start_offset'];
    if (!is_int($raw_offset)) {
        json_err('Field "start_offset" must be a non-negative integer.', 400);
    }
    if ($raw_offset < 0) {
        json_err('Field "start_offset" must be ≥ 0.', 400);
    }
    $start_offset = $raw_offset;
}

$nibble_align = true;
if (array_key_exists('nibble_align', $body)) {
    $raw_align = $body['nibble_align'];
    if (!is_bool($raw_align)) {
        json_err('Field "nibble_align" must be a boolean.', 400);
    }
    $nibble_align = $raw_align;
}

try {
    $r = plan_prefix_delegation(
        trim($raw_parent),
        $raw_child_len,
        $raw_count,
        $start_offset,
        $nibble_align
    );
} catch (\InvalidArgumentException $e) {
    json_err($e->getMessage(), 400);
}

json_ok([
    'parent'                  => $r['parent'],
    'children'                => $r['children'],
    'free'                    => $r['free'],
    'normalized_child_length' => $r['normalized_child_length'],
]);
