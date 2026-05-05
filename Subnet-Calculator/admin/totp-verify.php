<?php

declare(strict_types=1);

// /admin/totp-verify.php — TOTP step-up form (v3.0.0 #313, refactored
// for cookie-session login in v3.2.0 #342).
//
// The flow is now: admin/login.php verifies the password and mints an
// admin_sessions row with totp_pending=1 when $admin_totp_secret is
// configured. admin_session_require() redirects every web admin page
// (except this one and logout.php) to here while totp_pending=1. On a
// successful TOTP / recovery code we call admin_session_promote() to
// flip the flag, rotate the CSRF token, and redirect to the ?next=
// target.
//
// Failures audit-log auth.totp.fail and feed the same auth_rate_limit
// table the password step uses.

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions-util.php';
require __DIR__ . '/../includes/functions-admin-auth.php';
require __DIR__ . '/../includes/functions-admin-session.php';
require __DIR__ . '/../includes/functions-admin-totp.php';
require __DIR__ . '/../includes/functions-apikeys.php';
require __DIR__ . '/../includes/functions-audit.php';
require __DIR__ . '/../includes/functions-admin-wizard.php';

if (admin_wizard_needed()) {
    header('Location: keys.php', true, 303);
    exit;
}
if (!admin_totp_step_up_required()) {
    // TOTP disabled — promote any pending session and bounce home.
    $sid = (string)($_COOKIE[ADMIN_SESSION_COOKIE] ?? '');
    if ($sid !== '') {
        $db = new \SQLite3(admin_apikey_db_path());
        $db->enableExceptions(true);
        admin_session_promote($db, $sid);
        $db->close();
    }
    header('Location: keys.php', true, 303);
    exit;
}

// admin_session_require() lets totp-verify.php through with totp_pending=1.
// It also handles the unauth case (redirect to login.php).
$ctx = admin_session_require();

header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$nextRaw = (string)($_GET['next'] ?? $_POST['next'] ?? '/admin/');
$nextPath = (string)(parse_url($nextRaw, PHP_URL_PATH) ?: '/admin/');
if (
    $nextPath === ''
    || !preg_match('#^/admin/[A-Za-z0-9_./\\-]*$#', $nextPath)
    || str_contains($nextPath, 'login.php')
    || str_contains($nextPath, 'totp-verify.php')
    || str_contains($nextPath, '_test-drain.php')
) {
    $nextPath = '/admin/';
}

$db = new \SQLite3(admin_apikey_db_path());
$db->enableExceptions(true);
$db->busyTimeout(1500);

$ip    = audit_ip_from_request();
$user  = $ctx['user'];
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $submittedCsrf = (string)($_POST['csrf'] ?? '');
    $submittedCode = trim((string)($_POST['code'] ?? ''));

    $wait = admin_auth_rate_limit_check($db, $ip, $user);
    if ($wait > 0) {
        $error = 'Too many failed attempts. Try again in ' . (int)$wait . ' second'
               . ($wait === 1 ? '' : 's') . '.';
        admin_audit_event('login.totp.fail', $user, ['reason' => 'rate_limit']);
    } elseif (!hash_equals($ctx['csrf'], $submittedCsrf)) {
        $error = 'Form expired. Try again.';
        admin_audit_event('login.totp.fail', $user, ['reason' => 'csrf']);
    } elseif ($submittedCode !== '' && admin_totp_consume_attempt($user, $submittedCode)) {
        admin_session_promote($db, $ctx['session_id']);
        admin_auth_rate_limit_clear($db, $ip, $user);
        admin_audit_event('login.totp.success', $user, ['via' => 'form']);
        $db->close();
        header('Location: ' . $nextPath, true, 303);
        exit;
    } else {
        admin_auth_rate_limit_register_failure($db, $ip, $user);
        admin_audit_event('login.totp.fail', $user, ['via' => 'form']);
        $error = 'Code did not match. Try again, or use a recovery code.';
    }
}

$db->close();

ob_start();
?>
<section class="admin-section" aria-labelledby="totp-verify-heading">
    <h1 id="totp-verify-heading">Two-factor verification</h1>
    <?php if ($error !== null) : ?>
        <div class="alert alert-error" role="alert"><?= $h($error) ?></div>
    <?php endif; ?>
    <form method="post" action="" autocomplete="off" class="admin-form">
        <input type="hidden" name="csrf" value="<?= $h($ctx['csrf']) ?>">
        <input type="hidden" name="next" value="<?= $h($nextPath) ?>">
        <div class="form-group">
            <label for="totp-code">Authenticator code or recovery code</label>
            <input id="totp-code" type="text" name="code" required autofocus
                   pattern="[0-9A-Za-z\- ]{6,}"
                   inputmode="text"
                   class="admin-input-totp"
                   placeholder="123 456">
        </div>
        <div class="form-group form-group-action">
            <button class="splitter-btn" type="submit">Verify</button>
        </div>
    </form>
    <p class="admin-meta-note">Lost your authenticator? Enter a recovery code instead — each one is single-use.</p>
</section>
<?php
$admin_card_body       = ob_get_clean();
$page_title            = 'Two-factor verification';
$admin_breadcrumb      = 'Verify';
// Pre-promote the session row carries the placeholder user ('') and the
// pending CSRF; we surface them here so a stuck operator can hit Sign out
// from the verify page without first having to complete TOTP.
$admin_user_signed_in  = (string)($ctx['user'] ?? '');
$admin_csrf_token      = (string)($ctx['csrf'] ?? '');
require __DIR__ . '/../templates/_admin_layout.php';
