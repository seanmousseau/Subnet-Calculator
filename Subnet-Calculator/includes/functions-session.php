<?php

declare(strict_types=1);

// ─── Session persistence (SQLite) ─────────────────────────────────────────────

/**
 * Open (and initialise) the sessions SQLite database.
 *
 * Creates the schema on first run.  Throws \Exception if the file cannot be
 * created or opened.
 *
 * @throws \Exception on open / schema failure
 */
function session_db_open(string $path): \SQLite3
{
    $db = new \SQLite3($path);
    $db->enableExceptions(true);
    $db->busyTimeout(3000);

    $db->exec(
        'CREATE TABLE IF NOT EXISTS sessions (
            id         TEXT    NOT NULL PRIMARY KEY,
            payload    TEXT    NOT NULL,
            created_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions (expires_at);'
    );

    return $db;
}

/**
 * Prepare a statement and throw \RuntimeException if it fails.
 *
 * @throws \RuntimeException
 */
function session_prepare(\SQLite3 $db, string $sql): \SQLite3Stmt
{
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        throw new \RuntimeException('Failed to prepare SQLite statement.');
    }
    return $stmt;
}

/**
 * Create a new session and return its 8-character hex ID.
 *
 * @param  array<mixed>  $payload
 * @throws \Exception on database failure
 */
function session_create(\SQLite3 $db, array $payload, int $ttl_days): string
{
    session_purge($db);

    // Generate a unique 8-character hex ID
    do {
        $id   = bin2hex(random_bytes(4));
        $stmt = session_prepare($db, 'SELECT 1 FROM sessions WHERE id = :id');
        $stmt->bindValue(':id', $id, SQLITE3_TEXT);
        $result = $stmt->execute();
        $exists = $result !== false && $result->fetchArray(SQLITE3_NUM) !== false;
    } while ($exists);

    $now     = time();
    $expires = $now + ($ttl_days * 86400);
    $json    = json_encode($payload, JSON_THROW_ON_ERROR);

    $stmt = session_prepare(
        $db,
        'INSERT INTO sessions (id, payload, created_at, expires_at)
         VALUES (:id, :payload, :created_at, :expires_at)'
    );
    $stmt->bindValue(':id', $id, SQLITE3_TEXT);
    $stmt->bindValue(':payload', $json, SQLITE3_TEXT);
    $stmt->bindValue(':created_at', $now, SQLITE3_INTEGER);
    $stmt->bindValue(':expires_at', $expires, SQLITE3_INTEGER);
    $stmt->execute();

    return $id;
}

/**
 * Load a session payload by ID.  Returns null if not found or expired.
 *
 * @return array<mixed>|null
 * @throws \Exception on database failure
 */
function session_load(\SQLite3 $db, string $id): ?array
{
    $stmt = session_prepare(
        $db,
        'SELECT payload FROM sessions WHERE id = :id AND expires_at > :now'
    );
    $stmt->bindValue(':id', $id, SQLITE3_TEXT);
    $stmt->bindValue(':now', time(), SQLITE3_INTEGER);
    $result = $stmt->execute();

    if ($result === false) {
        return null;
    }

    $row = $result->fetchArray(SQLITE3_ASSOC);
    if ($row === false) {
        return null;
    }

    $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
    return is_array($payload) ? $payload : null;
}

/**
 * Delete all sessions whose expiry timestamp is in the past.
 *
 * @throws \Exception on database failure
 */
function session_purge(\SQLite3 $db): void
{
    $stmt = session_prepare($db, 'DELETE FROM sessions WHERE expires_at <= :now');
    $stmt->bindValue(':now', time(), SQLITE3_INTEGER);
    $stmt->execute();
}
