<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body      = api_body();
$global_id = trim((string)($body['global_id'] ?? ''));

$r = generate_ula_prefix($global_id);
if (isset($r['error'])) {
    json_err($r['error']);
}

json_ok($r);
