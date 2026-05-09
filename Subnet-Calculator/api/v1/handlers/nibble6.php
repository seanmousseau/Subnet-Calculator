<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body = api_body();

$raw_prefix = $body['prefix'] ?? null;
if (!is_string($raw_prefix) || trim($raw_prefix) === '') {
    json_err('Field "prefix" (string) is required, e.g. "2001:db8::/49".', 400);
}

try {
    $r = nibble_neighbours(trim($raw_prefix));
} catch (\InvalidArgumentException $e) {
    json_err($e->getMessage(), 400);
}

json_ok([
    'input' => $r['input'],
    'above' => $r['above'],
    'below' => $r['below'],
]);
