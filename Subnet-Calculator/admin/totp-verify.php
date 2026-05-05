<?php

declare(strict_types=1);

// /admin/totp-verify.php — TOTP step-up form (v3.0.0, #313).
//
// Shown when the operator has enabled TOTP and admin_authenticate() needs
// a fresh second factor for this PHP session. The page itself goes through
// admin_authenticate() too, so the user must already be Basic-Auth'd; we
// just bypass the TOTP redirect loop by passing the verified code via
// X-Admin-TOTP via a custom callback.
//
// On success: caches `totp_verified_at` in the sc_admin session and
// redirects to the URL the user originally requested (stored in
// $_SESSION['totp_return_to'] by admin_totp_check_step_up()). Default
// return target is /admin/keys.php.

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions-util.php';
require __DIR__ . '/../includes/functions-admin-auth.php';
require __DIR__ . '/../includes/functions-admin-totp.php';
require __DIR__ . '/../includes/functions-apikeys.php';
require __DIR__ . '/../includes/functions-audit.php';
require __DIR__ . '/../includes/functions-admin-wizard.php';

if (admin_wizard_needed()) {
    header('Location: keys.php', true, 303);
    exit;
}

if (!admin_totp_step_up_required()) {
    // TOTP is disabled at the moment — there's nothing to verify against.
    header('Location: keys.php', true, 303);
    exit;
}

// Cache + framing headers (same posture as keys.php).
header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Manually verify Basic Auth (we can't call admin_authenticate() because
// it would redirect us right back here when no session is verified).
global $admin_user, $admin_pass_hash, $admin_ui_enabled;
if (!$admin_ui_enabled) {
    http_response_code(404);
    exit;
}
$u = $_SERVER['PHP_AUTH_USER'] ?? '';
$p = $_SERVER['PHP_AUTH_PW']   ?? '';
if (!is_string($u) || !is_string($p) || $u === ''
    || !hash_equals((string)$admin_user, $u)
    || !password_verify($p, (string)$admin_pass_hash)
) {
    header('WWW-Authenticate: Basic realm="Subnet Calculator Admin", charset="UTF-8"');
    http_response_code(401);
    echo '<!doctype html><meta charset="utf-8"><title>Admin</title><p>Authentication required.</p>';
    exit;
}

// Start (or resume) the sc_admin session.
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $is_https,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_name('sc_admin');
session_start();

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $submitted = trim((string)($_POST['code'] ?? ''));
    if ($submitted !== '' && admin_totp_consume_attempt($u, $submitted)) {
        // Rotate session ID after privilege elevation to defang fixation.
        session_regenerate_id(true);
        $_SESSION['totp_verified_at']   = time();
        $_SESSION['totp_verified_user'] = $u;
        $return = isset($_SESSION['totp_return_to']) && is_string($_SESSION['totp_return_to'])
            ? $_SESSION['totp_return_to'] : '/admin/keys.php';
        unset($_SESSION['totp_return_to']);
        // Block off-host returns: only allow paths that start with `/admin/`.
        if (!preg_match('#^/admin/[A-Za-z0-9_./\\-?=&%]*$#', $return)) {
            $return = '/admin/keys.php';
        }
        header('Location: ' . $return, true, 303);
        exit;
    }
    admin_audit_event('login.totp.fail', $u, ['via' => 'form']);
    $error = 'Code did not match. Try again, or use a recovery code.';
}

ob_start();
?>
<section class="admin-section" aria-labelledby="totp-verify-heading">
    <h1 id="totp-verify-heading">Two-factor verification</h1>
    <?php if ($error !== null) : ?>
        <div class="alert alert-error" role="alert"><?= $h($error) ?></div>
    <?php endif; ?>
    <form method="post" action="" autocomplete="off" class="admin-form">
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
$admin_card_body  = ob_get_clean();
$page_title       = 'Two-factor verification';
$admin_breadcrumb = 'Verify';
require __DIR__ . '/../templates/_admin_layout.php';
