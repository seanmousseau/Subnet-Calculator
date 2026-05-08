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

if ($mode === 'encode') {
    $raw_v4 = $body['ipv4'] ?? null;
    if (!is_string($raw_v4) || trim($raw_v4) === '') {
        json_err('Field "ipv4" (string) is required when mode=encode.', 400);
    }
    try {
        $prefix = ipv4_to_6to4(trim($raw_v4));
    } catch (\InvalidArgumentException $e) {
        json_err($e->getMessage(), 400);
    }
    json_ok([
        'mode'   => 'encode',
        'ipv4'   => trim($raw_v4),
        'prefix' => $prefix,
    ]);
}

// mode === 'decode'
$raw_v6 = $body['ipv6'] ?? null;
if (!is_string($raw_v6) || trim($raw_v6) === '') {
    json_err('Field "ipv6" (string) is required when mode=decode.', 400);
}
try {
    $decoded = decode_6to4(trim($raw_v6));
} catch (\InvalidArgumentException $e) {
    json_err($e->getMessage(), 400);
}
json_ok([
    'mode'         => 'decode',
    'ipv6'         => trim($raw_v6),
    'ipv4'         => $decoded['ipv4'],
    'subnet_id'    => $decoded['subnet_id'],
    'interface_id' => $decoded['interface_id'],
]);
