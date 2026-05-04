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

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Two-factor verification — Subnet Calculator</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<style>
  :root { color-scheme: dark; }
  body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background:#0a1a12; color:#e5e7eb; margin:0; padding:2rem; }
  main { max-width: 480px; margin:0 auto; }
  h1 { font-size:1.4rem; margin:0 0 1rem; }
  .card { background:#0f2419; border:1px solid #1e3a2a; border-radius:8px; padding:1.5rem; }
  .alert-err { background:#3b0606; border:1px solid #ef4444; color:#fecaca; padding:0.75rem 1rem; border-radius:6px; margin-bottom:1rem; }
  label { display:block; margin-bottom:0.75rem; }
  label .lbl { display:block; font-weight:600; margin-bottom:0.25rem; color:#a7f3d0; }
  input[type=text] { width:100%; box-sizing:border-box; background:#000; border:1px solid #1e3a2a; color:#e5e7eb; padding:0.6rem; border-radius:4px; font-size:1.1rem; font-family: ui-monospace, monospace; letter-spacing:0.1em; }
  .btn { background:#06d6a0; color:#0a1a12; border:none; padding:0.6rem 1.2rem; border-radius:4px; font-weight:600; cursor:pointer; font-size:1rem; }
  .btn:hover { background:#34e2b5; }
  small { color:#9ca3af; }
</style>
</head>
<body>
<main>
<h1>Two-factor verification</h1>
<?php if ($error !== null) : ?><div class="alert-err"><?= $h($error) ?></div><?php endif; ?>
<div class="card">
  <form method="post" action="" autocomplete="off">
    <label>
      <span class="lbl">Authenticator code or recovery code</span>
      <input type="text" name="code" required autofocus
             pattern="[0-9A-Za-z\- ]{6,}"
             inputmode="text"
             placeholder="123 456">
    </label>
    <button class="btn" type="submit">Verify</button>
    <p><small>Lost your authenticator? Enter a recovery code instead — each one is single-use.</small></p>
  </form>
</div>
</main>
</body>
</html>
