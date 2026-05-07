<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body = api_body();
$raw_input = $body['input'] ?? '';
if (!is_string($raw_input)) {
    json_err('Field "input" must be a string.', 400);
}
$input = trim($raw_input);

try {
    $r = parse_zone_id($input);
} catch (\InvalidArgumentException $e) {
    json_err($e->getMessage(), 400);
}

json_ok([
    'address'       => $r['address'],
    'zone_id'       => $r['zone_id'],
    'is_link_local' => $r['is_link_local'],
    'warning'       => $r['warning'],
]);
