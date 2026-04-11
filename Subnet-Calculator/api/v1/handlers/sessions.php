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
