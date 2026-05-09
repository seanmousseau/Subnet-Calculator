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

$rfc3531_cap = $GLOBALS['rfc3531_max_bits'] ?? 8;
if (!is_int($rfc3531_cap) || $rfc3531_cap < 1 || $rfc3531_cap > 12) {
    $rfc3531_cap = 8;
}

$raw_bits = $body['reservation_bits'] ?? null;
if (!is_int($raw_bits) || $raw_bits < 1 || $raw_bits > $rfc3531_cap) {
    json_err(
        sprintf('Field "reservation_bits" (integer 1..%d) is required.', $rfc3531_cap),
        400
    );
}

$raw_strategy = $body['strategy'] ?? 'centermost';
if (!is_string($raw_strategy) || !in_array($raw_strategy, ['leftmost', 'centermost', 'rightmost'], true)) {
    json_err('Field "strategy" must be one of "leftmost", "centermost", "rightmost".', 400);
}

try {
    $r = rfc3531_apply(trim($raw_parent), $raw_bits, $raw_strategy);
} catch (\InvalidArgumentException $e) {
    json_err($e->getMessage(), 400);
}

json_ok([
    'parent'           => $r['parent'],
    'strategy'         => $r['strategy'],
    'reservation_bits' => $r['reservation_bits'],
    'allocation_order' => $r['allocation_order'],
    'children'         => $r['children'],
]);
