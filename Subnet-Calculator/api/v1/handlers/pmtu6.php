<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body = api_body();

if (!isset($body['path_mtu']) || !is_int($body['path_mtu'])) {
    json_err('Field "path_mtu" (positive integer) is required.', 400);
}
if (!isset($body['payload_size']) || !is_int($body['payload_size'])) {
    json_err('Field "payload_size" (non-negative integer) is required.', 400);
}

$path_mtu     = $body['path_mtu'];
$payload_size = $body['payload_size'];

$extension_headers = [];
if (isset($body['extension_headers'])) {
    if (!is_array($body['extension_headers'])) {
        json_err('Field "extension_headers" must be an array of integers.', 400);
    }
    foreach ($body['extension_headers'] as $ext) {
        if (!is_int($ext)) {
            json_err('Field "extension_headers" must be an array of integers.', 400);
        }
        $extension_headers[] = $ext;
    }
}

try {
    $r = pmtu_compute($path_mtu, $payload_size, $extension_headers);
} catch (\InvalidArgumentException $e) {
    json_err($e->getMessage(), 400);
}

json_ok([
    'path_mtu'            => $r['path_mtu'],
    'meets_minimum'       => $r['meets_minimum'],
    'fixed_header'        => $r['fixed_header'],
    'extension_overhead'  => $r['extension_overhead'],
    'total_overhead'      => $r['total_overhead'],
    'effective_payload'   => $r['effective_payload'],
    'payload_size'        => $r['payload_size'],
    'needs_fragmentation' => $r['needs_fragmentation'],
    'fragment_count'      => $r['fragment_count'],
    'fragments'           => $r['fragments'],
    'notes'               => $r['notes'],
]);
