<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body = api_body();
$ip   = trim((string)($body['ip']   ?? ''));
$mask = trim((string)($body['mask'] ?? ''));

if ($ip === '') {
    json_err('Field "ip" is required.');
}

$r = resolve_ipv4_input($ip, $mask);
if (!$r['result']) {
    json_err($r['error'] ?? 'Invalid input.');
}

json_ok($r['result']);
