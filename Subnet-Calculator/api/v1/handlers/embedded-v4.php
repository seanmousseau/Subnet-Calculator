<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body      = api_body();
$raw_input = $body['input'] ?? null;

if (!is_string($raw_input) || trim($raw_input) === '') {
    json_err('Field "input" (string) is required.', 400);
}
$input = trim($raw_input);

try {
    $detection = detect_embedded_v4($input);
} catch (\InvalidArgumentException $e) {
    json_err($e->getMessage(), 400);
}

json_ok([
    'input'        => $input,
    'scheme'       => $detection['scheme'],
    'ipv4'         => $detection['ipv4'],
    'deprecated'   => $detection['deprecated'],
    'detail_route' => $detection['detail_route'],
    'extra'        => $detection['extra'],
]);
