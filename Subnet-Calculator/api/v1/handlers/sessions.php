<?php

declare(strict_types=1);

if (!$session_enabled) {
    json_err('Session persistence is not enabled on this server.', 501);
}

$db_path = ($session_db_path !== '') ? $session_db_path
    : dirname(__DIR__, 3) . '/data/sessions.sqlite';

// GET /sessions/{id} — load a session
if ($method === 'GET') {
    $load_id = trim((string)($_GET['session_id'] ?? ''));
    if (!preg_match('/^[0-9a-f]{8}$/', $load_id)) {
        json_err('Invalid session ID format.');
    }
    if (!file_exists($db_path)) {
        json_err('Session not found or expired.', 404);
    }
    try {
        $db      = session_db_open($db_path);
        $payload = session_load($db, $load_id);
        $db->close();
    } catch (\Exception $e) {
        error_log('sc api session load error: ' . $e->getMessage());
        json_err('Failed to load session.', 500);
    }
    if ($payload === null) {
        json_err('Session not found or expired.', 404);
    }
    json_ok(['id' => $load_id, 'payload' => $payload]);
}

// POST /sessions — create a session
if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body    = api_body();
$payload = $body['payload'] ?? null;

if (!is_array($payload) || $payload === []) {
    json_err('Field "payload" must be a non-empty object.');
}

// v3.0.0 (#315) — validate the type discriminator. Missing `type` defaults
// to 'ipv4' for back-compat with v2 callers. Unknown types are rejected.
$type = $payload['type'] ?? 'ipv4';
if (!is_string($type) || !in_array($type, ['ipv4', 'ipv6', 'tree'], true)) {
    json_err('Field "type" must be one of: ipv4, ipv6, tree.');
}
$payload['type'] = $type; // normalise so the loaded session always has it

if ($type === 'ipv4' || $type === 'ipv6') {
    foreach (['network', 'cidr', 'requirements'] as $required) {
        if (!array_key_exists($required, $payload)) {
            json_err('Missing required field: ' . $required);
        }
    }
    if (!is_array($payload['requirements']) || $payload['requirements'] === []) {
        json_err('Field "requirements" must be a non-empty array.');
    }
    foreach ($payload['requirements'] as $i => $req) {
        if (!is_array($req) || !isset($req['name'], $req['hosts'])) {
            json_err("requirements[$i] must have name and hosts.");
        }
        // IPv6 'hosts' may be the "2^N" string form; ipv4 must be int.
        if ($type === 'ipv4' && !is_int($req['hosts'])) {
            json_err("requirements[$i].hosts must be an integer for ipv4.");
        }
        if (
            $type === 'ipv6'
            && !is_int($req['hosts'])
            && !(
                is_string($req['hosts'])
                && preg_match('/^2\^([0-9]|[1-9][0-9]|1[01][0-9]|12[0-8])$/', $req['hosts'])
            )
        ) {
            json_err("requirements[$i].hosts must be a positive integer or the string \"2^N\" with N in 0–128.");
        }
    }
} elseif ($type === 'tree') {
    // v3.0.0 PR3 (#302): full structural + semantic validation via
    // tree_validate(). Throws \InvalidArgumentException on the first rule
    // violation — message is safe to surface to the API caller.
    try {
        tree_validate($payload);
    } catch (\InvalidArgumentException $e) {
        json_err($e->getMessage());
    }
}

$db_dir = dirname($db_path);
if (!is_dir($db_dir)) {
    mkdir($db_dir, 0755, true);
}

try {
    $db     = session_db_open($db_path);
    $new_id = session_create($db, $payload, $session_ttl_days);
    $db->close();
} catch (\Exception $e) {
    error_log('sc api session create error: ' . $e->getMessage());
    json_err('Failed to save session.', 500);
}

http_response_code(201);
echo json_encode(['ok' => true, 'data' => ['id' => $new_id]], JSON_UNESCAPED_UNICODE);
exit;
