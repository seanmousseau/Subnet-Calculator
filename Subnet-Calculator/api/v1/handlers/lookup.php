<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

require_once dirname(__DIR__, 3) . '/includes/functions-lookup.php';

$body  = api_body();
$cidrs = $body['cidrs'] ?? null;
$ips   = $body['ips']   ?? null;

if (!is_array($cidrs) || count($cidrs) === 0) {
    json_err('Field "cidrs" must be a non-empty array of CIDR strings.');
}
if (!is_array($ips) || count($ips) === 0) {
    json_err('Field "ips" must be a non-empty array of IP strings.');
}

$max_cidrs = isset($GLOBALS['lookup_max_cidrs']) ? (int)$GLOBALS['lookup_max_cidrs'] : 100;
$max_ips   = isset($GLOBALS['lookup_max_ips'])   ? (int)$GLOBALS['lookup_max_ips']   : 1000;

if (count($cidrs) > $max_cidrs) {
    json_err('Too many CIDRs (max ' . $max_cidrs . ').');
}
if (count($ips) > $max_ips) {
    json_err('Too many IPs (max ' . $max_ips . ').');
}

$clean_cidrs = [];
foreach ($cidrs as $c) {
    if (!is_string($c) || trim($c) === '') {
        json_err('Each CIDR must be a non-empty string.');
    }
    $clean_cidrs[] = trim($c);
}

$clean_ips = [];
foreach ($ips as $i) {
    if (!is_string($i) || trim($i) === '') {
        json_err('Each IP must be a non-empty string.');
    }
    $clean_ips[] = trim($i);
}

try {
    $results = lookup_ips($clean_cidrs, $clean_ips);
} catch (InvalidArgumentException $e) {
    json_err($e->getMessage());
}

json_ok(['results' => $results]);
