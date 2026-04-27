<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body  = api_body();
$value = trim((string)($body['value'] ?? ''));

if ($value === '') {
    json_err('Field "value" is required.');
}

try {
    if (str_contains($value, '.')) {
        $cidr     = wildcard_to_cidr($value);
        $wildcard = $value;
    } else {
        $wildcard = cidr_to_wildcard($value);
        $cidr     = '/' . ltrim($value, '/');
    }
} catch (InvalidArgumentException $e) {
    json_err($e->getMessage());
}

json_ok([
    'input'    => $value,
    'cidr'     => $cidr,
    'wildcard' => $wildcard,
]);
