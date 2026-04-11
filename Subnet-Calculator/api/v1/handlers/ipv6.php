<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

if (!extension_loaded('gmp')) {
    json_err('IPv6 calculation requires the PHP GMP extension.', 500);
}

$body   = api_body();
$ipv6   = trim((string)($body['ipv6']   ?? ''));
$prefix = trim((string)($body['prefix'] ?? ''));

if ($ipv6 === '') {
    json_err('Field "ipv6" is required.');
}

$r = resolve_ipv6_input($ipv6, $prefix);
if (!$r['result6']) {
    json_err($r['error6'] ?? 'Invalid input.');
}

json_ok($r['result6']);
