<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body     = api_body();
$raw_mode = $body['mode'] ?? 'encode';
$mode     = is_string($raw_mode) ? strtolower(trim($raw_mode)) : 'encode';
if ($mode !== 'encode' && $mode !== 'decode') {
    json_err('Field "mode" must be "encode" or "decode".', 400);
}

if ($mode === 'encode') {
    $raw_prefix   = $body['unicast_prefix'] ?? null;
    $raw_scope    = $body['scope']          ?? null;
    $raw_group_id = $body['group_id']       ?? null;

    if (!is_string($raw_prefix) || trim($raw_prefix) === '') {
        json_err('Field "unicast_prefix" (string) is required for encode mode.', 400);
    }
    if (!is_int($raw_scope) && !(is_string($raw_scope) && ctype_digit($raw_scope))) {
        json_err('Field "scope" (integer 1..15) is required for encode mode.', 400);
    }
    $scope = (int)$raw_scope;
    if (!is_int($raw_group_id) && !(is_string($raw_group_id) && preg_match('/^\d+$/', $raw_group_id))) {
        json_err('Field "group_id" (32-bit unsigned integer) is required for encode mode.', 400);
    }
    $group_id = (int)$raw_group_id;
    if ($group_id < 0 || $group_id > 0xFFFFFFFF) {
        json_err('Field "group_id" must be 0..2^32-1.', 400);
    }

    try {
        $r = build_ssm_group(trim($raw_prefix), $scope, $group_id);
    } catch (\InvalidArgumentException $e) {
        json_err($e->getMessage(), 400);
    }

    json_ok([
        'mode'           => 'encode',
        'address'        => $r['address'],
        'scope'          => $r['scope'],
        'prefix_length'  => $r['prefix_length'],
        'unicast_prefix' => $r['unicast_prefix'],
        'group_id'       => $r['group_id'],
    ]);
}

// decode mode
$raw_input = $body['ipv6'] ?? null;
if (!is_string($raw_input) || trim($raw_input) === '') {
    json_err('Field "ipv6" (string) is required for decode mode.', 400);
}

try {
    $r = decode_ssm_group(trim($raw_input));
} catch (\InvalidArgumentException $e) {
    json_err($e->getMessage(), 400);
}

json_ok([
    'mode'           => 'decode',
    'input'          => trim($raw_input),
    'address'        => $r['address'],
    'scope'          => $r['scope'],
    'prefix_length'  => $r['prefix_length'],
    'unicast_prefix' => $r['unicast_prefix'],
    'group_id'       => $r['group_id'],
]);
