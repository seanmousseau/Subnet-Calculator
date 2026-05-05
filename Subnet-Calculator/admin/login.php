<?php

declare(strict_types=1);

// /admin/login.php — server-rendered login form (v3.2.0, #342).
//
// Replaces the HTTP Basic Auth dialog so 1Password / Bitwarden / Chrome's
// built-in password manager can save and autofill credentials on the
// /admin/* surface. TOTP step-up still applies and runs as a separate
// page (admin/totp-verify.php) immediately after a successful POST.
//
// On success, mints a row in admin_sessions, sets the sc_admin_sid cookie
// (HttpOnly; Secure; SameSite=Lax), and redirects to the ?next= target
// (whitelisted to /admin/*). Failed POSTs increment the auth_rate_limit
// counter; once 3 failures land for the same (ip, user) pair, the form
// rejects further attempts with an escalating backoff (1s -> 5s -> 30s
// -> 5min -> 30min -> 60min). Audit-logged via auth.login.success and
// auth.login.fail.
//
// CSRF: synchroniser-pattern token bound to the row in admin_sessions.
// On GET we mint a "pending" session row (totp_pending=1, csrf=...) and
// stash the id in the sc_admin_sid cookie. The form posts back the csrf
// token; the handler looks up the cookied row, runs hash_equals(), and
// either promotes the row on a correct password or destroys it on
// failure.
//
// /api/v1/admin/* keeps Basic Auth — see api/v1/handlers/admin_keys.php.

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions-util.php';
require __DIR__ . '/../includes/functions-admin-auth.php';
require __DIR__ . '/../includes/functions-admin-session.php';
require __DIR__ . '/../includes/functions-apikeys.php';
require __DIR__ . '/../includes/functions-audit.php';
require __DIR__ . '/../includes/functions-admin-wizard.php';

if (admin_wizard_needed()) {
    header('Location: keys.php', true, 303);
    exit;
}

global $admin_ui_enabled, $admin_user, $admin_pass_hash;
if (!$admin_ui_enabled) {
    http_response_code(404);
    exit;
}
if (!is_string($admin_user) || $admin_user === ''
    || !is_string($admin_pass_hash) || $admin_pass_hash === ''
) {
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><title>Admin</title>'
        . '<p>Admin UI enabled but $admin_user / $admin_pass_hash not configured.</p>';
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

// Resolve and whitelist the post-login next= target.
$nextRaw = (string)($_GET['next'] ?? $_POST['next'] ?? '/admin/');
$nextPath = (string)(parse_url($nextRaw, PHP_URL_PATH) ?: '/admin/');
if (
    $nextPath === ''
    || !preg_match('#^/admin/[A-Za-z0-9_./\\-]*$#', $nextPath)
    || preg_match('#(?:^|/)\.\.(?:/|$)#', $nextPath)
    || str_contains($nextPath, 'login.php')
    || str_contains($nextPath, '_test-drain.php')
) {
    $nextPath = '/admin/';
}

$db = new \SQLite3(admin_apikey_db_path());
$db->enableExceptions(true);
$db->busyTimeout(1500);
admin_session_db_init($db);
audit_db_init($db);

$ip = audit_ip_from_request();
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$error          = null;
$lockoutSeconds = 0;
$loggedOut      = isset($_GET['logged_out']);

// If the visitor already has a fully-promoted session, send them home.
$existingSid = (string)($_COOKIE[ADMIN_SESSION_COOKIE] ?? '');
if ($existingSid !== '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $existing = admin_session_check($db, $existingSid);
    if ($existing !== null && (int)$existing['totp_pending'] === 0) {
        $db->close();
        header('Location: ' . $nextPath, true, 303);
        exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $submittedUser  = trim((string)($_POST['user'] ?? ''));
    $submittedPass  = (string)($_POST['pass'] ?? '');
    $submittedCsrf  = (string)($_POST['csrf'] ?? '');
    $cookieSid      = (string)($_COOKIE[ADMIN_SESSION_COOKIE] ?? '');

    // CSRF: must have a pending session row whose csrf matches the form.
    $pending = $cookieSid !== '' ? admin_session_check($db, $cookieSid) : null;
    $csrfOk  = $pending !== null
        && hash_equals((string)$pending['csrf'], $submittedCsrf)
        && (int)$pending['totp_pending'] === 1
        && (string)$pending['user'] === '';

    // Rate limit check FIRST so a flood of bad CSRFs still trips the
    // lockout. We use the submitted username (or "?" if empty) for the
    // (ip, user) scope so an attacker cycling usernames doesn't get an
    // infinite per-username allowance.
    $userKey = $submittedUser !== '' ? $submittedUser : '?';
    $wait    = admin_auth_rate_limit_check($db, $ip, $userKey);
    if ($wait > 0) {
        $lockoutSeconds = $wait;
        $error = 'Too many failed attempts. Try again in ' . (int)$wait . ' second'
               . ($wait === 1 ? '' : 's') . '.';
        admin_audit_event('login.fail', $submittedUser !== '' ? $submittedUser : null, ['reason' => 'rate_limit']);
    } elseif (!$csrfOk) {
        $error = 'Your login form expired. Please try again.';
        admin_audit_event('login.fail', $submittedUser !== '' ? $submittedUser : null, ['reason' => 'csrf']);
    } else {
        $userOk = hash_equals((string)$admin_user, $submittedUser);
        $passOk = $userOk && password_verify($submittedPass, (string)$admin_pass_hash);
        if (!$passOk) {
            // Always run password_verify against the configured hash to
            // keep timing roughly constant whether the username matches
            // or not (timing-safe by hash_equals on user; password_verify
            // on a wrong password returns false in ~similar time).
            if (!$userOk) {
                @password_verify($submittedPass, (string)$admin_pass_hash);
            }
            admin_auth_rate_limit_register_failure($db, $ip, $userKey);
            admin_audit_event('login.fail', $submittedUser !== '' ? $submittedUser : null, ['reason' => 'password']);
            $error = 'Username or password is incorrect.';
        } else {
            // Success path. Replace the pending placeholder row with a
            // fresh row scoped to the verified user, drop the rate
            // counter, set a long-lived cookie, and redirect.
            admin_session_end($db, $cookieSid);
            admin_auth_rate_limit_clear($db, $ip, $userKey);

            $totpPending = is_string($GLOBALS['admin_totp_secret'] ?? null)
                && $GLOBALS['admin_totp_secret'] !== '';
            $newSess = admin_session_start($db, (string)$admin_user, $ip, $ua, $totpPending);

            setcookie(ADMIN_SESSION_COOKIE, $newSess['session_id'], [
                'expires'  => 0,                       // session cookie
                'path'     => '/',
                'secure'   => $is_https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            admin_audit_event('login.success', (string)$admin_user, ['totp_required' => $totpPending]);
            $db->close();

            $target = $totpPending
                ? ('totp-verify.php?next=' . rawurlencode($nextPath))
                : $nextPath;
            header('Location: ' . $target, true, 303);
            exit;
        }
    }
}

// GET (or failed POST that still wants to render a form). Mint or rotate
// the placeholder pending session so the rendered form has a fresh CSRF
// token bound to a row we own.
$cookieSid = (string)($_COOKIE[ADMIN_SESSION_COOKIE] ?? '');
$pending   = $cookieSid !== '' ? admin_session_check($db, $cookieSid) : null;
$needsNewPending = $pending === null
    || (string)$pending['user'] !== ''           // a real session, not a placeholder
    || (int)$pending['totp_pending'] !== 1;
if ($needsNewPending) {
    $sess = admin_session_start($db, '', $ip, $ua, true);
    setcookie(ADMIN_SESSION_COOKIE, $sess['session_id'], [
        'expires'  => 0,
        'path'     => '/',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $csrfToken = $sess['csrf'];
} else {
    $csrfToken = (string)$pending['csrf'];
}

$db->close();

ob_start();
?>
<section class="admin-section" aria-labelledby="login-heading">
    <h1 id="login-heading">Sign in</h1>
    <?php if ($loggedOut) : ?>
        <div class="alert alert-info" role="status">You have been signed out.</div>
    <?php endif; ?>
    <?php if ($error !== null) : ?>
        <div class="alert <?= $lockoutSeconds > 0 ? 'alert-warn' : 'alert-error' ?>" role="alert">
            <?= $h($error) ?>
        </div>
    <?php endif; ?>
    <form method="post" action="" class="admin-form" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="next" value="<?= $h($nextPath) ?>">
        <div class="form-group">
            <label for="login-user">Username</label>
            <input id="login-user" type="text" name="user" required
                   autocomplete="username"
                   autocapitalize="off" autocorrect="off" spellcheck="false"
                   <?= $lockoutSeconds > 0 ? 'disabled' : 'autofocus' ?>>
        </div>
        <div class="form-group">
            <label for="login-pass">Password</label>
            <input id="login-pass" type="password" name="pass" required
                   autocomplete="current-password"
                   <?= $lockoutSeconds > 0 ? 'disabled' : '' ?>>
        </div>
        <div class="form-group form-group-action">
            <button class="splitter-btn" type="submit" <?= $lockoutSeconds > 0 ? 'disabled' : '' ?>>
                Sign in
            </button>
        </div>
    </form>
    <p class="admin-meta-note">
        Credentials are configured in <code>config.php</code> (<code>$admin_user</code> /
        <code>$admin_pass_hash</code>). API clients continue to use HTTP Basic Auth at
        <code>/api/v1/admin/*</code>.
    </p>
</section>
<?php
$admin_card_body  = ob_get_clean();
$page_title       = 'Sign in';
$admin_breadcrumb = 'Sign in';
require __DIR__ . '/../templates/_admin_layout.php';
