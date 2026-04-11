<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body   = api_body();
$cidrs  = $body['cidrs']  ?? [];
$action = trim((string)($body['action'] ?? 'find'));

if (!in_array($action, ['find', 'summarise'], true)) {
    json_err('Field "action" must be "find" or "summarise".');
}
if (!is_array($cidrs) || count($cidrs) === 0) {
    json_err('Field "cidrs" must be a non-empty array.');
}
if (count($cidrs) > 50) {
    json_err('Maximum 50 CIDRs per request.');
}

$lines = array_values(array_filter(array_map(fn($c) => trim((string)$c), $cidrs)));

if (count($lines) === 0) {
    json_err('Field "cidrs" must contain at least one non-empty entry.');
}

if ($action === 'find') {
    $r = supernet_find($lines);
    if (isset($r['error'])) {
        json_err($r['error']);
    }
    json_ok(['supernet' => $r['supernet'] ?? '']);
} else {
    $r = summarise_cidrs($lines);
    if (isset($r['error'])) {
        json_err($r['error']);
    }
    json_ok(['summaries' => $r['summaries'] ?? []]);
}
