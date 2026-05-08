<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body       = api_body();
$raw_addr   = $body['address'] ?? null;
$raw_prefix = $body['prefix']  ?? null;

if (!is_string($raw_addr) || trim($raw_addr) === '') {
    json_err('Field "address" (string) is required.', 400);
}
$address = trim($raw_addr);

$prefix = null;
if ($raw_prefix !== null && $raw_prefix !== '') {
    if (is_int($raw_prefix)) {
        $prefix = $raw_prefix;
    } elseif (is_string($raw_prefix) && preg_match('/^-?\d+$/', $raw_prefix) === 1) {
        $prefix = (int) $raw_prefix;
    } else {
        json_err('Field "prefix" must be an integer 0..128 (multiple of 4).', 400);
    }
}

try {
    $arpa = ipv6_to_arpa($address, $prefix);
} catch (\InvalidArgumentException $e) {
    json_err($e->getMessage(), 400);
}

json_ok([
    'address' => $address,
    'prefix'  => $prefix ?? 128,
    'arpa'    => $arpa,
]);
