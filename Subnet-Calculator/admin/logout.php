<?php

declare(strict_types=1);

// /admin/logout.php — POST-only logout endpoint (v3.2.0, #343).
//
// Tears down the current cookie session row, clears the sc_admin_sid
// cookie client-side, audit-logs auth.logout, and redirects to
// admin/login.php?logged_out=1 so the login page can render a "you have
// been signed out" status banner.
//
// CSRF: the POST body must carry the active session row's csrf token in
// the `csrf` field. We compare timing-safely with hash_equals(). Mismatch
// or missing cookie -> 403. Non-POST -> 405 + Allow: POST.
//
// Note: the API admin Basic-Auth path (/api/v1/admin/*) is unaffected —
// there is no server-side session to end on that path.

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions-admin-auth.php';
require __DIR__ . '/../includes/functions-admin-session.php';
require __DIR__ . '/../includes/functions-apikeys.php';
require __DIR__ . '/../includes/functions-audit.php';

global $admin_ui_enabled;
if (!$admin_ui_enabled) {
    http_response_code(404);
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Method Not Allowed\n";
    exit;
}

$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

$cookieSidRaw = $_COOKIE[ADMIN_SESSION_COOKIE] ?? '';
$cookieSid    = is_string($cookieSidRaw) ? $cookieSidRaw : '';

$submittedCsrfRaw = $_POST['csrf'] ?? '';
$submittedCsrf    = is_string($submittedCsrfRaw) ? $submittedCsrfRaw : '';

// Open the admin DB up front — used for session lookup, end, and audit.
try {
    $db = new \SQLite3(admin_apikey_db_path());
    $db->enableExceptions(true);
    $db->busyTimeout(1500);
} catch (\Throwable $e) {
    error_log('sc admin logout db open error: ' . $e->getMessage());
    http_response_code(503);
    echo 'Logout failed: storage unavailable.';
    exit;
}

// CSRF: the cookie must point at a real session row whose stored token
// matches the form field. No cookie / no row / no match -> 403. We do NOT
// 200 here just because the cookie is missing — a missing cookie means
// the browser is already signed out, but a CSRF-less POST that we accept
// would let a third-party page log the user out (login-CSRF). Reject.
$row = $cookieSid !== '' ? admin_session_check($db, $cookieSid) : null;
if ($row === null || !hash_equals((string)$row['csrf'], $submittedCsrf)) {
    $db->close();
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden: invalid or missing CSRF token.\n";
    exit;
}

$user = (string)$row['user'];

// End the session row + clear the rate-limit failure counter for this
// user (operator may have just rotated credentials and want a clean slate).
admin_session_end($db, $cookieSid);

// Audit the logout. Any failure here is swallowed by admin_audit_event(),
// so a temporarily-failing audit table never blocks the actual sign-out.
admin_audit_event('auth.logout', $user !== '' ? $user : null, [
    'sid_prefix' => substr($cookieSid, 0, 8),
]);

$db->close();

// Clear the cookie client-side. Same attributes as the set in login.php so
// browsers actually invalidate the right cookie (path/secure/samesite must
// match for the deletion to land).
setcookie(ADMIN_SESSION_COOKIE, '', [
    'expires'  => 1,
    'path'     => '/',
    'secure'   => $is_https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
// Also emit the explicit Max-Age=0 form for tests / proxies that look at
// the raw header rather than parsed Set-Cookie attributes.
header(
    'Set-Cookie: ' . ADMIN_SESSION_COOKIE . '=; Max-Age=0; Path=/; '
    . 'HttpOnly; SameSite=Lax' . ($is_https ? '; Secure' : ''),
    false
);

header('Location: login.php?logged_out=1', true, 302);
exit;
