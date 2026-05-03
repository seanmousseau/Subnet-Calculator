<?php

declare(strict_types=1);

// SQLite-backed API key management (v2.12.0, #297).
//
// Tokens are minted as `sk_live_` + 32 hex characters and stored only as a
// bcrypt hash (PASSWORD_BCRYPT). The first 8 hex chars are also stored in a
// non-secret `prefix` column so the admin UI can identify keys without
// touching the hash. The plaintext token is shown to the operator exactly
// once at creation.
//
// The verify path looks the prefix up first to keep the bcrypt cost bounded
// regardless of how many keys exist.

const APIKEY_TOKEN_PREFIX  = 'sk_live_';
const APIKEY_RAND_HEX_LEN  = 32; // 16 random bytes, hex-encoded
const APIKEY_PUBLIC_PREFIX = 8;  // first 8 of the hex tail shown in the UI
const APIKEY_NAME_MAX      = 128;

/**
 * Open (and migrate) the SQLite database used for API keys.
 *
 * @throws \RuntimeException if the schema cannot be applied
 */
function apikey_db_open(string $path): \SQLite3
{
    $db = new \SQLite3($path);
    $db->enableExceptions(true);
    $db->busyTimeout(3000);

    $db->exec(
        'CREATE TABLE IF NOT EXISTS api_keys (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            name         TEXT    NOT NULL,
            prefix       TEXT    NOT NULL,
            token_hash   TEXT    NOT NULL,
            created_at   INTEGER NOT NULL,
            last_used_at INTEGER,
            revoked_at   INTEGER
        );
        CREATE INDEX IF NOT EXISTS idx_api_keys_prefix ON api_keys(prefix);'
    );

    return $db;
}

/**
 * Mint a new API key.
 *
 * Returns ['id' => int, 'token' => string, 'prefix' => string]. The plaintext
 * token is only ever returned from this function — it is never readable from
 * the database after this point.
 *
 * @return array{id:int, token:string, prefix:string, name:string, created_at:int}
 * @throws InvalidArgumentException on empty / oversized name
 * @throws \RuntimeException        on prepare failure
 *
 * Note: PHP 8.0+ password_hash() always returns a string for valid inputs
 * (the historical `false` return was removed); we therefore do not guard
 * against a hash failure on the happy path.
 */
function apikey_create(\SQLite3 $db, string $name): array
{
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('API key name is required.');
    }
    if (strlen($name) > APIKEY_NAME_MAX) {
        throw new InvalidArgumentException('API key name exceeds ' . APIKEY_NAME_MAX . ' characters.');
    }

    $hex    = bin2hex(random_bytes(APIKEY_RAND_HEX_LEN / 2));
    $token  = APIKEY_TOKEN_PREFIX . $hex;
    $prefix = substr($hex, 0, APIKEY_PUBLIC_PREFIX);
    $hash = password_hash($token, PASSWORD_BCRYPT);
    $now  = time();
    $stmt = $db->prepare(
        'INSERT INTO api_keys (name, prefix, token_hash, created_at)
         VALUES (:name, :prefix, :hash, :ts)'
    );
    if ($stmt === false) {
        throw new \RuntimeException('Failed to prepare insert.');
    }
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':prefix', $prefix, SQLITE3_TEXT);
    $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
    $stmt->bindValue(':ts', $now, SQLITE3_INTEGER);
    $stmt->execute();

    return [
        'id'         => (int)$db->lastInsertRowID(),
        'token'      => $token,
        'prefix'     => $prefix,
        'name'       => $name,
        'created_at' => $now,
    ];
}

/**
 * Verify a presented Bearer token against the api_keys table.
 *
 * Returns the matching row's safe fields when the token is valid and active;
 * null otherwise. Implementation intentionally probes every active key with
 * a matching prefix so that prefix collisions (extremely unlikely but
 * possible after manual edits) still resolve correctly.
 *
 * @return array{id:int, name:string, prefix:string, created_at:int, last_used_at:?int}|null
 */
function apikey_verify(\SQLite3 $db, string $token): ?array
{
    if (!str_starts_with($token, APIKEY_TOKEN_PREFIX)) {
        return null;
    }
    $hex = substr($token, strlen(APIKEY_TOKEN_PREFIX));
    if (!preg_match('/^[a-f0-9]{' . APIKEY_RAND_HEX_LEN . '}$/', $hex)) {
        return null;
    }
    $prefix = substr($hex, 0, APIKEY_PUBLIC_PREFIX);

    $stmt = $db->prepare(
        'SELECT id, name, prefix, token_hash, created_at, last_used_at
         FROM api_keys
         WHERE prefix = :p AND revoked_at IS NULL'
    );
    if ($stmt === false) {
        return null;
    }
    $stmt->bindValue(':p', $prefix, SQLITE3_TEXT);
    $res = $stmt->execute();
    if ($res === false) {
        return null;
    }
    while (($row = $res->fetchArray(SQLITE3_ASSOC)) !== false) {
        if (password_verify($token, (string)$row['token_hash'])) {
            return [
                'id'           => (int)$row['id'],
                'name'         => (string)$row['name'],
                'prefix'       => (string)$row['prefix'],
                'created_at'   => (int)$row['created_at'],
                'last_used_at' => $row['last_used_at'] !== null ? (int)$row['last_used_at'] : null,
            ];
        }
    }
    return null;
}

/**
 * List API keys (newest first) without ever returning the hash.
 *
 * @return array<int, array{id:int, name:string, prefix:string, created_at:int, last_used_at:?int, revoked_at:?int}>
 */
function apikey_list(\SQLite3 $db): array
{
    // Throws \SQLite3Exception on prepare/execute failure (db has
    // enableExceptions(true) set in apikey_db_open). Callers must
    // surface errors — silently returning [] would mask DB failures.
    $res = $db->query(
        'SELECT id, name, prefix, created_at, last_used_at, revoked_at
         FROM api_keys
         ORDER BY id DESC'
    );
    if ($res === false) {
        throw new \RuntimeException('Failed to list API keys.');
    }
    $rows = [];
    while (($r = $res->fetchArray(SQLITE3_ASSOC)) !== false) {
        $rows[] = [
            'id'           => (int)$r['id'],
            'name'         => (string)$r['name'],
            'prefix'       => (string)$r['prefix'],
            'created_at'   => (int)$r['created_at'],
            'last_used_at' => $r['last_used_at'] !== null ? (int)$r['last_used_at'] : null,
            'revoked_at'   => $r['revoked_at']   !== null ? (int)$r['revoked_at']   : null,
        ];
    }
    return $rows;
}

/**
 * Mark an API key as revoked. Returns true if the row existed and was active,
 * false if it did not exist or was already revoked. Throws \RuntimeException
 * on DB error so callers can distinguish "no such key" (false) from
 * "DB failure" (exception) — important on a security-sensitive admin screen.
 */
function apikey_revoke(\SQLite3 $db, int $id): bool
{
    $stmt = $db->prepare(
        'UPDATE api_keys
         SET revoked_at = :ts
         WHERE id = :id AND revoked_at IS NULL'
    );
    if ($stmt === false) {
        throw new \RuntimeException('Failed to prepare revoke statement.');
    }
    $stmt->bindValue(':ts', time(), SQLITE3_INTEGER);
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
    return $db->changes() > 0;
}

/**
 * Update last_used_at to the current time. Best-effort — silently no-op
 * if the row no longer exists.
 */
function apikey_record_use(\SQLite3 $db, int $id): void
{
    $stmt = $db->prepare('UPDATE api_keys SET last_used_at = :ts WHERE id = :id');
    if ($stmt === false) {
        return;
    }
    $stmt->bindValue(':ts', time(), SQLITE3_INTEGER);
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
}
