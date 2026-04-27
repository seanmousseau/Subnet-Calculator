<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body    = api_body();
$network = trim((string)($body['network'] ?? ''));
$cidr    = trim((string)($body['cidr']    ?? ''));
$reqs    = $body['requirements'] ?? [];

if ($network === '') {
    json_err('Field "network" is required.');
}
if (!is_array($reqs) || count($reqs) === 0) {
    json_err('Field "requirements" must be a non-empty array.');
}

$rv = resolve_ipv6_input($network, $cidr);
if (!$rv['result6']) {
    json_err('Network: ' . ($rv['error6'] ?? 'Invalid input.'));
}

$clean_reqs = [];
foreach ($reqs as $req) {
    if (!is_array($req)) {
        json_err('Each requirement must be an object with "name" and "hosts".');
    }
    $name      = trim((string)($req['name'] ?? ''));
    $hosts_raw = $req['hosts'] ?? null;
    if ($name === '' || mb_strlen($name) > 100) {
        json_err('Each requirement needs a non-empty "name" up to 100 characters.');
    }
    if ($hosts_raw === null || $hosts_raw === '') {
        json_err('Each requirement needs a non-empty "name" and "hosts" >= 1.');
    }
    if (!is_int($hosts_raw) && !is_string($hosts_raw)) {
        json_err('Each "hosts" must be an integer or "2^N" string.');
    }
    $clean_reqs[] = ['name' => $name, 'hosts' => $hosts_raw];
}

$cidr_int   = (int)ltrim((string)$rv['result6']['prefix'], '/');
$network_ip = explode('/', (string)$rv['result6']['network_cidr'])[0];
$vr = vlsm6_allocate($network_ip, $cidr_int, $clean_reqs);

if (isset($vr['error'])) {
    json_err($vr['error']);
}

json_ok(['allocations' => $vr['allocations'] ?? []]);
