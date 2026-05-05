<?php

declare(strict_types=1);

// /admin/_test-drain.php — TEST-ONLY admin-state reset endpoint (v3.1.0 #324).
//
// Zeroes api_keys, admin_audit, and admin_recovery_codes; clears sc_admin
// PHP-session files. Lets `make test-docker` run repeatedly against a
// non-fresh container without leftover rows tripping later tests.
//
// Hard-gated by PHPUNIT_TEST_DRAIN_TOKEN — when the env var is unset (the
// production case) the endpoint returns 404 and reveals nothing about its
// existence. Token comparison is timing-safe via hash_equals().
//
// This file MUST NOT ship in release tarballs. The release-build step in
// CLAUDE.md excludes admin/_test-drain.php via `tar --exclude=…`; on top of
// that, this endpoint fails closed (404) whenever PHPUNIT_TEST_DRAIN_TOKEN
// is unset, so a stray copy on a production host stays inert by default.

$expected = getenv('PHPUNIT_TEST_DRAIN_TOKEN');
if (!is_string($expected) || $expected === '') {
    // Fail closed: no token configured → pretend the route does not exist.
    http_response_code(404);
    exit;
}

// Documented contract is POST-only; reject everything else explicitly so the
// behaviour does not depend on SAPI quirks that populate $_POST on PUT/PATCH.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$presented = $_POST['token'] ?? '';
if (!is_string($presented) || !hash_equals($expected, $presented)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions-admin-auth.php';

$drained = [];

try {
    $db = new \SQLite3(admin_apikey_db_path());
    $db->enableExceptions(true);

    foreach (['api_keys', 'admin_audit', 'admin_recovery_codes', 'admin_sessions', 'admin_state', 'auth_rate_limit'] as $table) {
        // Each table is created lazily by its owning module's open() helper.
        // The drain endpoint runs at the start of a test suite — before any
        // admin page is hit — so the tables may not exist yet. DELETE on a
        // missing table would throw; guard with an existence check.
        $stmt = $db->prepare(
            "SELECT 1 FROM sqlite_master WHERE type='table' AND name=:n LIMIT 1"
        );
        if ($stmt === false) {
            continue;
        }
        $stmt->bindValue(':n', $table, SQLITE3_TEXT);
        $res = $stmt->execute();
        if ($res !== false && $res->fetchArray(SQLITE3_NUM) !== false) {
            $db->exec('DELETE FROM "' . $table . '"');
            $drained[] = $table;
        }
    }
    $db->close();
} catch (\Throwable $e) {
    // Log the underlying message but do not echo it back — even though the
    // endpoint is token-gated, leaking SQLite paths or driver text to the
    // response body widens what a future misconfiguration could expose.
    error_log('sc admin drain error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => false, 'error' => 'drain failed']);
    exit;
}

// Clear sc_admin PHP-session files for this container so any saved
// CSRF / wizard / TOTP-pending state from a prior run is dropped.
//
// Path-safety: $dir comes only from session_save_path() (set by PHP from
// php.ini, never from request input) or sys_get_temp_dir(); the glob
// pattern is fixed; each match is then realpath()-checked to confirm it
// lives directly inside the resolved session dir and matches the
// `sess_<token>` shape before unlink. No user input reaches this code path.
$dir = session_save_path();
if (!is_string($dir) || $dir === '') {
    $dir = sys_get_temp_dir();
}
$dirReal = realpath($dir);
$cleared = 0;
if (is_string($dirReal)) {
    $matches = glob($dirReal . '/sess_*');
    if (is_array($matches)) {
        foreach ($matches as $file) {
            $base = basename($file);
            // Defense-in-depth: confirm the basename is the canonical
            // sess_<alnum> shape PHP itself emits, the file resolves
            // inside $dirReal, and it is a regular file (not a symlink).
            if (
                preg_match('/^sess_[A-Za-z0-9,_-]+$/', $base) === 1
                && is_file($file)
                && !is_link($file)
                && dirname((string)realpath($file)) === $dirReal
            ) {
                // No user input reaches $file: $dir originates from
                // session_save_path() (php.ini, not request data); the
                // glob pattern is hard-coded; the match is regex-validated
                // and realpath-confined to $dirReal. Token gate above
                // already requires PHPUNIT_TEST_DRAIN_TOKEN equality.
                // nosemgrep: php.lang.security.unlink-use.unlink-use
                if (@unlink($file)) {
                    $cleared++;
                }
            }
        }
    }
}
$drained[] = 'sessions(' . $cleared . ')';

header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['ok' => true, 'drained' => $drained]);
