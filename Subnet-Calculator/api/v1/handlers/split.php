<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

// Determine IPv4 vs IPv6 from the URI path
$is_v6 = str_ends_with(rtrim($uri, '/'), '/ipv6');

$body   = api_body();
$limit  = (int)($body['limit'] ?? $split_max_subnets);
$limit  = max(1, min($limit, 256));

if ($is_v6) {
    if (!extension_loaded('gmp')) {
        json_err('IPv6 split requires the PHP GMP extension.', 500);
    }
    $ipv6   = trim((string)($body['ipv6']        ?? ''));
    $prefix = trim((string)($body['prefix']       ?? ''));
    $new_pfx = (int)($body['split_prefix'] ?? 0);
    if ($ipv6 === '') {
        json_err('Field "ipv6" is required.');
    }
    $r = resolve_ipv6_input($ipv6, $prefix);
    if (!$r['result6']) {
        json_err($r['error6'] ?? 'Invalid input.');
    }
    $current_pfx = (int)ltrim($r['result6']['prefix'], '/');
    if ($new_pfx <= $current_pfx || $new_pfx > 128) {
        json_err('split_prefix must be greater than the current prefix and <= 128.');
    }
    $network = explode('/', $r['result6']['network_cidr'])[0];
    $result  = split_subnet6($network, $current_pfx, $new_pfx, $limit);
    json_ok([
        'network'       => $r['result6']['network_cidr'],
        'split_prefix'  => '/' . $new_pfx,
        'total'         => $result['total'],
        'showing'       => $result['showing'],
        'subnets'       => $result['subnets'],
    ]);
} else {
    $ip      = trim((string)($body['ip']           ?? ''));
    $mask    = trim((string)($body['mask']          ?? ''));
    $new_pfx = (int)($body['split_prefix'] ?? 0);
    if ($ip === '') {
        json_err('Field "ip" is required.');
    }
    $r = resolve_ipv4_input($ip, $mask);
    if (!$r['result']) {
        json_err($r['error'] ?? 'Invalid input.');
    }
    $current_pfx = (int)ltrim($r['result']['netmask_cidr'], '/');
    if ($new_pfx <= $current_pfx || $new_pfx > 32) {
        json_err('split_prefix must be greater than the current prefix and <= 32.');
    }
    $network = explode('/', $r['result']['network_cidr'])[0];
    $result  = split_subnet($network, $current_pfx, $new_pfx, $limit);
    json_ok([
        'network'       => $r['result']['network_cidr'],
        'split_prefix'  => '/' . $new_pfx,
        'total'         => $result['total'],
        'showing'       => $result['showing'],
        'subnets'       => $result['subnets'],
    ]);
}
