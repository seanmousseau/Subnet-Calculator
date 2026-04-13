<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body  = api_body();
$start = trim((string)($body['start'] ?? ''));
$end   = trim((string)($body['end']   ?? ''));

if ($start === '') {
    json_err('Field "start" is required.');
}
if ($end === '') {
    json_err('Field "end" is required.');
}

$r = range_to_cidrs($start, $end);

if (isset($r['error'])) {
    json_err($r['error']);
}

json_ok(['cidrs' => $r['cidrs'] ?? []]);
