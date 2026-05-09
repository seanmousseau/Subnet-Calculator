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
    $raw_rp_addr      = $body['rp_address']       ?? null;
    $raw_rp_plen      = $body['rp_prefix_length'] ?? null;
    $raw_riid         = $body['riid']             ?? null;
    $raw_scope        = $body['scope']            ?? null;
    $raw_group_id     = $body['group_id']         ?? null;

    if (!is_string($raw_rp_addr) || trim($raw_rp_addr) === '') {
        json_err('Field "rp_address" (string) is required for encode mode.', 400);
    }
    if (!is_int($raw_rp_plen) && !(is_string($raw_rp_plen) && ctype_digit($raw_rp_plen))) {
        json_err('Field "rp_prefix_length" (integer 0..64) is required for encode mode.', 400);
    }
    $rp_plen = (int)$raw_rp_plen;
    if (!is_int($raw_riid) && !(is_string($raw_riid) && ctype_digit($raw_riid))) {
        json_err('Field "riid" (integer 0..15) is required for encode mode.', 400);
    }
    $riid = (int)$raw_riid;
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
        $r = build_embedded_rp_group(trim($raw_rp_addr), $rp_plen, $riid, $scope, $group_id);
    } catch (\InvalidArgumentException $e) {
        json_err($e->getMessage(), 400);
    }

    json_ok([
        'mode'             => 'encode',
        'address'          => $r['address'],
        'scope'            => $r['scope'],
        'rp_prefix'        => $r['rp_prefix'],
        'rp_prefix_length' => $r['rp_prefix_length'],
        'rp_address'       => $r['rp_address'],
        'riid'             => $r['riid'],
        'group_id'         => $r['group_id'],
    ]);
}

// decode mode
$raw_input = $body['ipv6'] ?? null;
if (!is_string($raw_input) || trim($raw_input) === '') {
    json_err('Field "ipv6" (string) is required for decode mode.', 400);
}

try {
    $r = decode_embedded_rp_group(trim($raw_input));
} catch (\InvalidArgumentException $e) {
    json_err($e->getMessage(), 400);
}

json_ok([
    'mode'             => 'decode',
    'input'            => trim($raw_input),
    'address'          => $r['address'],
    'scope'            => $r['scope'],
    'rp_prefix'        => $r['rp_prefix'],
    'rp_prefix_length' => $r['rp_prefix_length'],
    'rp_address'       => $r['rp_address'],
    'riid'             => $r['riid'],
    'group_id'         => $r['group_id'],
]);
