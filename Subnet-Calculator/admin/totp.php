<?php

declare(strict_types=1);

// /admin/totp.php — TOTP enrollment + recovery-code management (v3.0.0, #313).
//
// Two operating modes, distinguished by whether $admin_totp_secret is set:
//
//   1. Disabled — page exposes a "Generate a secret" helper. The operator
//      scans / pastes the secret into their authenticator app, then pastes
//      the matching `$admin_totp_secret = '…';` snippet into config.php
//      (or config-admin.php) and refreshes. We deliberately don't
//      auto-write the secret to disk: a wrong secret + no recovery codes
//      yet would lock the operator out, and we want the manual paste step
//      as a deliberate confirmation.
//
//   2. Enabled — page shows status (unused recovery code count + last
//      audit timestamp), and surfaces "Regenerate recovery codes" and
//      "Mint codes (first time)" actions. Mints are protected by
//      successful TOTP step-up via the standard admin_authenticate()
//      pipeline that gates this page.
//
// Audit hooks:
//   totp.recovery.regenerate — set whenever recovery codes are minted or rotated.

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

// PRG flash carrier. The freshly-minted recovery codes pass through here
// so a refresh after the mint doesn't replay the action and re-mint.
// admin_authenticate() (called via the admin/keys.php boot) already
// configured the sc_admin session; we only need to (re-)start it here in
// case this page is hit directly without the keys.php prelude.
if (session_status() !== PHP_SESSION_ACTIVE) {
    // Match the hardened sc_admin cookie params used by admin_authenticate()
    // for the standalone-load path (e.g. opening /admin/totp.php directly).
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $flash = ['success' => null, 'error' => null, 'codes' => null, 'gen_secret' => null];
    if (!hash_equals($csrf_expect, (string)($_POST['_csrf'] ?? ''))) {
        $flash['error'] = 'Invalid CSRF token. Reload the page and retry.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'generate_secret') {
                $flash['gen_secret'] = admin_totp_generate_secret();
            } elseif ($action === 'regenerate_codes') {
                if (!admin_totp_enabled()) {
                    $flash['error'] = 'Enable TOTP first by setting $admin_totp_secret in config.php.';
                } else {
                    $db = new \SQLite3(admin_apikey_db_path());
                    $db->enableExceptions(true);
                    $db->busyTimeout(1500);
                    $codes = admin_recovery_regenerate($db);
                    audit_log(
                        $db,
                        'totp.recovery.regenerate',
                        audit_actor_from_request(),
                        audit_ip_from_request(),
                        null,
                        ['count' => count($codes)]
                    );
                    $db->close();
                    $flash['codes']   = $codes;
                    $flash['success'] = 'Generated ' . count($codes) . ' recovery codes. Copy them now — they are not shown again.';
                }
            } else {
                $flash['error'] = 'Unknown action.';
            }
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
$success    = is_array($flash) && isset($flash['success']) && is_string($flash['success']) ? $flash['success'] : null;
$error      = is_array($flash) && isset($flash['error'])   && is_string($flash['error'])   ? $flash['error']   : null;
$codes      = is_array($flash) && isset($flash['codes'])   && is_array($flash['codes'])    ? $flash['codes']   : null;
$gen_secret = is_array($flash) && isset($flash['gen_secret']) && is_string($flash['gen_secret']) ? $flash['gen_secret'] : null;

$enabled       = admin_totp_enabled();
$unused_count  = 0;
if ($enabled) {
    try {
        $db = new \SQLite3(admin_apikey_db_path());
        $db->enableExceptions(true);
        $db->busyTimeout(1500);
        $unused_count = admin_recovery_unused_count($db);
        $db->close();
    } catch (\Throwable $e) {
        error_log('sc admin totp count error: ' . $e->getMessage());
    }
}

$account = (string)($admin_user ?? 'admin');
$issuer  = parse_url((string)($canonical_url ?? 'subnet-calculator'), PHP_URL_HOST) ?: 'subnet-calculator';

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
            &nbsp;Unused recovery codes: <strong><?= (int)$unused_count ?></strong>
        <?php else : ?>
            <span class="badge badge-revoked">disabled</span>
            &nbsp;Set <code>$admin_totp_secret</code> in <code>config.php</code> to enable.
        <?php endif; ?>
    </p>
</section>

<?php if (!$enabled) : ?>
    <section class="admin-section" aria-labelledby="totp-secret-heading">
        <h2 id="totp-secret-heading">Generate a secret</h2>
        <?php if ($gen_secret !== null) :
            $uri = admin_totp_provisioning_uri($gen_secret, $account, $issuer); ?>
            <p>Add the following snippet to <code>Subnet-Calculator/config.php</code> (or <code>config-admin.php</code>) and refresh this page:</p>
            <pre class="api-key-display">$admin_totp_secret = <?= $h(var_export($gen_secret, true)) ?>;</pre>
            <p>Scan this URI with your authenticator (Google Authenticator, 1Password, Authy, etc.):</p>
            <pre class="api-key-display"><?= $h($uri) ?></pre>
            <p>Or paste the raw base32 secret into the app: <code><?= $h($gen_secret) ?></code></p>
            <p class="admin-meta-note"><strong>Important:</strong> after enabling, return here and click <em>Mint recovery codes</em> so you have a way back in if you lose the authenticator.</p>
        <?php else : ?>
            <form method="post" action="">
                <input type="hidden" name="_csrf" value="<?= $h($csrf_expect) ?>">
                <input type="hidden" name="action" value="generate_secret">
                <button class="splitter-btn" type="submit">Generate a secret</button>
            </form>
        <?php endif; ?>
    </section>
<?php else : ?>
    <section class="admin-section" aria-labelledby="totp-recovery-heading">
        <h2 id="totp-recovery-heading">Recovery codes</h2>
        <p>
            <?php if ($unused_count === 0) : ?>
                No recovery codes are configured. Mint a set now — without them, losing your authenticator means losing admin access.
            <?php else : ?>
                Regenerate to invalidate the existing codes and mint a fresh set of <?= ADMIN_RECOVERY_CODE_COUNT ?>.
            <?php endif; ?>
        </p>
        <form method="post" action="" onsubmit="return confirm('This will invalidate any existing recovery codes. Continue?');">
            <input type="hidden" name="_csrf" value="<?= $h($csrf_expect) ?>">
            <input type="hidden" name="action" value="regenerate_codes">
            <button class="splitter-btn" type="submit"><?= $unused_count === 0 ? 'Mint recovery codes' : 'Regenerate recovery codes' ?></button>
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
