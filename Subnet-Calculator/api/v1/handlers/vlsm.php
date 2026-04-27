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

$rv = resolve_ipv4_input($network, $cidr);
if (!$rv['result']) {
    json_err('Network: ' . ($rv['error'] ?? 'Invalid input.'));
}

$clean_reqs = [];
foreach ($reqs as $req) {
    if (!is_array($req)) {
        json_err('Each requirement must be an object with "name" and "hosts".');
    }
    $nameRaw  = $req['name']  ?? null;
    $hostsRaw = $req['hosts'] ?? null;

    if (!is_string($nameRaw)) {
        json_err('Each requirement needs a non-empty "name" up to 100 characters.');
    }
    $name = trim($nameRaw);

    if (mb_strlen($name) > 100) {
        json_err('Each requirement needs a non-empty "name" up to 100 characters.');
    }

    if (is_string($hostsRaw)) {
        $hostsRaw = trim($hostsRaw);
    }
    if (!is_int($hostsRaw) && !(is_string($hostsRaw) && ctype_digit($hostsRaw))) {
        json_err('Each requirement needs "hosts" >= 1.');
    }
    $hosts = (int)$hostsRaw;

    if ($name === '') {
        json_err('Each requirement needs a non-empty "name" and "hosts" >= 1.');
    }
    if ($hosts < 1) {
        json_err('Each requirement needs "hosts" >= 1.');
    }
    $clean_reqs[] = ['name' => $name, 'hosts' => $hosts];
}

$cidr_int    = (int)ltrim($rv['result']['netmask_cidr'], '/');
$network_ip  = explode('/', $rv['result']['network_cidr'])[0];
$vr = vlsm_allocate($network_ip, $cidr_int, $clean_reqs);

if (isset($vr['error'])) {
    json_err($vr['error']);
}

json_ok(['allocations' => $vr['allocations'] ?? []]);
