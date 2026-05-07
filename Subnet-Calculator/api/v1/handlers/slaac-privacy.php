<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body   = api_body();
$prefix = trim((string)($body['prefix'] ?? ''));

$seed = null;
if (array_key_exists('seed', $body) && $body['seed'] !== null) {
    $seed_raw = trim((string)$body['seed']);
    if ($seed_raw !== '') {
        $seed = $seed_raw;
    }
}

try {
    $r = slaac_privacy_address($prefix, $seed);
} catch (\InvalidArgumentException $e) {
    json_err($e->getMessage(), 400);
}

json_ok([
    'prefix'            => $r['prefix'],
    'address'           => $r['address'],
    'interface_id'      => $r['interface_id'],
    'seed_used'         => $r['seed_used'],
    'seed_was_provided' => $r['seed_was_provided'],
]);
