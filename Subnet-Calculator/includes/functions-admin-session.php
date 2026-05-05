<?php

/**
 * Admin cookie-session store + auth rate limiter (v3.2.0, #342).
 *
 * Replaces the HTTP Basic Auth flow on the web admin (/admin/*.php) with a
 * server-rendered login form. Sessions are stored in the same SQLite file
 * as api_keys / admin_audit (resolved via admin_apikey_db_path()).
 *
 * Two tables are introduced here:
 *
 *   admin_sessions    one row per active web session. session_id is a
 *                     256-bit URL-safe token sent in the sc_admin_sid
 *                     cookie. csrf is bound to the row and rotated on
 *                     promote() (TOTP success).
 *
 *   auth_rate_limit   failed-login counter, scoped by (ip, user). Backoff
 *                     escalates 1s -> 5s -> 30s -> 5min -> 30min -> 60min
 *                     once the threshold (3 failures) is crossed.
 *
 * The Basic Auth path used by /api/v1/admin/* is unchanged.
 */

declare(strict_types=1);

const ADMIN_SESSION_TTL          = 60 * 60 * 8;   // 8 hours, sliding via last_seen
const ADMIN_SESSION_COOKIE       = 'sc_admin_sid';
const ADMIN_RATE_LIMIT_THRESHOLD = 3;
/** @var array<int,int> Backoff seconds for the Nth failure beyond the threshold. */
const ADMIN_RATE_LIMIT_BACKOFFS = [1, 5, 30, 300, 1800, 3600];

function admin_session_db_init(\SQLite3 $db): void
{
    $db->exec(
        'CREATE TABLE IF NOT EXISTS admin_sessions (
            session_id   TEXT PRIMARY KEY,
            user         TEXT NOT NULL,
            created_at   INTEGER NOT NULL,
            last_seen    INTEGER NOT NULL,
            expires_at   INTEGER NOT NULL,
            totp_pending INTEGER NOT NULL DEFAULT 0,
            csrf         TEXT NOT NULL,
            ip           TEXT,
            user_agent   TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_admin_sessions_expires_at ON admin_sessions(expires_at);

        CREATE TABLE IF NOT EXISTS auth_rate_limit (
            ip       TEXT NOT NULL,
            user     TEXT NOT NULL,
            failures INTEGER NOT NULL DEFAULT 0,
            last_at  INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (ip, user)
        );'
    );
}

/**
 * @return array{session_id:string, user:string, csrf:string, totp_pending:int, expires_at:int}
 */
function admin_session_start(
    \SQLite3 $db,
    string $user,
    string $ip,
    string $userAgent,
    bool $totpPending
): array {
    admin_session_db_init($db);

    $sessionId = bin2hex(random_bytes(32));
    $csrf      = bin2hex(random_bytes(32));
    $now       = time();
    $expires   = $now + ADMIN_SESSION_TTL;
    $pending   = $totpPending ? 1 : 0;

    $stmt = $db->prepare(
        'INSERT INTO admin_sessions
            (session_id, user, created_at, last_seen, expires_at, totp_pending, csrf, ip, user_agent)
         VALUES (:sid, :u, :c, :c, :e, :tp, :csrf, :ip, :ua)'
    );
    if ($stmt === false) {
        throw new \RuntimeException('Failed to prepare admin_sessions insert.');
    }
    $stmt->bindValue(':sid', $sessionId, SQLITE3_TEXT);
    $stmt->bindValue(':u', $user, SQLITE3_TEXT);
    $stmt->bindValue(':c', $now, SQLITE3_INTEGER);
    $stmt->bindValue(':e', $expires, SQLITE3_INTEGER);
    $stmt->bindValue(':tp', $pending, SQLITE3_INTEGER);
    $stmt->bindValue(':csrf', $csrf, SQLITE3_TEXT);
    $stmt->bindValue(':ip', substr($ip, 0, 64), SQLITE3_TEXT);
    $stmt->bindValue(':ua', substr($userAgent, 0, 255), SQLITE3_TEXT);
    $stmt->execute();

    return [
        'session_id'   => $sessionId,
        'user'         => $user,
        'csrf'         => $csrf,
        'totp_pending' => $pending,
        'expires_at'   => $expires,
    ];
}

/**
 * @return array{session_id:string, user:string, csrf:string, totp_pending:int, expires_at:int}|null
 */
function admin_session_check(\SQLite3 $db, string $sessionId): ?array
{
    if ($sessionId === '') {
        return null;
    }
    admin_session_db_init($db);

    $stmt = $db->prepare(
        'SELECT session_id, user, csrf, totp_pending, expires_at
           FROM admin_sessions
          WHERE session_id = :sid
          LIMIT 1'
    );
    if ($stmt === false) {
        throw new \RuntimeException('Failed to prepare admin_sessions select.');
    }
    $stmt->bindValue(':sid', $sessionId, SQLITE3_TEXT);
    $res = $stmt->execute();
    if ($res === false) {
        return null;
    }
    $row = $res->fetchArray(SQLITE3_ASSOC);
    if ($row === false) {
        return null;
    }
    $expiresAt = is_numeric($row['expires_at'] ?? null) ? (int)$row['expires_at'] : 0;
    $now = time();
    if ($expiresAt < $now) {
        return null;
    }

    $bump = $db->prepare(
        'UPDATE admin_sessions
            SET last_seen = :ls, expires_at = :e
          WHERE session_id = :sid'
    );
    if ($bump !== false) {
        $bump->bindValue(':ls', $now, SQLITE3_INTEGER);
        $bump->bindValue(':e', $now + ADMIN_SESSION_TTL, SQLITE3_INTEGER);
        $bump->bindValue(':sid', $sessionId, SQLITE3_TEXT);
        $bump->execute();
    }

    $sidOut = is_string($row['session_id']   ?? null) ? (string)$row['session_id']  : '';
    $userOut = is_string($row['user']        ?? null) ? (string)$row['user']        : '';
    $csrfOut = is_string($row['csrf']        ?? null) ? (string)$row['csrf']        : '';
    $tpOut   = is_numeric($row['totp_pending'] ?? null) ? (int)$row['totp_pending'] : 0;

    return [
        'session_id'   => $sidOut,
        'user'         => $userOut,
        'csrf'         => $csrfOut,
        'totp_pending' => $tpOut,
        'expires_at'   => $now + ADMIN_SESSION_TTL,
    ];
}

function admin_session_promote(\SQLite3 $db, string $sessionId): void
{
    admin_session_db_init($db);
    $newCsrf = bin2hex(random_bytes(32));
    $stmt = $db->prepare(
        'UPDATE admin_sessions
            SET totp_pending = 0, csrf = :csrf
          WHERE session_id = :sid'
    );
    if ($stmt === false) {
        throw new \RuntimeException('Failed to prepare admin_sessions promote.');
    }
    $stmt->bindValue(':csrf', $newCsrf, SQLITE3_TEXT);
    $stmt->bindValue(':sid', $sessionId, SQLITE3_TEXT);
    $stmt->execute();
}

function admin_session_end(\SQLite3 $db, string $sessionId): void
{
    if ($sessionId === '') {
        return;
    }
    admin_session_db_init($db);
    $stmt = $db->prepare('DELETE FROM admin_sessions WHERE session_id = :sid');
    if ($stmt === false) {
        return;
    }
    $stmt->bindValue(':sid', $sessionId, SQLITE3_TEXT);
    $stmt->execute();
}

function admin_session_purge_expired(\SQLite3 $db): void
{
    admin_session_db_init($db);
    $stmt = $db->prepare('DELETE FROM admin_sessions WHERE expires_at < :now');
    if ($stmt === false) {
        return;
    }
    $stmt->bindValue(':now', time(), SQLITE3_INTEGER);
    $stmt->execute();
}

function admin_auth_rate_limit_check(\SQLite3 $db, string $ip, string $user): int
{
    admin_session_db_init($db);
    $stmt = $db->prepare(
        'SELECT failures, last_at FROM auth_rate_limit WHERE ip = :ip AND user = :u'
    );
    if ($stmt === false) {
        return 0;
    }
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    $stmt->bindValue(':u', $user, SQLITE3_TEXT);
    $res = $stmt->execute();
    if ($res === false) {
        return 0;
    }
    $row = $res->fetchArray(SQLITE3_ASSOC);
    if ($row === false) {
        return 0;
    }
    $failures = is_numeric($row['failures'] ?? null) ? (int)$row['failures'] : 0;
    if ($failures < ADMIN_RATE_LIMIT_THRESHOLD) {
        return 0;
    }
    $idx = min($failures - ADMIN_RATE_LIMIT_THRESHOLD, count(ADMIN_RATE_LIMIT_BACKOFFS) - 1);
    $backoff = ADMIN_RATE_LIMIT_BACKOFFS[$idx];
    $lastAt  = is_numeric($row['last_at'] ?? null) ? (int)$row['last_at'] : 0;
    $waited  = time() - $lastAt;
    $remain  = $backoff - $waited;
    return $remain > 0 ? $remain : 0;
}

function admin_auth_rate_limit_register_failure(\SQLite3 $db, string $ip, string $user): void
{
    admin_session_db_init($db);
    $now = time();
    $stmt = $db->prepare(
        'INSERT INTO auth_rate_limit (ip, user, failures, last_at)
              VALUES (:ip, :u, 1, :n)
         ON CONFLICT(ip, user) DO UPDATE SET
              failures = failures + 1,
              last_at  = :n'
    );
    if ($stmt === false) {
        return;
    }
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    $stmt->bindValue(':u', $user, SQLITE3_TEXT);
    $stmt->bindValue(':n', $now, SQLITE3_INTEGER);
    $stmt->execute();
}

function admin_auth_rate_limit_clear(\SQLite3 $db, string $ip, string $user): void
{
    admin_session_db_init($db);
    $stmt = $db->prepare('DELETE FROM auth_rate_limit WHERE ip = :ip AND user = :u');
    if ($stmt === false) {
        return;
    }
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    $stmt->bindValue(':u', $user, SQLITE3_TEXT);
    $stmt->execute();
}
