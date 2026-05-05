<?php

declare(strict_types=1);

// /admin/totp.php — TOTP enrolment + recovery-code management.
//
// v3.0.0 (#313) shipped a "paste this snippet into config.php" enrol flow
// and a regenerate-recovery-codes button. v3.2.0 (#347) replaces the manual
// paste with a real verify-and-persist flow, adds a Disable TOTP sub-card,
// surfaces per-recovery-code usage timestamps + IP, and a "last TOTP login"
// status row.
//
// Sub-sections under one outer .card:
//   - Status (always shown)
//   - Recovery codes (always shown)
//   - Enrol  (only when secret is empty)
//   - Disable (only when secret is set)
//
// Audit hooks:
//   totp.recovery.regenerate
//   totp.enrol.start
//   totp.enrol.verify.ok
//   totp.enrol.verify.fail
//   totp.disable
//   totp.disable.fail

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

$admin_ctx = admin_session_require();

header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

if (session_status() !== PHP_SESSION_ACTIVE) {
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
}

$csrf_seed   = ($admin_pass_hash ?? '') . '|' . ($_SERVER['REMOTE_ADDR'] ?? '');
$csrf_expect = hash('sha256', $csrf_seed);
$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$flash = ['success' => null, 'error' => null, 'codes' => null];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($csrf_expect, (string)($_POST['_csrf'] ?? ''))) {
        $flash['error'] = 'Invalid CSRF token. Reload the page and retry.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            $db = new \SQLite3(admin_apikey_db_path());
            $db->enableExceptions(true);
            $db->busyTimeout(1500);

            if ($action === 'regenerate_codes') {
                if (!admin_totp_enabled()) {
                    $flash['error'] = 'Enable TOTP first by completing the enrol flow.';
                } else {
                    $codes = admin_recovery_regenerate($db);
                    audit_log(
                        $db,
                        'totp.recovery.regenerate',
                        audit_actor_from_request(),
                        audit_ip_from_request(),
                        null,
                        ['count' => count($codes)]
                    );
                    $flash['codes']   = $codes;
                    $flash['success'] = 'Generated ' . count($codes) . ' recovery codes. Copy them now — they are not shown again.';
                }
            } elseif ($action === 'enrol_verify') {
                if (admin_totp_enabled()) {
                    $flash['error'] = 'TOTP is already enabled. Use Disable first if you need to re-enrol.';
                } else {
                    $cached = (string)($_SESSION['totp_enrol_secret'] ?? '');
                    $code   = (string)($_POST['code'] ?? '');
                    if ($cached === '') {
                        $flash['error'] = 'Enrol session expired. Reload and try again.';
                    } elseif (admin_totp_verify($cached, $code)) {
                        if (admin_totp_enrol_persist($cached)) {
                            audit_log(
                                $db,
                                'totp.enrol.verify.ok',
                                audit_actor_from_request(),
                                audit_ip_from_request()
                            );
                            unset($_SESSION['totp_enrol_secret'], $_SESSION['totp_enrol_started']);
                            $flash['success'] = 'TOTP enabled. Mint recovery codes next so you have a fallback.';
                        } else {
                            $flash['error'] = 'Verified, but failed to write config-admin.php. Check directory permissions.';
                        }
                    } else {
                        audit_log(
                            $db,
                            'totp.enrol.verify.fail',
                            audit_actor_from_request(),
                            audit_ip_from_request()
                        );
                        $flash['error'] = 'Code did not match. Check authenticator clock and try again.';
                    }
                }
            } elseif ($action === 'disable') {
                if (!admin_totp_enabled()) {
                    $flash['error'] = 'TOTP is already disabled.';
                } else {
                    $code = (string)($_POST['code'] ?? '');
                    $secret = (string)($admin_totp_secret ?? '');
                    // Non-destructive check: don't consume the recovery code
                    // before the disable write succeeds. admin_totp_disable()
                    // wipes admin_recovery_codes wholesale on success anyway,
                    // so consuming here is wasteful and strands the operator
                    // if the config-admin.php write fails.
                    $valid = $code !== ''
                        && (admin_totp_verify($secret, $code)
                            || admin_recovery_match($db, $code) !== null);
                    if (!$valid) {
                        audit_log(
                            $db,
                            'totp.disable.fail',
                            audit_actor_from_request(),
                            audit_ip_from_request()
                        );
                        $flash['error'] = 'Invalid code. Disable requires a valid current TOTP or recovery code.';
                    } else {
                        if (admin_totp_disable($db)) {
                            audit_log(
                                $db,
                                'totp.disable',
                                audit_actor_from_request(),
                                audit_ip_from_request()
                            );
                            // Clear any cached enrol session state so a
                            // re-enrol mints a fresh secret rather than
                            // resurrecting one from a prior aborted flow.
                            unset(
                                $_SESSION['totp_enrol_secret'],
                                $_SESSION['totp_enrol_started']
                            );
                            $flash['success'] = 'TOTP disabled. Anyone with the admin password can now sign in — re-enrol when ready.';
                        } else {
                            $flash['error'] = 'Failed to write config-admin.php. Check directory permissions.';
                        }
                    }
                }
            } else {
                $flash['error'] = 'Unknown action.';
            }
            $db->close();
        } catch (\Throwable $e) {
            error_log('sc admin totp page error: ' . $e->getMessage());
            $flash['error'] = 'Internal error processing the request.';
        }
    }
    $_SESSION['totp_flash'] = $flash;
    $self = strtok((string)($_SERVER['REQUEST_URI'] ?? '/admin/totp.php'), '?');
    header('Location: ' . $self, true, 303);
    exit;
}

$flash = $_SESSION['totp_flash'] ?? null;
unset($_SESSION['totp_flash']);
$success = is_array($flash) && isset($flash['success']) && is_string($flash['success']) ? $flash['success'] : null;
$error   = is_array($flash) && isset($flash['error'])   && is_string($flash['error'])   ? $flash['error']   : null;
$codes   = is_array($flash) && isset($flash['codes'])   && is_array($flash['codes'])    ? $flash['codes']   : null;

$enabled       = admin_totp_enabled();
$unused_count  = 0;
$total_count   = 0;
$last_totp_at  = null;
$recovery_rows = [];

try {
    $db = new \SQLite3(admin_apikey_db_path());
    $db->enableExceptions(true);
    $db->busyTimeout(1500);
    admin_recovery_db_init($db);
    if ($enabled) {
        $unused_count  = admin_recovery_unused_count($db);
        $recovery_rows = admin_recovery_list($db);
        $total_count   = count($recovery_rows);
        $last          = admin_state_get($db, 'last_totp_at');
        $last_totp_at  = $last !== null ? (int)$last : null;
    }
    $db->close();
} catch (\Throwable $e) {
    error_log('sc admin totp status error: ' . $e->getMessage());
}

$account = (string)($admin_user ?? 'admin');
$issuer  = parse_url((string)($canonical_url ?? 'subnet-calculator'), PHP_URL_HOST) ?: 'subnet-calculator';

// Generate (or reuse) an enrol secret if TOTP is currently disabled.
$enrol_secret = null;
$enrol_uri    = null;
if (!$enabled) {
    if (!isset($_SESSION['totp_enrol_secret']) || !is_string($_SESSION['totp_enrol_secret']) || $_SESSION['totp_enrol_secret'] === '') {
        $_SESSION['totp_enrol_secret'] = admin_totp_generate_secret();
    }
    $enrol_secret = (string)$_SESSION['totp_enrol_secret'];
    $enrol_uri    = admin_totp_provisioning_uri($enrol_secret, $account, $issuer);

    // Audit totp.enrol.start exactly once per enrol session, not on every GET.
    if (empty($_SESSION['totp_enrol_started'])) {
        try {
            $db = new \SQLite3(admin_apikey_db_path());
            $db->enableExceptions(true);
            $db->busyTimeout(1500);
            audit_log(
                $db,
                'totp.enrol.start',
                audit_actor_from_request(),
                audit_ip_from_request()
            );
            $db->close();
            $_SESSION['totp_enrol_started'] = true;
        } catch (\Throwable $e) {
            error_log('sc admin totp enrol-start audit error: ' . $e->getMessage());
        }
    }
}

$fmt_ts = static function (?int $ts): string {
    if ($ts === null) {
        return 'never';
    }
    return gmdate('Y-m-d H:i:s', $ts) . ' UTC';
};
$iso_ts = static function (?int $ts): string {
    if ($ts === null) {
        return '';
    }
    return gmdate('Y-m-d\TH:i:s\Z', $ts);
};

ob_start();
?>
<section class="admin-section" aria-labelledby="totp-heading">
    <h1 id="totp-heading">TOTP / 2FA <?= help_bubble('admin-totp', 'Time-based one-time passwords gate admin access. Recovery codes are single-use fallbacks for when the authenticator is lost.') ?></h1>
    <?php if ($success !== null) : ?>
        <div class="alert alert-success" role="status"><?= $h($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== null) : ?>
        <div class="alert alert-error" role="alert"><?= $h($error) ?></div>
    <?php endif; ?>
    <?php if ($codes !== null) : ?>
        <div class="alert alert-accent" role="alert">
            <strong>New recovery codes</strong>
            <p>Copy these now. Each is single-use. They will not be shown again.</p>
            <pre class="api-key-display"><?php foreach ($codes as $c) : ?><?= $h($c) ?>
<?php endforeach; ?></pre>
        </div>
    <?php endif; ?>
</section>

<section class="admin-section" aria-labelledby="totp-status-heading">
    <h2 id="totp-status-heading">Status</h2>
    <p>
        <?php if ($enabled) : ?>
            <span class="badge badge-active">enabled</span>
        <?php else : ?>
            <span class="badge badge-revoked">disabled</span>
        <?php endif; ?>
    </p>
    <?php if ($enabled) : ?>
        <p>
            Last TOTP login:
            <?php if ($last_totp_at !== null) : ?>
                <time datetime="<?= $h($iso_ts($last_totp_at)) ?>"><?= $h($fmt_ts($last_totp_at)) ?></time>
            <?php else : ?>
                <span class="admin-meta-note">never</span>
            <?php endif; ?>
        </p>
        <p>
            Recovery codes: <strong><?= (int)$unused_count ?> unused</strong>
            / <?= (int)$total_count ?> total
        </p>
    <?php endif; ?>
</section>

<?php if (!$enabled && $enrol_secret !== null && $enrol_uri !== null) : ?>
    <section class="admin-section" aria-labelledby="totp-enrol-heading">
        <h2 id="totp-enrol-heading">Enrol</h2>
        <p>Add this secret to your authenticator app (Google Authenticator, 1Password, Authy, etc.):</p>
        <p><strong>Manual entry secret:</strong> <code class="api-key-inline"><?= $h($enrol_secret) ?></code></p>
        <details>
            <summary>Show <code>otpauth://</code> URI (paste into a QR generator if your app needs one)</summary>
            <pre class="api-key-display"><?= $h($enrol_uri) ?></pre>
        </details>
        <form method="post" action="" class="admin-form-stack">
            <input type="hidden" name="_csrf" value="<?= $h($csrf_expect) ?>">
            <input type="hidden" name="action" value="enrol_verify">
            <label for="totp-enrol-code">Verify with the 6-digit code from your authenticator</label>
            <input id="totp-enrol-code" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}"
                   maxlength="6" autocomplete="one-time-code" required>
            <button class="splitter-btn" type="submit">Verify and enable</button>
        </form>
    </section>
<?php endif; ?>

<?php if ($enabled) : ?>
    <section class="admin-section" aria-labelledby="totp-recovery-heading">
        <h2 id="totp-recovery-heading">Recovery codes</h2>
        <p class="admin-meta-note">Codes are shown once at generation and cannot be retrieved later. Store them in a password manager. Use <em>Regenerate</em> to mint a fresh set; this invalidates all previous codes (used or unused).</p>
        <?php if ($recovery_rows !== []) : ?>
            <details class="admin-recovery-list">
                <summary>Show usage list (<?= (int)$total_count ?> codes)</summary>
                <ul class="admin-recovery-rows">
                    <?php foreach ($recovery_rows as $row) :
                        $isUsed = $row['used_at'] !== null;
                        ?>
                        <li class="admin-recovery-row<?= $isUsed ? ' is-used' : '' ?>">
                            <span class="mono">#<?= (int)$row['id'] ?></span>
                            <?php if ($isUsed) : ?>
                                <span>used <time datetime="<?= $h($iso_ts($row['used_at'])) ?>"><?= $h($fmt_ts($row['used_at'])) ?></time>
                                <?php if ($row['used_via_ip'] !== null) : ?>
                                    from <span class="mono"><?= $h($row['used_via_ip']) ?></span>
                                <?php endif; ?>
                                </span>
                            <?php else : ?>
                                <span>unused</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>
        <form method="post" action="" onsubmit="return confirm('This will invalidate any existing recovery codes. Continue?');">
            <input type="hidden" name="_csrf" value="<?= $h($csrf_expect) ?>">
            <input type="hidden" name="action" value="regenerate_codes">
            <button class="splitter-btn" type="submit"><?= $unused_count === 0 && $total_count === 0 ? 'Mint recovery codes' : 'Regenerate recovery codes' ?></button>
        </form>
    </section>

    <section class="admin-section" aria-labelledby="totp-disable-heading">
        <h2 id="totp-disable-heading">Disable TOTP</h2>
        <p class="admin-meta-note">This removes the second factor from <code>/admin/</code> login. Anyone with your password can sign in.</p>
        <form method="post" action="" class="admin-form-stack" onsubmit="return confirm('Disable TOTP? Anyone with the admin password can sign in until you re-enrol.');">
            <input type="hidden" name="_csrf" value="<?= $h($csrf_expect) ?>">
            <input type="hidden" name="action" value="disable">
            <label for="totp-disable-code">Confirm with current TOTP or recovery code</label>
            <input id="totp-disable-code" name="code" type="text" inputmode="text"
                   maxlength="20" autocomplete="one-time-code" required>
            <button class="splitter-btn btn-danger" type="submit">Disable TOTP</button>
        </form>
    </section>
<?php endif; ?>
<?php
$admin_card_body       = ob_get_clean();
$page_title            = 'TOTP / 2FA';
$admin_breadcrumb      = 'TOTP / 2FA';
$admin_user_signed_in  = (string)($admin_ctx['user'] ?? '');
$admin_csrf_token      = (string)($admin_ctx['csrf'] ?? '');
require __DIR__ . '/../templates/_admin_layout.php';
