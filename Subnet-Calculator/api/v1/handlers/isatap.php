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
    $globally_unique = null;
    if (array_key_exists('globally_unique', $body)) {
        if (!is_bool($body['globally_unique'])) {
            json_err('Field "globally_unique" must be a boolean if present.', 400);
        }
        $globally_unique = $body['globally_unique'];
    }
    try {
        $iid = ipv4_to_isatap_iid(trim($raw_v4), $globally_unique);
    } catch (\InvalidArgumentException $e) {
        json_err($e->getMessage(), 400);
    }
    // Re-derive globally_unique from the produced IID so the response is
    // consistent regardless of whether the caller supplied an explicit value.
    $decoded = decode_isatap_iid($iid);
    json_ok([
        'mode'            => 'encode',
        'ipv4'            => trim($raw_v4),
        'iid'             => $iid,
        'globally_unique' => $decoded['globally_unique'],
    ]);
}

// mode === 'decode'
$raw_iid = $body['iid'] ?? null;
if (!is_string($raw_iid) || trim($raw_iid) === '') {
    json_err('Field "iid" (string) is required when mode=decode.', 400);
}
try {
    $decoded = decode_isatap_iid(trim($raw_iid));
} catch (\InvalidArgumentException $e) {
    json_err($e->getMessage(), 400);
}
json_ok([
    'mode'            => 'decode',
    'iid'             => trim($raw_iid),
    'ipv4'            => $decoded['ipv4'],
    'globally_unique' => $decoded['globally_unique'],
]);
