<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body = api_body();
$raw_prefix = $body['prefix'] ?? '';
if (!is_string($raw_prefix)) {
    json_err('Field "prefix" must be a string.', 400);
}
$prefix = trim($raw_prefix);

$seed = null;
if (array_key_exists('seed', $body) && $body['seed'] !== null) {
    if (!is_string($body['seed'])) {
        json_err('Field "seed" must be a string.', 400);
    }
    $seed_raw = trim($body['seed']);
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
