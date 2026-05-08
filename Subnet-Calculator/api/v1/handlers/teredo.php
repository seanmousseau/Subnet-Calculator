<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body     = api_body();
$raw_mode = $body['mode'] ?? 'decode';
if (!is_string($raw_mode)) {
    json_err('Field "mode" must be a string ("encode" or "decode").', 400);
}
$mode = strtolower(trim($raw_mode));
if ($mode === '') {
    $mode = 'decode';
}
if ($mode !== 'encode' && $mode !== 'decode') {
    json_err('Field "mode" must be "encode" or "decode".', 400);
}

if ($mode === 'decode') {
    $raw_v6 = $body['ipv6'] ?? null;
    if (!is_string($raw_v6) || trim($raw_v6) === '') {
        json_err('Field "ipv6" (string) is required when mode=decode.', 400);
    }
    try {
        $decoded = decode_teredo(trim($raw_v6));
    } catch (\InvalidArgumentException $e) {
        json_err($e->getMessage(), 400);
    }
    json_ok([
        'mode'        => 'decode',
        'ipv6'        => trim($raw_v6),
        'server_ipv4' => $decoded['server_ipv4'],
        'flags'       => $decoded['flags'],
        'cone'        => $decoded['cone'],
        'port'        => $decoded['port'],
        'client_ipv4' => $decoded['client_ipv4'],
    ]);
}

// mode === 'encode'
$raw_server = $body['server_ipv4'] ?? null;
$raw_client = $body['client_ipv4'] ?? null;
$raw_port   = $body['port']        ?? null;
$raw_flags  = $body['flags']       ?? 0x8000;

if (!is_string($raw_server) || trim($raw_server) === '') {
    json_err('Field "server_ipv4" (string) is required when mode=encode.', 400);
}
if (!is_string($raw_client) || trim($raw_client) === '') {
    json_err('Field "client_ipv4" (string) is required when mode=encode.', 400);
}
if (!is_int($raw_port)) {
    json_err('Field "port" (integer 0..65535) is required when mode=encode.', 400);
}
if (!is_int($raw_flags)) {
    json_err('Field "flags" must be an integer (default 0x8000).', 400);
}
if ($raw_flags < 0 || $raw_flags > 0xFFFF) {
    json_err('Field "flags" must be in 16-bit range (0..65535).', 400);
}

try {
    $addr = encode_teredo(trim($raw_server), trim($raw_client), $raw_port, $raw_flags);
} catch (\InvalidArgumentException $e) {
    json_err($e->getMessage(), 400);
}

json_ok([
    'mode'        => 'encode',
    'server_ipv4' => trim($raw_server),
    'client_ipv4' => trim($raw_client),
    'port'        => $raw_port,
    'flags'       => $raw_flags & 0xFFFF,
    'cone'        => ($raw_flags & 0x8000) !== 0,
    'ipv6'        => $addr,
]);
