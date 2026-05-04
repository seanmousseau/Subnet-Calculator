<?php

declare(strict_types=1);

/**
 * API v1 — shared helpers
 * This file must never be served directly (blocked by .htaccess FilesMatch).
 */

// ── Response helpers ──────────────────────────────────────────────────────────

/**
 * @param  array<mixed> $data
 * @param  int          $status  HTTP status code (default 200; pass 201 for create endpoints).
 */
function json_ok(array $data, int $status = 200): never
{
    http_response_code($status);
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
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Max-Age: 86400');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// ── Authentication ────────────────────────────────────────────────────────────

/**
 * Resolve the SQLite path used for storing API keys.
 *
 * Mirrors admin_apikey_db_path() but lives here so api_authenticate() does
 * not require functions-admin-auth.php at API-bootstrap time.
 */
function api_apikey_db_path(): string
{
    global $apikey_db_path, $session_db_path;
    if (is_string($apikey_db_path ?? null) && $apikey_db_path !== '') {
        return $apikey_db_path;
    }
    if (is_string($session_db_path ?? null) && $session_db_path !== '') {
        return $session_db_path;
    }
    return dirname(__DIR__, 2) . '/data/sessions.sqlite';
}

/**
 * Cheap one-shot probe: does the api_keys table contain any active rows?
 *
 * Three outcomes:
 *   - File does not exist (no keys ever minted)        → false (open API).
 *   - File exists, table exists, ≥1 active row         → true (require auth).
 *   - File exists, table exists, 0 active rows         → false.
 *   - File exists but unreadable / corrupt / table     → fail closed:
 *     missing despite the file being there            json_err 503.
 *
 * Failing closed on a real DB error is critical: a perms regression or
 * disk corruption must NOT silently re-open the API to anonymous callers
 * just because the probe could not read the table.
 */
function api_sqlite_keys_present(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $path = api_apikey_db_path();
    if (!is_file($path)) {
        $cached = false;
        return false;
    }
    try {
        $db = new \SQLite3($path);
        $db->enableExceptions(true);
        $db->busyTimeout(1000);
        $res = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='api_keys'");
        if ($res === false) {
            throw new \RuntimeException('Failed to query sqlite_master.');
        }
        $has_table = $res->fetchArray(SQLITE3_ASSOC) !== false;
        if (!$has_table) {
            // File exists but no api_keys table — treat as no keys.
            // (e.g. only the rate_limit table is present in sessions.sqlite.)
            $db->close();
            $cached = false;
            return false;
        }
        $res = $db->query('SELECT 1 FROM api_keys WHERE revoked_at IS NULL LIMIT 1');
        if ($res === false) {
            throw new \RuntimeException('Failed to query api_keys.');
        }
        $cached = $res->fetchArray(SQLITE3_NUM) !== false;
        $db->close();
    } catch (\Throwable $e) {
        // Fail closed — log and reject the request rather than silently
        // re-opening the API. The DB file exists but we couldn't read it,
        // which is a real operator problem that needs surfacing.
        error_log('sc apikey probe error: ' . $e->getMessage());
        json_err('API key store unavailable.', 503);
    }
    return $cached;
}

/**
 * Stash the SQLite-verified key row so api_rate_limit() can read it
 * without re-running bcrypt. Returns the previously-stashed value when
 * called with no argument.
 *
 * @param  array<string, mixed>|null $row
 * @return array<string, mixed>|null
 */
function api_verified_sqlite_key(?array $row = null): ?array
{
    static $stash = null;
    if (func_num_args() > 0) {
        $stash = $row;
    }
    return $stash;
}

function api_authenticate(): void
{
    global $api_tokens;

    $static_tokens_active = is_array($api_tokens) && $api_tokens !== [];
    $sqlite_keys_active   = api_sqlite_keys_present();

    // No auth configured — preserves the open-by-default behaviour. When the
    // admin UI is enabled but no keys have been minted yet, the API stays
    // open until the operator either sets $api_tokens or mints a key.
    if (!$static_tokens_active && !$sqlite_keys_active) {
        return;
    }

    $authRaw = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    $auth    = is_string($authRaw) ? $authRaw : '';
    if (!str_starts_with($auth, 'Bearer ')) {
        json_err('Unauthorised — Bearer token required.', 401);
    }
    $token = substr($auth, 7);

    if ($static_tokens_active && in_array($token, $api_tokens, true)) {
        return;
    }

    if ($sqlite_keys_active && function_exists('apikey_verify')) {
        $db_path = api_apikey_db_path();
        try {
            $db = apikey_db_open($db_path);
            $row = apikey_verify($db, $token);
            if ($row !== null) {
                // Stash the verified row for api_rate_limit() so it can
                // honour the per-key rate_limit_rpm override (#312) without
                // re-running bcrypt. Cleared at the end of the request by
                // PHP's normal teardown — statics live for one request only.
                api_verified_sqlite_key($row);

                // Stat update is best-effort: a transient lock or perms blip
                // here must NOT reject a token that just verified. Log and
                // proceed so the caller sees a 200 with a stale last_used_at.
                try {
                    apikey_record_use($db, (int)$row['id']);
                } catch (\Throwable $e) {
                    error_log('sc apikey_record_use error: ' . $e->getMessage());
                }
                $db->close();
                return;
            }
            $db->close();
        } catch (\Throwable $e) {
            error_log('sc apikey verify error: ' . $e->getMessage());
            // fail closed — fall through to 401
        }
    }

    json_err('Unauthorised — invalid token.', 401);
}

// ── Rate limiting ─────────────────────────────────────────────────────────────

function api_rate_limit(string $key): void
{
    global $api_rate_limit_rpm, $api_rate_limit_tokens, $api_tokens, $session_db_path;

    // Effective RPM lookup order (first match wins):
    //   1. Static $api_rate_limit_tokens[token] override (operator-edited config)
    //   2. SQLite api_keys.rate_limit_rpm override (#312, set via /admin/keys.php)
    //   3. Global $api_rate_limit_rpm fallback
    // An RPM of 0 at any tier means "unlimited" for that match.
    $rpm    = (int)($api_rate_limit_rpm ?? 0);
    $rl_key = $key; // default: key by IP

    $authRaw = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    $auth    = is_string($authRaw) ? $authRaw : '';
    $token   = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';

    // Tier 1 — static map from $api_tokens
    $matched = false;
    if (
        $token !== ''
        && is_array($api_tokens) && $api_tokens !== []
        && is_array($api_rate_limit_tokens) && $api_rate_limit_tokens !== []
        && in_array($token, $api_tokens, true)
        && array_key_exists($token, $api_rate_limit_tokens)
    ) {
        $raw_rpm = $api_rate_limit_tokens[$token];
        if (is_numeric($raw_rpm)) {
            $token_rpm = (int)$raw_rpm;
            $matched   = true;
            if ($token_rpm === 0) {
                return; // explicit 0 = unlimited for this token
            }
            $rpm    = $token_rpm;
            $rl_key = 'tok:' . hash('sha256', $token);
        }
        // non-numeric entry: ignore, fall through to next tier
    }

    // Tier 2 — SQLite per-key override (#312). Only applies when api_authenticate()
    // already verified the bearer token via apikey_verify() and stashed the row.
    if (!$matched && $token !== '' && function_exists('api_verified_sqlite_key')) {
        $verified = api_verified_sqlite_key();
        if (
            is_array($verified)
            && isset($verified['rate_limit_rpm']) && is_int($verified['rate_limit_rpm'])
            && isset($verified['id'])             && is_int($verified['id'])
        ) {
            $sqlite_rpm = $verified['rate_limit_rpm'];
            $matched    = true;
            if ($sqlite_rpm === 0) {
                return; // 0 = unlimited for this key
            }
            $rpm    = $sqlite_rpm;
            // Bucket by key id, not token plaintext — stable across tokens
            // sharing a prefix and avoids hashing the token here.
            $rl_key = 'key:' . $verified['id'];
        }
    }

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
        $reset  = $now + 60;
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
        $cnt->bindValue(':k', $rl_key, SQLITE3_TEXT);
        $cnt->bindValue(':w', $window, SQLITE3_INTEGER);
        $res   = $cnt->execute();
        $row   = ($res !== false) ? $res->fetchArray(SQLITE3_NUM) : false;
        $count = is_array($row) ? (int)$row[0] : 0;
        // Per RFC draft-ietf-httpapi-ratelimit-headers; remaining is computed
        // before the request is recorded (so the count includes the previous
        // requests but not this one yet — match standard semantics).
        $remaining = max(0, $rpm - $count - 1);
        header('X-RateLimit-Limit: ' . $rpm);
        header('X-RateLimit-Remaining: ' . $remaining);
        header('X-RateLimit-Reset: ' . $reset);
        if ($count >= $rpm) {
            $db->close();
            header('X-RateLimit-Remaining: 0');
            header('Retry-After: 60');
            json_err('Rate limit exceeded — ' . $rpm . ' requests/minute.', 429);
        }
        $ins = $db->prepare('INSERT INTO rate_limit (key, hit_at) VALUES (:k, :t)');
        if ($ins === false) {
            throw new \RuntimeException('Failed to prepare rate_limit insert.');
        }
        $ins->bindValue(':k', $rl_key, SQLITE3_TEXT);
        $ins->bindValue(':t', $now, SQLITE3_INTEGER);
        $ins->execute();
        $db->close();
    } catch (\Throwable $e) {
        error_log('sc api rate-limit error: ' . $e->getMessage());
        // fail open
    }
}

// ── API request logging ───────────────────────────────────────────────────────

function api_log_request(string $key, string $endpoint, string $method): void
{
    global $api_request_log_db_path;

    $db_path = ($api_request_log_db_path !== '') ? $api_request_log_db_path
        : dirname(__DIR__, 2) . '/data/api_requests.sqlite';
    try {
        $db_dir = dirname($db_path);
        if (!is_dir($db_dir)) {
            mkdir($db_dir, 0755, true);
        }
        $db = new \SQLite3($db_path);
        $db->enableExceptions(true);
        $db->busyTimeout(3000);
        $db->exec(
            'CREATE TABLE IF NOT EXISTS api_requests (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                endpoint     TEXT    NOT NULL,
                method       TEXT    NOT NULL,
                client_key   TEXT    NOT NULL,
                requested_at INTEGER NOT NULL
            )'
        );
        $ins = $db->prepare(
            'INSERT INTO api_requests (endpoint, method, client_key, requested_at)
             VALUES (:ep, :m, :k, :t)'
        );
        if ($ins === false) {
            throw new \RuntimeException('Failed to prepare api_requests insert.');
        }
        $ins->bindValue(':ep', $endpoint, SQLITE3_TEXT);
        $ins->bindValue(':m', $method, SQLITE3_TEXT);
        $ins->bindValue(':k', $key, SQLITE3_TEXT);
        $ins->bindValue(':t', time(), SQLITE3_INTEGER);
        $ins->execute();
        $db->close();
    } catch (\Throwable $e) {
        error_log('sc api request-log error: ' . $e->getMessage());
        // fail open
    }
}

// ── Deprecation headers ───────────────────────────────────────────────────────

function api_deprecation_headers(string $sunset_date, string $link = ''): void
{
    header('Sunset: ' . $sunset_date);
    header('Deprecation: true');
    if ($link !== '') {
        header('Link: <' . $link . '>; rel="deprecation"');
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
