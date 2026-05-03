<?php

declare(strict_types=1);

// /api/v1/admin/keys (POST, GET) and /api/v1/admin/keys/{id} (DELETE).
//
// JSON CRUD parallel of admin/keys.php, gated by HTTP Basic Auth and the
// $admin_ui_enabled master switch. Lives behind the same allowlist so
// operators who turn admin off are protected from forgetting to lock
// down the JSON endpoints separately.

require_once dirname(__DIR__, 3) . '/includes/functions-admin-auth.php';

// Override the api-level Bearer auth challenge with a JSON envelope.
admin_authenticate(static function (string $reason, int $status): void {
    if ($status === 401) {
        header('WWW-Authenticate: Basic realm="Subnet Calculator Admin", charset="UTF-8"');
    }
    json_err('Admin: ' . $reason, $status);
});

$db_path = admin_apikey_db_path();
$db      = apikey_db_open($db_path);

// Route parsing happens against $uri, which the router already normalised.
// Trailing /{id} extraction:
$id = null;
if (preg_match('#^/admin/keys/(\d+)$#', $uri, $m)) {
    $id = (int)$m[1];
}

if ($id === null && $uri === '/admin/keys' && $method === 'GET') {
    json_ok(['keys' => apikey_list($db)]);
}

if ($id === null && $uri === '/admin/keys' && $method === 'POST') {
    $body = api_body();
    $name = (string)($body['name'] ?? '');
    try {
        $created = apikey_create($db, $name);
    } catch (\InvalidArgumentException $e) {
        json_err($e->getMessage(), 400);
    } catch (\Throwable $e) {
        error_log('sc admin keys create: ' . $e->getMessage());
        json_err('Failed to create key.', 500);
    }
    http_response_code(201);
    echo json_encode(
        ['ok' => true, 'data' => $created],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

if ($id !== null && $method === 'DELETE') {
    $ok = apikey_revoke($db, $id);
    if (!$ok) {
        json_err('Key not found or already revoked.', 404);
    }
    json_ok(['id' => $id, 'revoked' => true]);
}

json_err('Method not allowed.', 405);
