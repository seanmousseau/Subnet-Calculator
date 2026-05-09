<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body     = api_body();
$raw_mode = $body['mode'] ?? 'encode';
if (!is_string($raw_mode)) {
    json_err('Field "mode" must be a string ("encode" or "decode").', 400);
}
$mode = strtolower(trim($raw_mode));
if ($mode === '') {
    $mode = 'encode';
}
if ($mode !== 'encode' && $mode !== 'decode') {
    json_err('Field "mode" must be "encode" or "decode".', 400);
}

// Common SP parameters.
$raw_sp_prefix = $body['sp_ipv6_prefix'] ?? null;
if (!is_string($raw_sp_prefix) || trim($raw_sp_prefix) === '') {
    json_err('Field "sp_ipv6_prefix" (string) is required, e.g. "2001:db8::/32".', 400);
}
$sp_ipv4_mask_len = 0;
if (array_key_exists('sp_ipv4_mask_len', $body)) {
    $raw_mask = $body['sp_ipv4_mask_len'];
    if (!is_int($raw_mask)) {
        json_err('Field "sp_ipv4_mask_len" must be an integer 0..32.', 400);
    }
    if ($raw_mask < 0 || $raw_mask > 32) {
        json_err('Field "sp_ipv4_mask_len" must be 0..32.', 400);
    }
    $sp_ipv4_mask_len = $raw_mask;
}

if ($mode === 'encode') {
    $raw_v4 = $body['ipv4'] ?? null;
    if (!is_string($raw_v4) || trim($raw_v4) === '') {
        json_err('Field "ipv4" (string) is required when mode=encode.', 400);
    }
    try {
        $r = compute_6rd_delegation(trim($raw_sp_prefix), $sp_ipv4_mask_len, trim($raw_v4));
    } catch (\InvalidArgumentException $e) {
        json_err($e->getMessage(), 400);
    }
    json_ok([
        'mode'             => 'encode',
        'sp_ipv6_prefix'   => trim($raw_sp_prefix),
        'sp_ipv4_mask_len' => $sp_ipv4_mask_len,
        'ipv4'             => trim($raw_v4),
        'prefix'           => $r['prefix'],
        'prefix_length'    => $r['prefix_length'],
    ]);
}

// mode === 'decode'
$raw_addr = $body['ipv6'] ?? null;
if (!is_string($raw_addr) || trim($raw_addr) === '') {
    json_err('Field "ipv6" (string) is required when mode=decode.', 400);
}
try {
    $v4 = extract_6rd_ipv4(trim($raw_sp_prefix), $sp_ipv4_mask_len, trim($raw_addr));
} catch (\InvalidArgumentException $e) {
    json_err($e->getMessage(), 400);
}
json_ok([
    'mode'             => 'decode',
    'sp_ipv6_prefix'   => trim($raw_sp_prefix),
    'sp_ipv4_mask_len' => $sp_ipv4_mask_len,
    'ipv6'             => trim($raw_addr),
    'ipv4'             => $v4,
]);
