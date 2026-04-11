<?php

declare(strict_types=1);

/**
 * API v1 — shared helpers
 * This file must never be served directly (blocked by .htaccess FilesMatch).
 */

// ── Response helpers ──────────────────────────────────────────────────────────

/** @param array<mixed> $data */
function json_ok(array $data): never
{
    http_response_code(200);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_err(string $message, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── CORS ──────────────────────────────────────────────────────────────────────

function api_cors(): void
{
    global $api_cors_origins;
    $origin = (string)($api_cors_origins ?? '*');
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Max-Age: 86400');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// ── Authentication ────────────────────────────────────────────────────────────

function api_authenticate(): void
{
    global $api_tokens;
    if (!is_array($api_tokens) || $api_tokens === []) {
        return;
    }
    $authRaw = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    $auth    = is_string($authRaw) ? $authRaw : '';
    if (!str_starts_with($auth, 'Bearer ')) {
        json_err('Unauthorised — Bearer token required.', 401);
    }
    $token = substr($auth, 7);
    if (!in_array($token, $api_tokens, true)) {
        json_err('Unauthorised — invalid token.', 401);
    }
}

// ── Rate limiting ─────────────────────────────────────────────────────────────

function api_rate_limit(string $key): void
{
    global $api_rate_limit_rpm, $session_db_path;
    $rpm = (int)($api_rate_limit_rpm ?? 0);
    if ($rpm <= 0) {
        return;
    }
    $db_path = ($session_db_path !== '') ? $session_db_path
        : dirname(__DIR__, 2) . '/data/sessions.sqlite';
    try {
        $db_dir = dirname($db_path);
        if (!is_dir($db_dir)) {
            mkdir($db_dir, 0755, true);
        }
        $db = new \SQLite3($db_path);
        $db->enableExceptions(true);
        $db->busyTimeout(3000);
        $db->exec(
            'CREATE TABLE IF NOT EXISTS rate_limit (
                key    TEXT    NOT NULL,
                hit_at INTEGER NOT NULL
            )'
        );
        $db->exec('CREATE INDEX IF NOT EXISTS idx_rl_key ON rate_limit (key, hit_at)');
        $now    = time();
        $window = $now - 60;
        $del = $db->prepare('DELETE FROM rate_limit WHERE hit_at < :w');
        if ($del === false) {
            throw new \RuntimeException('Failed to prepare rate_limit delete.');
        }
        $del->bindValue(':w', $window, SQLITE3_INTEGER);
        $del->execute();
        $cnt = $db->prepare('SELECT COUNT(*) FROM rate_limit WHERE key = :k AND hit_at >= :w');
        if ($cnt === false) {
            throw new \RuntimeException('Failed to prepare rate_limit count.');
        }
        $cnt->bindValue(':k', $key, SQLITE3_TEXT);
        $cnt->bindValue(':w', $window, SQLITE3_INTEGER);
        $res   = $cnt->execute();
        $row   = ($res !== false) ? $res->fetchArray(SQLITE3_NUM) : false;
        $count = is_array($row) ? (int)$row[0] : 0;
        if ($count >= $rpm) {
            $db->close();
            header('Retry-After: 60');
            json_err('Rate limit exceeded — ' . $rpm . ' requests/minute.', 429);
        }
        $ins = $db->prepare('INSERT INTO rate_limit (key, hit_at) VALUES (:k, :t)');
        if ($ins === false) {
            throw new \RuntimeException('Failed to prepare rate_limit insert.');
        }
        $ins->bindValue(':k', $key, SQLITE3_TEXT);
        $ins->bindValue(':t', $now, SQLITE3_INTEGER);
        $ins->execute();
        $db->close();
    } catch (\Exception $e) {
        error_log('sc api rate-limit error: ' . $e->getMessage());
        // fail open
    }
}

// ── Request body ──────────────────────────────────────────────────────────────

/** @return array<mixed> */
function api_body(): array
{
    $raw = (string)file_get_contents('php://input');
    if ($raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_err('Invalid JSON body.');
    }
    return $data;
}

// ── Client key ────────────────────────────────────────────────────────────────

function api_client_key(): string
{
    $ipRaw = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    $ip    = is_string($ipRaw) ? $ipRaw : 'unknown';
    return trim(explode(',', $ip)[0]);
}
