<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body = api_body();

// ── Multi-op mode (v3.4.0) ───────────────────────────────────────────────────
//
// When the request body contains an `items[]` array, dispatch each item to
// its corresponding op handler (range6, supernet6, zone-id, derive,
// slaac-privacy, rdns6, mapped6). Per-item errors are returned as
// `{op, ok: false, error}` envelopes; the request itself succeeds with HTTP
// 200 as long as the top-level shape is valid.
if (array_key_exists('items', $body)) {
    $items = $body['items'];
    if (!is_array($items) || count($items) === 0) {
        json_err('Field "items" must be a non-empty array.');
    }
    if (count($items) > 50) {
        json_err('Maximum 50 items per request.');
    }

    // Charge an additional rate-limit hit per item beyond the first (the router
    // already charged 1). Prevents 50× amplification of expensive ops via the
    // bulk endpoint. (v3.6.3, #427-M1)
    $extra = count($items) - 1;
    if ($extra > 0) {
        api_rate_limit(api_client_key(), $extra);
    }

    $gmp_loaded = extension_loaded('gmp');
    if (!$gmp_loaded) {
        json_err('The /bulk multi-op mode requires the PHP GMP extension.', 503);
    }

    json_ok(['results' => bulk_dispatch_ops(array_values($items))]);
}

// ── Legacy single-op mode (CIDR resolution) ──────────────────────────────────
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

// Charge per-item rate-limit (v3.6.3, #427-M1) — see note in items branch.
$extra = count($cidrs) - 1;
if ($extra > 0) {
    api_rate_limit(api_client_key(), $extra);
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
