<?php

declare(strict_types=1);

// /api/v1/admin/keys (POST, GET) and /api/v1/admin/keys/{id} (DELETE).
//
// JSON CRUD parallel of admin/keys.php, gated by HTTP Basic Auth and the
// $admin_ui_enabled master switch. Lives behind the same allowlist so
// operators who turn admin off are protected from forgetting to lock
// down the JSON endpoints separately.

require_once dirname(__DIR__, 3) . '/includes/functions-admin-auth.php';
require_once dirname(__DIR__, 3) . '/includes/functions-audit.php';

// Override the api-level Bearer auth challenge with a JSON envelope.
admin_authenticate(static function (string $reason, int $status): void {
    if ($status === 401) {
        header('WWW-Authenticate: Basic realm="Subnet Calculator Admin", charset="UTF-8"');
    }
    json_err('Admin: ' . $reason, $status);
});

// Route parsing happens against $uri, which the router already normalised.
// Supported routes:
//   GET    /admin/keys
//   POST   /admin/keys
//   DELETE /admin/keys/{id}
//   PATCH  /admin/keys/{id}/rate-limit  -- v3.0.0 (#312)
$id            = null;
$is_rate_route = false;
if (preg_match('#^/admin/keys/(\d+)/rate-limit$#', $uri, $m)) {
    $id            = (int)$m[1];
    $is_rate_route = true;
} elseif (preg_match('#^/admin/keys/(\d+)$#', $uri, $m)) {
    $id = (int)$m[1];
}

// Open the SQLite-backed key store. Any DB failure here (or in the per-
// branch helpers below) escapes through the broader try/catch as a clean
// JSON 500 envelope rather than a raw PHP fatal.
try {
    $db_path = admin_apikey_db_path();
    $db      = apikey_db_open($db_path);

    if ($id === null && $uri === '/admin/keys' && $method === 'GET') {
        json_ok(['keys' => apikey_list($db)]);
    }

    if ($id === null && $uri === '/admin/keys' && $method === 'POST') {
        $body    = api_body();
        $rawName = $body['name'] ?? null;
        if (!is_string($rawName)) {
            json_err('Field "name" must be a string.', 400);
        }
        $rawRpm = $body['rate_limit_rpm'] ?? null;
        $rpm    = null;
        if ($rawRpm !== null) {
            if (!is_int($rawRpm) || $rawRpm < 0) {
                json_err('Field "rate_limit_rpm" must be null or a non-negative integer.', 400);
            }
            $rpm = $rawRpm;
        }
        try {
            $created = apikey_create($db, $rawName, $rpm);
        } catch (\InvalidArgumentException $e) {
            json_err($e->getMessage(), 400);
        }
        audit_log(
            $db,
            'key.mint',
            audit_actor_from_request(),
            audit_ip_from_request(),
            (int)$created['id'],
            ['name' => $created['name'], 'prefix' => $created['prefix'], 'rate_limit_rpm' => $rpm]
        );
        json_ok($created, 201);
    }

    if ($id !== null && $is_rate_route && $method === 'PATCH') {
        $body = api_body();
        // The body must explicitly contain rate_limit_rpm — `{}` previously
        // collapsed to "clear the override" via `?? null`, which let a
        // malformed client silently wipe a per-key limit.
        if (!is_array($body) || !array_key_exists('rate_limit_rpm', $body)) {
            json_err('Field "rate_limit_rpm" is required.', 400);
        }
        $rawRpm = $body['rate_limit_rpm'];
        $rpm    = null;
        if ($rawRpm !== null) {
            if (!is_int($rawRpm) || $rawRpm < 0) {
                json_err('Field "rate_limit_rpm" must be null or a non-negative integer.', 400);
            }
            $rpm = $rawRpm;
        }
        try {
            $changed = apikey_set_rate_limit($db, $id, $rpm);
        } catch (\InvalidArgumentException $e) {
            json_err($e->getMessage(), 400);
        }
        if (!$changed) {
            // Distinguish "not found" (key id absent or revoked) from "already
            // in this state" (idempotent no-op): the latter must be a 200,
            // not a 404 — clients re-issuing the same PATCH expect success.
            $exists = apikey_exists($db, $id);
            if (!$exists) {
                json_err('Key not found or already revoked.', 404);
            }
            // No-op: value already matches the request.
        }
        audit_log(
            $db,
            'key.rate_limit',
            audit_actor_from_request(),
            audit_ip_from_request(),
            $id,
            ['rate_limit_rpm' => $rpm]
        );
        json_ok(['id' => $id, 'rate_limit_rpm' => $rpm]);
    }

    if ($id !== null && !$is_rate_route && $method === 'DELETE') {
        $ok = apikey_revoke($db, $id);
        if (!$ok) {
            json_err('Key not found or already revoked.', 404);
        }
        audit_log(
            $db,
            'key.revoke',
            audit_actor_from_request(),
            audit_ip_from_request(),
            $id,
            null
        );
        json_ok(['id' => $id, 'revoked' => true]);
    }
} catch (\Throwable $e) {
    error_log('sc admin keys handler error: ' . $e->getMessage());
    json_err('Internal error processing the request.', 500);
}

json_err('Method not allowed.', 405);
