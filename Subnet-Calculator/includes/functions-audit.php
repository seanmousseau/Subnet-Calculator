<?php

declare(strict_types=1);

// Admin audit log (v3.0.0, #306).
//
// Persists a structured trail of security-relevant admin actions so an
// operator can answer "who did what, when, from where". All admin entry
// points (HTTP Basic auth result, key mint, key revoke, and PR2's wizard
// + TOTP events) call audit_log() through a single SQLite-backed table
// that lives in the same database as api_keys.
//
// Retention is bounded by $admin_audit_retention_days (default 90); rows
// older than the window are purged according to $admin_audit_purge_strategy
// (v3.1.0, #325): 'inline' (every write — legacy v3.0.0 behaviour),
// 'sampled' (probabilistic via $admin_audit_purge_sample_rate, default ~1
// in 1000 writes — current default) or 'cron' (no inline purge; operator
// runs bin/sc-audit-purge.php on a schedule).

const AUDIT_RETENTION_DEFAULT_DAYS = 90;
const AUDIT_ACTION_MAX             = 64;
const AUDIT_ACTOR_MAX              = 128;
const AUDIT_IP_MAX                 = 64;
const AUDIT_META_MAX               = 4096;

/**
 * Open (and migrate) the admin_audit table on the supplied database
 * handle. The handle is reused across calls — callers typically pass the
 * same SQLite3 instance returned from apikey_db_open() so all admin data
 * lives in one file.
 *
 * @throws \RuntimeException on schema failure
 */
function audit_db_init(\SQLite3 $db): void
{
    $db->exec(
        'CREATE TABLE IF NOT EXISTS admin_audit (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            ts        INTEGER NOT NULL,
            actor     TEXT,
            ip        TEXT,
            action    TEXT NOT NULL,
            target_id INTEGER,
            meta      TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_admin_audit_ts ON admin_audit(ts);
        CREATE INDEX IF NOT EXISTS idx_admin_audit_action ON admin_audit(action);'
    );
}

/**
 * Record an admin action.
 *
 * Best-effort: a write failure logs to error_log but does not throw — we
 * never want an audit failure to mask the underlying admin action (e.g.
 * a successful key revocation must complete even if the audit table is
 * locked by a concurrent writer).
 *
 * @param array<string, mixed>|null $meta  Action-specific JSON-serialisable detail.
 */
function audit_log(
    \SQLite3 $db,
    string $action,
    ?string $actor = null,
    ?string $ip = null,
    ?int $targetId = null,
    ?array $meta = null
): void {
    if ($action === '' || strlen($action) > AUDIT_ACTION_MAX) {
        error_log('sc audit_log: invalid action: ' . $action);
        return;
    }

    if ($actor !== null) {
        $actor = mb_substr($actor, 0, AUDIT_ACTOR_MAX);
    }
    if ($ip !== null) {
        $ip = substr($ip, 0, AUDIT_IP_MAX);
    }

    $metaJson = null;
    if ($meta !== null) {
        try {
            $metaJson = json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (strlen((string)$metaJson) > AUDIT_META_MAX) {
                $metaJson = json_encode(['truncated' => true], JSON_THROW_ON_ERROR);
            }
        } catch (\Throwable $e) {
            error_log('sc audit_log meta encode error: ' . $e->getMessage());
            $metaJson = null;
        }
    }

    try {
        audit_db_init($db);

        // v3.1.0 (#325) — purge dispatch. Reads the operator-configured
        // strategy via global, matching the $admin_audit_trust_xff pattern.
        // Unknown values fall back to 'sampled' (config sanitiser also
        // clamps, but we re-check here so direct unit-test callers that
        // bypass config.php still get safe behaviour).
        global $admin_audit_purge_strategy, $admin_audit_purge_sample_rate;
        $strategy = is_string($admin_audit_purge_strategy ?? null)
            ? $admin_audit_purge_strategy
            : 'sampled';
        switch ($strategy) {
            case 'inline':
                audit_purge($db);
                break;
            case 'cron':
                // No-op: operator runs bin/sc-audit-purge.php on a schedule.
                break;
            case 'sampled':
            default:
                $rate = is_numeric($admin_audit_purge_sample_rate ?? null)
                    ? (float)$admin_audit_purge_sample_rate
                    : 0.0;
                if ($rate > 0.0 && (mt_rand() / mt_getrandmax()) < $rate) {
                    audit_purge($db);
                }
                break;
        }

        $stmt = $db->prepare(
            'INSERT INTO admin_audit (ts, actor, ip, action, target_id, meta)
             VALUES (:ts, :actor, :ip, :action, :tid, :meta)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare admin_audit insert.');
        }
        $stmt->bindValue(':ts', time(), SQLITE3_INTEGER);
        $stmt->bindValue(':actor', $actor, $actor === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $stmt->bindValue(':ip', $ip, $ip === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $stmt->bindValue(':action', $action, SQLITE3_TEXT);
        $stmt->bindValue(':tid', $targetId, $targetId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(':meta', $metaJson, $metaJson === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $stmt->execute();
    } catch (\Throwable $e) {
        error_log('sc audit_log write error: ' . $e->getMessage());
    }
}

/**
 * List audit rows newest-first, with optional offset for pagination and
 * an optional action prefix filter (e.g. 'login.' surfaces only auth events).
 *
 * @return array<int, array{id:int, ts:int, actor:?string, ip:?string, action:string, target_id:?int, meta:?string}>
 * @throws \RuntimeException on read failure (callers should surface, not hide)
 */
function audit_list(\SQLite3 $db, int $limit = 50, int $offset = 0, ?string $actionPrefix = null): array
{
    audit_db_init($db);

    $limit  = max(1, min(500, $limit));
    $offset = max(0, $offset);

    if ($actionPrefix !== null && $actionPrefix !== '') {
        $stmt = $db->prepare(
            'SELECT id, ts, actor, ip, action, target_id, meta
             FROM admin_audit
             WHERE action LIKE :pfx
             ORDER BY id DESC
             LIMIT :lim OFFSET :off'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare admin_audit select.');
        }
        $stmt->bindValue(':pfx', $actionPrefix . '%', SQLITE3_TEXT);
    } else {
        $stmt = $db->prepare(
            'SELECT id, ts, actor, ip, action, target_id, meta
             FROM admin_audit
             ORDER BY id DESC
             LIMIT :lim OFFSET :off'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare admin_audit select.');
        }
    }
    $stmt->bindValue(':lim', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':off', $offset, SQLITE3_INTEGER);

    $res = $stmt->execute();
    if ($res === false) {
        throw new \RuntimeException('Failed to read admin_audit.');
    }

    $rows = [];
    while (($r = $res->fetchArray(SQLITE3_ASSOC)) !== false) {
        $rows[] = [
            'id'        => (int)$r['id'],
            'ts'        => (int)$r['ts'],
            'actor'     => $r['actor']  !== null ? (string)$r['actor']  : null,
            'ip'        => $r['ip']     !== null ? (string)$r['ip']     : null,
            'action'    => (string)$r['action'],
            'target_id' => $r['target_id'] !== null ? (int)$r['target_id'] : null,
            'meta'      => $r['meta']   !== null ? (string)$r['meta']   : null,
        ];
    }
    return $rows;
}

/**
 * Total row count for pagination footer.
 *
 * @throws \RuntimeException on read failure
 */
function audit_count(\SQLite3 $db, ?string $actionPrefix = null): int
{
    audit_db_init($db);

    if ($actionPrefix !== null && $actionPrefix !== '') {
        $stmt = $db->prepare('SELECT COUNT(*) FROM admin_audit WHERE action LIKE :pfx');
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare admin_audit count.');
        }
        $stmt->bindValue(':pfx', $actionPrefix . '%', SQLITE3_TEXT);
    } else {
        $stmt = $db->prepare('SELECT COUNT(*) FROM admin_audit');
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare admin_audit count.');
        }
    }
    $res = $stmt->execute();
    if ($res === false) {
        throw new \RuntimeException('Failed to count admin_audit.');
    }
    $row = $res->fetchArray(SQLITE3_NUM);
    return is_array($row) ? (int)$row[0] : 0;
}

/**
 * Drop rows older than the configured retention window. Resolves the
 * retention from the global $admin_audit_retention_days, falling back to
 * AUDIT_RETENTION_DEFAULT_DAYS. A retention of 0 disables purging
 * (operators who want to keep everything until they manually rotate).
 */
function audit_purge(\SQLite3 $db): void
{
    global $admin_audit_retention_days;

    $days = is_int($admin_audit_retention_days ?? null)
        ? $admin_audit_retention_days
        : AUDIT_RETENTION_DEFAULT_DAYS;
    if ($days <= 0) {
        return;
    }
    $cutoff = time() - ($days * 86400);
    $stmt = $db->prepare('DELETE FROM admin_audit WHERE ts < :c');
    if ($stmt === false) {
        return;
    }
    $stmt->bindValue(':c', $cutoff, SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * Convenience: derive the actor + IP that surrounding code will pass to
 * audit_log(). Centralised so a future header change (e.g. trusting an
 * X-Real-IP from a known-good reverse proxy) only happens here.
 */
function audit_actor_from_request(): ?string
{
    $u = $_SERVER['PHP_AUTH_USER'] ?? null;
    return is_string($u) && $u !== '' ? $u : null;
}

function audit_ip_from_request(): ?string
{
    // Audit IP must be the *direct* client unless the operator has explicitly
    // opted-in to a trusted-proxy header. Blindly trusting X-Forwarded-For
    // lets a direct caller spoof the recorded source IP.
    $remote = $_SERVER['REMOTE_ADDR'] ?? null;
    $remote = is_string($remote) && $remote !== '' ? trim($remote) : null;

    global $admin_audit_trust_xff;
    if (!empty($admin_audit_trust_xff)) {
        $xffRaw = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (is_string($xffRaw) && $xffRaw !== '') {
            $first = trim(explode(',', $xffRaw)[0]);
            if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }
    }

    return $remote !== null && filter_var($remote, FILTER_VALIDATE_IP) !== false
        ? $remote
        : $remote; // pass-through unfiltered fallback (Unix socket, etc.)
}
