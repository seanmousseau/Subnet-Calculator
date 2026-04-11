<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body  = api_body();
$cidrs = $body['cidrs'] ?? [];
$type  = trim((string)($body['type'] ?? 'auto'));

if (!in_array($type, ['ipv4', 'ipv6', 'auto'], true)) {
    json_err('Field "type" must be "ipv4", "ipv6", or "auto".');
}
if (!is_array($cidrs) || count($cidrs) === 0) {
    json_err('Field "cidrs" must be a non-empty array.');
}
if (count($cidrs) > 50) {
    json_err('Maximum 50 CIDRs per request.');
}

$gmp_loaded = extension_loaded('gmp');
$results    = [];

foreach ($cidrs as $raw) {
    $cidr = trim((string)$raw);

    if ($cidr === '') {
        $results[] = ['input' => '', 'ok' => false, 'error' => 'Empty input.'];
        continue;
    }

    // Determine IP version: explicit type wins; auto-detects on colon presence
    $is_ipv6 = $type === 'ipv6' || ($type === 'auto' && str_contains($cidr, ':'));

    if ($is_ipv6) {
        if (!$gmp_loaded) {
            $results[] = [
                'input' => $cidr,
                'ok'    => false,
                'error' => 'IPv6 requires the PHP GMP extension.',
            ];
            continue;
        }
        $r = resolve_ipv6_input($cidr, '');
        if (!$r['result6']) {
            $results[] = [
                'input' => $cidr,
                'ok'    => false,
                'error' => $r['error6'] ?? 'Invalid IPv6 input.',
            ];
        } else {
            $results[] = [
                'input' => $cidr,
                'ok'    => true,
                'data'  => $r['result6'],
            ];
        }
    } else {
        $r = resolve_ipv4_input($cidr, '');
        if (!$r['result']) {
            $results[] = [
                'input' => $cidr,
                'ok'    => false,
                'error' => $r['error'] ?? 'Invalid IPv4 input.',
            ];
        } else {
            $results[] = [
                'input' => $cidr,
                'ok'    => true,
                'data'  => $r['result'],
            ];
        }
    }
}

json_ok(['results' => $results]);
