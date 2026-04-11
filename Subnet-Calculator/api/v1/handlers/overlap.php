<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body = api_body();
$a    = trim((string)($body['cidr_a'] ?? ''));
$b    = trim((string)($body['cidr_b'] ?? ''));

if ($a === '' || $b === '') {
    json_err('Fields "cidr_a" and "cidr_b" are required.');
}

// Detect IPv6 vs IPv4 by presence of ':'
$is_v6 = str_contains($a, ':') || str_contains($b, ':');

if ($is_v6) {
    if (!extension_loaded('gmp')) {
        json_err('IPv6 overlap check requires the PHP GMP extension.', 500);
    }
    [$a_ip, $a_pfx] = explode('/', $a) + [1 => ''];
    [$b_ip, $b_pfx] = explode('/', $b) + [1 => ''];
    if (!is_valid_ipv6($a_ip) || !ctype_digit($a_pfx)) {
        json_err('cidr_a is not a valid IPv6 CIDR.');
    }
    if (!is_valid_ipv6($b_ip) || !ctype_digit($b_pfx)) {
        json_err('cidr_b is not a valid IPv6 CIDR.');
    }
    try {
        $ra = calculate_subnet6($a_ip, (int)$a_pfx);
        $rb = calculate_subnet6($b_ip, (int)$b_pfx);
        $relation = cidrs_overlap6($ra['network_cidr'], $rb['network_cidr']);
    } catch (\Exception $e) {
        json_err('Calculation error: ' . $e->getMessage());
    }
} else {
    [$a_ip, $a_pfx] = explode('/', $a) + [1 => ''];
    [$b_ip, $b_pfx] = explode('/', $b) + [1 => ''];
    $ra = resolve_ipv4_input($a_ip, $a_pfx);
    $rb = resolve_ipv4_input($b_ip, $b_pfx);
    if (!$ra['result']) {
        json_err('cidr_a: ' . ($ra['error'] ?? 'Invalid input.'));
    }
    if (!$rb['result']) {
        json_err('cidr_b: ' . ($rb['error'] ?? 'Invalid input.'));
    }
    $relation = cidrs_overlap($ra['result']['network_cidr'], $rb['result']['network_cidr']);
}

json_ok(['cidr_a' => $a, 'cidr_b' => $b, 'relation' => $relation]);
