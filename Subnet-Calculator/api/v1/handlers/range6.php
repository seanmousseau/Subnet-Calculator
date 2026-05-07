<?php

declare(strict_types=1);

// POST /api/v1/range6 — IPv6 inclusive range → minimal CIDR list (v3.3.0).
// Body: { "start": "2001:db8::", "end": "2001:db8::ffff" }
// Response: { ok: true, cidrs, count, total_addresses, truncated, cap }

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body = api_body();
$raw_start = $body['start'] ?? '';
$raw_end   = $body['end']   ?? '';
if (!is_string($raw_start)) {
    json_err('Field "start" must be a string.', 400);
}
if (!is_string($raw_end)) {
    json_err('Field "end" must be a string.', 400);
}
$start = trim($raw_start);
$end   = trim($raw_end);

if ($start === '') {
    json_err('Field "start" is required.');
}
if ($end === '') {
    json_err('Field "end" is required.');
}

$r = range6_to_cidrs($start, $end);

if (isset($r['error'])) {
    json_err($r['error']);
}

json_ok([
    'cidrs'           => $r['cidrs'] ?? [],
    'count'           => $r['count'] ?? 0,
    'total_addresses' => $r['total_addresses'] ?? 0,
    'truncated'       => $r['truncated'] ?? false,
    'cap'             => $r['cap'] ?? 256,
]);
