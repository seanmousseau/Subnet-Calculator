<?php

declare(strict_types=1);

// Admin TOTP / 2FA + recovery codes (v3.0.0, #313).
//
// Pure-PHP RFC 6238 (HOTP under the hood, RFC 4226). No new runtime
// dependencies — TOTP is computed with hash_hmac('sha1') against a
// 30-second time step, ±1 step drift on verify, 6 digits, base32 secret.
//
// Operators enable TOTP by setting $admin_totp_secret to a base32 string
// (e.g. via /admin/totp.php "Enable" flow). Verification happens after
// password verify in admin_authenticate(): API callers must supply
// X-Admin-TOTP, UI users complete a step-up form whose result is cached
// in the PHP session for ADMIN_TOTP_SESSION_TTL seconds.
//
// Recovery codes are stored in admin_recovery_codes (one row per code,
// bcrypt-hashed plaintext, used_at marker). 10 codes are minted at TOTP
// enable time and shown once. A user who has lost their authenticator
// can substitute a recovery code for the TOTP step; each code is single
// use and audit-logs totp.recovery.use.

const ADMIN_TOTP_DIGITS         = 6;
const ADMIN_TOTP_PERIOD         = 30;     // seconds per RFC 6238 step
const ADMIN_TOTP_DRIFT_STEPS    = 1;      // accept ±1 step (60s window total)
const ADMIN_TOTP_SECRET_BYTES   = 20;     // 160-bit shared secret (matches HMAC-SHA1 block)
const ADMIN_TOTP_SESSION_TTL    = 1800;   // 30 min UI step-up cache
const ADMIN_RECOVERY_CODE_COUNT = 10;
const ADMIN_RECOVERY_CODE_BYTES = 5;      // 40 bits → 8 base32 chars per code

/**
 * Whether the operator has TOTP configured. False = TOTP disabled, no
 * step-up required.
 */
function admin_totp_enabled(): bool
{
    global $admin_totp_secret;
    return is_string($admin_totp_secret ?? null) && $admin_totp_secret !== '';
}

/**
 * Generate a fresh TOTP secret as a base32 string (RFC 4648 alphabet,
 * no padding). 160 bits matches HMAC-SHA1's natural block size and is
 * what Google Authenticator / 1Password expect.
 */
function admin_totp_generate_secret(): string
{
    return admin_totp_base32_encode(random_bytes(ADMIN_TOTP_SECRET_BYTES));
}

/**
 * Build the otpauth:// URI a TOTP authenticator app consumes via QR code.
 *
 * issuer: typically the host name; embedded as both the URI label prefix
 * and the issuer query parameter — apps display the issuer alongside the
 * account name so users with multiple admin panels can tell them apart.
 */
function admin_totp_provisioning_uri(string $secret, string $account, string $issuer): string
{
    $label = rawurlencode($issuer) . ':' . rawurlencode($account);
    $params = http_build_query([
        'secret'    => $secret,
        'issuer'    => $issuer,
        'algorithm' => 'SHA1',
        'digits'    => (string)ADMIN_TOTP_DIGITS,
        'period'    => (string)ADMIN_TOTP_PERIOD,
    ]);
    return 'otpauth://totp/' . $label . '?' . $params;
}

/**
 * Constant-time-ish verify of a 6-digit TOTP code against a base32 secret.
 *
 * Accepts ±ADMIN_TOTP_DRIFT_STEPS to tolerate authenticator/server clock
 * skew. Internally compares each candidate via hash_equals.
 *
 * Returns true if any candidate matches; false otherwise. Strips spaces
 * from the user-submitted code (some apps copy with embedded spaces).
 */
function admin_totp_verify(string $secret, string $code, ?int $now = null): bool
{
    $code = preg_replace('/\\s+/', '', $code) ?? '';
    if (!preg_match('/^\\d{' . ADMIN_TOTP_DIGITS . '}$/', $code)) {
        return false;
    }
    $bin = admin_totp_base32_decode($secret);
    if ($bin === null || $bin === '') {
        return false;
    }

    $now ??= time();
    $step = (int)floor($now / ADMIN_TOTP_PERIOD);

    $matched = false;
    for ($drift = -ADMIN_TOTP_DRIFT_STEPS; $drift <= ADMIN_TOTP_DRIFT_STEPS; $drift++) {
        $candidate = admin_totp_compute($bin, $step + $drift);
        // hash_equals on every iteration so we don't short-circuit timing.
        if (hash_equals($candidate, $code)) {
            $matched = true;
        }
    }
    return $matched;
}

/**
 * Compute the 6-digit TOTP code for a given binary key and step counter,
 * implementing the HOTP truncation from RFC 4226 §5.3.
 */
function admin_totp_compute(string $binSecret, int $step): string
{
    // 8-byte big-endian counter.
    $counter = pack('N*', 0, $step);
    $hash = hash_hmac('sha1', $counter, $binSecret, true);
    $offset = ord($hash[19]) & 0x0f;
    $truncated = ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff);
    $code = $truncated % (10 ** ADMIN_TOTP_DIGITS);
    return str_pad((string)$code, ADMIN_TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/**
 * RFC 4648 base32 encode (no padding). Alphabet: A–Z + 2–7.
 */
function admin_totp_base32_encode(string $raw): string
{
    if ($raw === '') {
        return '';
    }
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits     = '';
    for ($i = 0, $n = strlen($raw); $i < $n; $i++) {
        $bits .= str_pad(decbin(ord($raw[$i])), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        if ($chunk === '') {
            continue;
        }
        $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $out  .= $alphabet[(int)bindec($chunk)];
    }
    return $out;
}

/**
 * RFC 4648 base32 decode. Tolerant of lower-case, whitespace and `=`
 * padding; rejects anything else as null.
 */
function admin_totp_base32_decode(string $encoded): ?string
{
    $encoded = strtoupper(preg_replace('/\\s|=/', '', $encoded) ?? '');
    if ($encoded === '') {
        return null;
    }
    if (preg_match('/[^A-Z2-7]/', $encoded)) {
        return null;
    }
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits     = '';
    for ($i = 0, $n = strlen($encoded); $i < $n; $i++) {
        $idx = strpos($alphabet, $encoded[$i]);
        if ($idx === false) {
            return null;
        }
        $bits .= str_pad(decbin($idx), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr((int)bindec($byte));
        }
    }
    return $out;
}

// ─── Recovery codes ──────────────────────────────────────────────────────────

/**
 * Schema migration for the admin_recovery_codes table. Idempotent.
 */
function admin_recovery_db_init(\SQLite3 $db): void
{
    $db->exec(
        'CREATE TABLE IF NOT EXISTS admin_recovery_codes (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            code_hash TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            used_at   INTEGER NULL
        );
        CREATE INDEX IF NOT EXISTS idx_admin_recovery_used ON admin_recovery_codes(used_at);'
    );
}

/**
 * Format a single recovery code: 8 base32 chars split as 4-4 for legibility
 * (operators are usually copying these into a password manager by hand).
 */
function admin_recovery_format_code(string $raw): string
{
    $b32 = admin_totp_base32_encode($raw);
    $b32 = str_pad(substr($b32, 0, 8), 8, '0', STR_PAD_RIGHT);
    return substr($b32, 0, 4) . '-' . substr($b32, 4, 4);
}

/**
 * Replace all recovery codes in one transaction. Returns the freshly-minted
 * plaintext codes — the only time they leave the server. The DB stores
 * bcrypt hashes only; verification matches by walking unused rows.
 *
 * @return array<int, string>
 */
function admin_recovery_regenerate(\SQLite3 $db): array
{
    admin_recovery_db_init($db);

    $codes = [];
    $hashes = [];
    for ($i = 0; $i < ADMIN_RECOVERY_CODE_COUNT; $i++) {
        $raw = random_bytes(ADMIN_RECOVERY_CODE_BYTES);
        $codes[] = admin_recovery_format_code($raw);
        $hashes[] = password_hash(end($codes), PASSWORD_BCRYPT);
    }

    $db->exec('BEGIN IMMEDIATE');
    try {
        $db->exec('DELETE FROM admin_recovery_codes');
        $stmt = $db->prepare(
            'INSERT INTO admin_recovery_codes (code_hash, created_at) VALUES (:h, :t)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare recovery insert.');
        }
        $now = time();
        foreach ($hashes as $h) {
            $stmt->bindValue(':h', $h, SQLITE3_TEXT);
            $stmt->bindValue(':t', $now, SQLITE3_INTEGER);
            $stmt->execute();
            $stmt->reset();
            $stmt->clear();
        }
        $db->exec('COMMIT');
    } catch (\Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
    return $codes;
}

/**
 * Verify a recovery code: returns the row id on match (and marks it used),
 * or null if no unused row matches.
 *
 * Walks every unused row by design — bcrypt hashes are not deterministic,
 * so we cannot index by hash. With the 10-row cap that ships with this
 * feature, the linear scan is cheap.
 */
function admin_recovery_verify_and_consume(\SQLite3 $db, string $submitted): ?int
{
    admin_recovery_db_init($db);
    $submitted = strtoupper(preg_replace('/\\s|-/', '', $submitted) ?? '');
    if ($submitted === '' || !preg_match('/^[A-Z2-7]{8}$/', $submitted)) {
        return null;
    }
    // Re-format to the canonical "XXXX-XXXX" shape the hashes were created with.
    $canonical = substr($submitted, 0, 4) . '-' . substr($submitted, 4, 4);

    $res = $db->query('SELECT id, code_hash FROM admin_recovery_codes WHERE used_at IS NULL');
    if ($res === false) {
        return null;
    }
    while (($r = $res->fetchArray(SQLITE3_ASSOC)) !== false) {
        if (password_verify($canonical, (string)$r['code_hash'])) {
            $upd = $db->prepare(
                'UPDATE admin_recovery_codes SET used_at = :t WHERE id = :id AND used_at IS NULL'
            );
            if ($upd === false) {
                return null;
            }
            $upd->bindValue(':t', time(), SQLITE3_INTEGER);
            $upd->bindValue(':id', (int)$r['id'], SQLITE3_INTEGER);
            $upd->execute();
            // Confirm the guarded UPDATE actually changed a row — concurrent
            // submits of the same code race here, and only one must succeed.
            if ($db->changes() > 0) {
                return (int)$r['id'];
            }
        }
    }
    return null;
}

/**
 * Count unused recovery codes — used by the admin TOTP page banner so the
 * operator knows when to regenerate.
 */
function admin_recovery_unused_count(\SQLite3 $db): int
{
    admin_recovery_db_init($db);
    $res = $db->query('SELECT COUNT(*) FROM admin_recovery_codes WHERE used_at IS NULL');
    if ($res === false) {
        return 0;
    }
    $row = $res->fetchArray(SQLITE3_NUM);
    return is_array($row) ? (int)$row[0] : 0;
}
