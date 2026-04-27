<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

require_once dirname(__DIR__, 3) . '/includes/functions-diff.php';

$body   = api_body();
$before = $body['before'] ?? null;
$after  = $body['after']  ?? null;

if (!is_array($before)) {
    json_err('Field "before" must be an array of CIDR strings.');
}
if (!is_array($after)) {
    json_err('Field "after" must be an array of CIDR strings.');
}

$max = 1000;
if ($before === [] && $after === []) {
    json_err('At least one of "before" or "after" must contain a CIDR.');
}
if (count($before) > $max) {
    json_err('Too many CIDRs in "before" (max ' . $max . ').');
}
if (count($after) > $max) {
    json_err('Too many CIDRs in "after" (max ' . $max . ').');
}

$clean_before = [];
foreach ($before as $c) {
    if (!is_string($c) || trim($c) === '') {
        json_err('Each "before" entry must be a non-empty string.');
    }
    $clean_before[] = trim($c);
}
$clean_after = [];
foreach ($after as $c) {
    if (!is_string($c) || trim($c) === '') {
        json_err('Each "after" entry must be a non-empty string.');
    }
    $clean_after[] = trim($c);
}

try {
    $diff = subnet_diff($clean_before, $clean_after);
} catch (InvalidArgumentException $e) {
    json_err($e->getMessage());
}

json_ok($diff);
