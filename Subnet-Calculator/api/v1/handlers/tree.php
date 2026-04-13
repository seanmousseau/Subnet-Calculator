<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body     = api_body();
$parent   = trim((string)($body['parent']   ?? ''));
$children = $body['children'] ?? [];

if ($parent === '') {
    json_err('Field "parent" is required.');
}
if (!is_array($children)) {
    json_err('Field "children" must be an array.');
}
if (count($children) > 100) {
    json_err('Maximum 100 child CIDRs per request.');
}

$r = build_subnet_tree($parent, $children);

if (isset($r['error'])) {
    json_err($r['error']);
}

json_ok(['tree' => $r['tree'] ?? []]);
