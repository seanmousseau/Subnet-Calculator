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
if (!function_exists('help_bubble')) {
    require __DIR__ . '/../includes/functions-util.php';
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
                // $hash is non-string only on the password_hash() failure
                // path above; in that case admin_wizard_snippet() must not
                // be called with it. Guard so the strict-types signature
                // doesn't fatal under a TypeError.
                $fallback_hash = is_string($hash) ? $hash : '';
            }
        }
    }
}

ob_start();
?>
<section class="admin-section" aria-labelledby="wizard-heading">
    <h1 id="wizard-heading">Set up the admin account</h1>
    <p class="admin-lede">
        Admin UI is enabled but no password is configured. Choose a username and
        password below — the wizard will write
        <code>Subnet-Calculator/config-admin.php</code> alongside your existing
        <code>config.php</code>. The wizard self-locks on completion.
    </p>
</section>

<?php if ($success_path !== null) : ?>
    <section class="admin-section" aria-labelledby="wizard-done-heading">
        <h2 id="wizard-done-heading" class="sr-only">Setup complete</h2>
        <div class="alert alert-success" role="status">
            <strong>Done.</strong> Wrote <code><?= $h($success_path) ?></code>.
            Refresh this page to log in.
        </div>
        <p>
            <a href="keys.php" class="splitter-btn admin-btn-link">Continue to API keys →</a>
        </p>
    </section>
<?php else : ?>
    <?php if ($errors !== []) : ?>
        <section class="admin-section" aria-labelledby="wizard-errors-heading">
            <h2 id="wizard-errors-heading" class="sr-only">Errors</h2>
            <div class="alert alert-error" role="alert">
                <strong>We could not save your configuration:</strong>
                <ul class="admin-error-list">
                    <?php foreach ($errors as $err) : ?>
                        <li><?= $h($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($fallback_hash !== '') : ?>
        <section class="admin-section" aria-labelledby="wizard-fallback-heading">
            <h2 id="wizard-fallback-heading">Manual fallback</h2>
            <p>
                Paste the snippet below into <code>Subnet-Calculator/config.php</code>,
                then refresh this page. (This is the same one-line approach as the
                <code>php -r "echo password_hash(...)"</code> setup we used before the
                wizard existed.)
            </p>
            <pre class="api-key-display"><?= $h(admin_wizard_snippet($fallback_user, $fallback_hash)) ?></pre>
        </section>
    <?php endif; ?>

    <section class="admin-section" aria-labelledby="wizard-form-heading">
        <h2 id="wizard-form-heading" class="sr-only">Setup form</h2>
        <form method="post" action="" autocomplete="off" class="admin-form">
            <input type="hidden" name="_csrf" value="<?= $h($csrf_token) ?>">
            <div class="form-group">
                <label for="wizard-user">Username</label>
                <input id="wizard-user" type="text" name="admin_user" required maxlength="<?= ADMIN_WIZARD_USER_MAX ?>"
                       autocomplete="username" value="<?= $h($user_input) ?>">
            </div>
            <div class="form-group">
                <label for="wizard-pass">Password (min <?= ADMIN_WIZARD_PASSWORD_MIN ?> characters)</label>
                <input id="wizard-pass" type="password" name="admin_password" required
                       minlength="<?= ADMIN_WIZARD_PASSWORD_MIN ?>" maxlength="<?= ADMIN_WIZARD_PASSWORD_MAX ?>"
                       autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="wizard-pass2">Confirm password</label>
                <input id="wizard-pass2" type="password" name="admin_password_confirm" required
                       minlength="<?= ADMIN_WIZARD_PASSWORD_MIN ?>" maxlength="<?= ADMIN_WIZARD_PASSWORD_MAX ?>"
                       autocomplete="new-password">
            </div>
            <div class="form-group form-group-action">
                <button type="submit" class="splitter-btn">Save and continue</button>
            </div>
        </form>
        <p class="admin-meta-note">After this completes you can enable TOTP/2FA from the admin pages.</p>
    </section>
<?php endif; ?>
<?php
$admin_card_body  = ob_get_clean();
$page_title       = 'Set up Admin';
$admin_breadcrumb = 'Setup';
require __DIR__ . '/../templates/_admin_layout.php';
