<?php

declare(strict_types=1);

// First-run admin wizard view (v3.0.0, #311). Required by admin/keys.php
// (and admin/audit.php) when admin_wizard_needed() is true. Renders the
// setup form on GET; processes the form on POST and writes
// config-admin.php via admin_wizard_write(). Audit-logs wizard.complete.
//
// Self-locks on the next request: once $admin_pass_hash is non-empty,
// admin_wizard_needed() returns false and admin_authenticate() takes over.
//
// CSRF: there are no credentials yet to bind the token to, so this page
// uses the standard same-origin double-submit pattern (cookie + form
// field), set on first GET. The wizard target file lives outside the
// docroot's web-accessible config block, but the wizard itself is reached
// without auth — same threat model as a one-time setup wizard on a fresh
// install.

if (!function_exists('admin_wizard_needed')) {
    require __DIR__ . '/../includes/functions-admin-wizard.php';
}
if (!function_exists('audit_log')) {
    require __DIR__ . '/../includes/functions-audit.php';
}
if (!function_exists('admin_apikey_db_path')) {
    require __DIR__ . '/../includes/functions-admin-auth.php';
}

header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

// Double-submit CSRF cookie. Stored in a separate cookie + posted as a
// hidden form field; server compares the two. Lifetime = browser session.
$csrf_cookie = 'sc_admin_wizard_csrf';
$csrf_token  = $_COOKIE[$csrf_cookie] ?? '';
if (!is_string($csrf_token) || !preg_match('/^[a-f0-9]{64}$/', $csrf_token)) {
    $csrf_token = bin2hex(random_bytes(32));
    setcookie($csrf_cookie, $csrf_token, [
        'expires'  => 0,
        'path'     => '/',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$errors        = [];
$success_path  = null;
$fallback_user = '';
$fallback_hash = '';
$user_input    = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $posted = (string)($_POST['_csrf'] ?? '');
    if (!hash_equals($csrf_token, $posted)) {
        $errors[] = 'Invalid CSRF token. Reload the page and retry.';
    } else {
        $user_input    = (string)($_POST['admin_user'] ?? '');
        $password      = (string)($_POST['admin_password'] ?? '');
        $passwordAgain = (string)($_POST['admin_password_confirm'] ?? '');

        $errors = admin_wizard_validate($user_input, $password, $passwordAgain);
        if ($errors === []) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            // PASSWORD_BCRYPT can return false on rare implementations / OOM.
            // Refuse to call admin_wizard_write() with a non-string value —
            // strict types would otherwise throw a TypeError + 500.
            $result = ['ok' => false, 'reason' => ''];
            if (!is_string($hash) || $hash === '') {
                $errors[] = 'Password hashing failed unexpectedly. Try again or set $admin_pass_hash by hand.';
            } else {
                $result = admin_wizard_write(trim($user_input), $hash);
            }
            if ($result['ok']) {
                $success_path = $result['path'] ?? admin_wizard_config_path();
                // Audit-log the completion. The wizard runs unauthenticated,
                // so the only "actor" we can record is the chosen username.
                try {
                    $db = new \SQLite3(admin_apikey_db_path());
                    $db->enableExceptions(true);
                    $db->busyTimeout(1500);
                    audit_log(
                        $db,
                        'wizard.complete',
                        trim($user_input),
                        audit_ip_from_request(),
                        null,
                        ['config_path' => $success_path]
                    );
                    $db->close();
                } catch (\Throwable $e) {
                    error_log('sc admin wizard audit error: ' . $e->getMessage());
                }
            } else {
                $errors[] = $result['reason'] ?? 'Failed to save admin configuration.';
                $fallback_user = trim($user_input);
                $fallback_hash = $hash;
            }
        }
    }
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Set up Admin — Subnet Calculator</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<style>
  :root { color-scheme: dark; }
  body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background:#0a1a12; color:#e5e7eb; margin:0; padding:2rem; }
  main { max-width: 720px; margin:0 auto; }
  h1 { font-size:1.6rem; margin:0 0 0.5rem; }
  p.lede { color:#9ca3af; margin:0 0 1.5rem; }
  .card { background:#0f2419; border:1px solid #1e3a2a; border-radius:8px; padding:1.5rem; margin-bottom:1.25rem; }
  .alert { padding:0.75rem 1rem; border-radius:6px; margin-bottom:1rem; }
  .alert-ok    { background:#063b25; border:1px solid #06d6a0; color:#a7f3d0; }
  .alert-err   { background:#3b0606; border:1px solid #ef4444; color:#fecaca; }
  label { display:block; margin-bottom:0.75rem; }
  label .lbl { display:block; font-weight:600; margin-bottom:0.25rem; color:#a7f3d0; }
  input[type=text], input[type=password] { width:100%; box-sizing:border-box; background:#000; border:1px solid #1e3a2a; color:#e5e7eb; padding:0.6rem; border-radius:4px; font-size:1rem; }
  .btn { background:#06d6a0; color:#0a1a12; border:none; padding:0.6rem 1.2rem; border-radius:4px; font-weight:600; cursor:pointer; font-size:1rem; }
  .btn:hover { background:#34e2b5; }
  pre.snippet { background:#000; color:#06d6a0; padding:1rem; border-radius:4px; overflow-x:auto; font-family: ui-monospace, monospace; font-size:0.9rem; }
  ul.errors { margin:0; padding-left:1.25rem; }
  code { background:#000; padding:0.05rem 0.3rem; border-radius:3px; font-family: ui-monospace, monospace; }
</style>
</head>
<body>
<main>
<h1>Set up the admin account</h1>
<p class="lede">
  Admin UI is enabled but no password is configured. Choose a username and
  password below — the wizard will write
  <code>Subnet-Calculator/config-admin.php</code> alongside your existing
  <code>config.php</code>. The wizard self-locks on completion.
</p>

<?php if ($success_path !== null) : ?>
  <div class="card">
    <div class="alert alert-ok">
      <strong>Done.</strong> Wrote <code><?= $h($success_path) ?></code>.
      Refresh this page to log in.
    </div>
    <p>
      <a href="keys.php" class="btn" style="text-decoration:none;display:inline-block">Continue to API keys →</a>
    </p>
  </div>
<?php else : ?>

<?php if ($errors !== []) : ?>
  <div class="alert alert-err">
    <strong>We could not save your configuration:</strong>
    <ul class="errors">
      <?php foreach ($errors as $err) : ?>
        <li><?= $h($err) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($fallback_hash !== '') : ?>
  <div class="card">
    <strong>Manual fallback</strong>
    <p>
      Paste the snippet below into <code>Subnet-Calculator/config.php</code>,
      then refresh this page. (This is the same one-line approach as the
      <code>php -r "echo password_hash(...)"</code> setup we used before the
      wizard existed.)
    </p>
    <pre class="snippet"><?= $h(admin_wizard_snippet($fallback_user, $fallback_hash)) ?></pre>
  </div>
<?php endif; ?>

<div class="card">
  <form method="post" action="" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= $h($csrf_token) ?>">
    <label>
      <span class="lbl">Username</span>
      <input type="text" name="admin_user" required maxlength="<?= ADMIN_WIZARD_USER_MAX ?>"
             autocomplete="username" value="<?= $h($user_input) ?>">
    </label>
    <label>
      <span class="lbl">Password (min <?= ADMIN_WIZARD_PASSWORD_MIN ?> characters)</span>
      <input type="password" name="admin_password" required
             minlength="<?= ADMIN_WIZARD_PASSWORD_MIN ?>" maxlength="<?= ADMIN_WIZARD_PASSWORD_MAX ?>"
             autocomplete="new-password">
    </label>
    <label>
      <span class="lbl">Confirm password</span>
      <input type="password" name="admin_password_confirm" required
             minlength="<?= ADMIN_WIZARD_PASSWORD_MIN ?>" maxlength="<?= ADMIN_WIZARD_PASSWORD_MAX ?>"
             autocomplete="new-password">
    </label>
    <button type="submit" class="btn">Save and continue</button>
  </form>
</div>

<p style="color:#6b7280;font-size:0.85rem">
  After this completes you can enable TOTP/2FA from the admin pages.
</p>

<?php endif; ?>
</main>
</body>
</html>
