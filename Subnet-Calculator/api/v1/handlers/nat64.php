<?php

declare(strict_types=1);

// POST /api/v1/nat64
//
// Modes:
//   - encode  : ipv4 + nat64_prefix + prefix_length → embedded IPv6
//   - decode  : ipv6 + nat64_prefix + prefix_length → IPv4
//   - dns64   : a_record (IPv4) + optional nat64_prefix/prefix_length → AAAA
//
// All three back the v3.5.0 NAT64 / DNS64 helper (RFC 6052 / 6147). The
// existing /api/v1/mapped6 endpoint is unchanged for backwards
// compatibility; nat64 is the prefix-length-aware superset.

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body     = api_body();
$raw_mode = $body['mode'] ?? 'encode';
if (!is_string($raw_mode)) {
    json_err('Field "mode" must be a string ("encode", "decode", or "dns64").', 400);
}
$mode = strtolower(trim($raw_mode));
if ($mode === '') {
    $mode = 'encode';
}
if ($mode !== 'encode' && $mode !== 'decode' && $mode !== 'dns64') {
    json_err('Field "mode" must be "encode", "decode", or "dns64".', 400);
}

$nat64_prefix  = NAT64_WELL_KNOWN_PREFIX;
$prefix_length = 96;
if (array_key_exists('nat64_prefix', $body) && $body['nat64_prefix'] !== null && $body['nat64_prefix'] !== '') {
    if (!is_string($body['nat64_prefix'])) {
        json_err('Field "nat64_prefix" must be a string.', 400);
    }
    $nat64_prefix = trim($body['nat64_prefix']);
}
if (array_key_exists('prefix_length', $body) && $body['prefix_length'] !== null) {
    if (!is_int($body['prefix_length'])) {
        json_err('Field "prefix_length" must be an integer (32, 40, 48, 56, 64, or 96).', 400);
    }
    $prefix_length = $body['prefix_length'];
}

// Enforce the advertised set at the handler layer rather than relying on
// nat64_embed/extract/dns64_synthesize to reject — single source of truth.
$valid_pls = [32, 40, 48, 56, 64, 96];
if (!in_array($prefix_length, $valid_pls, true)) {
    json_err('Field "prefix_length" must be one of {32, 40, 48, 56, 64, 96}.', 400);
}

try {
    if ($mode === 'encode' || $mode === 'dns64') {
        $field    = $mode === 'dns64' ? 'a_record' : 'ipv4';
        $raw      = $body[$field] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            json_err("Field \"{$field}\" (string) is required when mode={$mode}.", 400);
        }
        $v4    = trim($raw);
        $ipv6  = $mode === 'dns64'
            ? dns64_synthesize($v4, $nat64_prefix, $prefix_length)
            : nat64_embed($v4, $nat64_prefix, $prefix_length);
        json_ok([
            'mode'          => $mode,
            'nat64_prefix'  => $nat64_prefix,
            'prefix_length' => $prefix_length,
            'ipv4'          => $v4,
            'ipv6'          => $ipv6,
        ]);
    }

    // mode === 'decode'
    $raw = $body['ipv6'] ?? null;
    if (!is_string($raw) || trim($raw) === '') {
        json_err('Field "ipv6" (string) is required when mode=decode.', 400);
    }
    $addr = trim($raw);
    $v4   = nat64_extract($addr, $nat64_prefix, $prefix_length);
    json_ok([
        'mode'          => 'decode',
        'nat64_prefix'  => $nat64_prefix,
        'prefix_length' => $prefix_length,
        'ipv6'          => $addr,
        'ipv4'          => $v4,
    ]);
} catch (\InvalidArgumentException $e) {
    json_err($e->getMessage(), 400);
}
