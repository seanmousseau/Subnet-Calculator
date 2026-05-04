<?php

declare(strict_types=1);

/**
 * Admin HTTP Basic Auth (v2.12.0, #297).
 *
 * Operators set $admin_ui_enabled, $admin_user, and $admin_pass_hash
 * (PASSWORD_BCRYPT) in config.php. There is no first-run wizard — the
 * password hash is generated out-of-band, e.g.
 *
 *   php -r "echo password_hash('mysecret', PASSWORD_BCRYPT) . PHP_EOL;"
 *
 * and pasted into config.php. We re-authenticate every request; there is
 * intentionally no session cookie to expire.
 *
 * The `$onFail` callback exists so the same middleware can serve both HTML
 * pages (where a 401 + WWW-Authenticate prompts a browser dialog) and JSON
 * APIs (where we want a plain envelope).
 */

/**
 * @param callable $onFail  Invoked with (string $reason, int $status) before exit.
 *                          Must terminate; default sends a Basic Auth challenge.
 */
function admin_authenticate(?callable $onFail = null): void
{
    global $admin_ui_enabled, $admin_user, $admin_pass_hash;

    if ($onFail === null) {
        $onFail = static function (string $reason, int $status): void {
            header('WWW-Authenticate: Basic realm="Subnet Calculator Admin", charset="UTF-8"');
            http_response_code($status);
            echo '<!doctype html><meta charset="utf-8"><title>Admin</title>'
                . '<p>' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . '</p>';
            exit;
        };
    }

    if (!$admin_ui_enabled) {
        $onFail('Admin UI is disabled.', 404);
        return; // unreachable, callbacks always exit
    }
    if (
        !is_string($admin_user) || $admin_user === ''
        || !is_string($admin_pass_hash) || $admin_pass_hash === ''
    ) {
        $onFail('Admin UI enabled but $admin_user / $admin_pass_hash not configured.', 503);
        return;
    }

    $u = $_SERVER['PHP_AUTH_USER'] ?? '';
    $p = $_SERVER['PHP_AUTH_PW']   ?? '';

    if (!is_string($u) || !is_string($p) || $u === '' || $p === '') {
        $onFail('Authentication required.', 401);
        return;
    }

    // Constant-time username compare; bcrypt verify is itself constant-time
    // for matching-cost hashes.
    if (!hash_equals($admin_user, $u) || !password_verify($p, $admin_pass_hash)) {
        admin_audit_login_outcome(false, $u);
        $onFail('Invalid credentials.', 401);
        return;
    }

    // TOTP step-up (v3.0.0, #313). Only enforced when the operator has set
    // $admin_totp_secret. UI callers complete a form posted to
    // /admin/totp-verify.php; API callers send X-Admin-TOTP. Both paths
    // hit admin_totp_check_step_up() which returns true on success. The
    // TOTP module is only loaded when needed so non-admin requests don't
    // pay for it.
    if (admin_totp_step_up_required()) {
        require_once __DIR__ . '/functions-admin-totp.php';
        if (!admin_totp_check_step_up($u, $onFail)) {
            return;
        }
    }

    admin_audit_login_outcome(true, $u);
}

/**
 * Whether TOTP step-up is required for the current request. Wraps the
 * config check in a function so callers don't have to import the global.
 */
function admin_totp_step_up_required(): bool
{
    global $admin_totp_secret;
    return is_string($admin_totp_secret ?? null) && $admin_totp_secret !== '';
}

/**
 * Run after password verify when TOTP is enabled. Returns true if the
 * caller is allowed through; otherwise calls $onFail (which exits).
 *
 * - API requests (no PHP session, X-Admin-TOTP header present) are
 *   verified against the live TOTP code on every request — the header
 *   acts as a per-request second factor.
 * - UI requests with an active sc_admin session whose `totp_verified_at`
 *   is within ADMIN_TOTP_SESSION_TTL pass without re-prompting. Otherwise
 *   the caller is redirected to /admin/totp-verify.php.
 *
 * Recovery codes can substitute for either path (header value `recovery:CODE`
 * or the verify form's recovery input).
 */
function admin_totp_check_step_up(string $user, callable $onFail): bool
{
    $hdr = $_SERVER['HTTP_X_ADMIN_TOTP'] ?? '';
    if (is_string($hdr) && $hdr !== '') {
        if (admin_totp_consume_attempt($user, (string)$hdr)) {
            return true;
        }
        admin_audit_event('login.totp.fail', $user, ['via' => 'header']);
        $onFail('Invalid or missing TOTP code.', 401);
        return false;
    }

    // UI session-cached step-up. We start the session lazily so non-UI
    // callers (e.g. API) don't pay for one.
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
        @session_start();
    }
    $verifiedAt = isset($_SESSION['totp_verified_at']) && is_int($_SESSION['totp_verified_at'])
        ? $_SESSION['totp_verified_at'] : 0;
    $verifiedFor = isset($_SESSION['totp_verified_user']) && is_string($_SESSION['totp_verified_user'])
        ? $_SESSION['totp_verified_user'] : '';
    if (
        $verifiedFor === $user
        && $verifiedAt > 0
        && (time() - $verifiedAt) < ADMIN_TOTP_SESSION_TTL
    ) {
        return true;
    }

    // Not verified or stale — redirect UI users to the verify page.
    // A POST to keys.php (e.g. mint key) without TOTP shouldn't silently
    // succeed; a 303 forces a GET on the redirect target.
    $selfRaw = $_SERVER['REQUEST_URI'] ?? '';
    $self    = is_string($selfRaw) ? $selfRaw : '';
    $_SESSION['totp_return_to'] = $self !== '' ? $self : '/admin/keys.php';
    header('Location: /admin/totp-verify.php', true, 303);
    exit;
}

/**
 * Verify either a TOTP code or a recovery code; audit-log the outcome.
 * Returns true on success.
 */
function admin_totp_consume_attempt(string $user, string $submitted): bool
{
    global $admin_totp_secret;
    require_once __DIR__ . '/functions-admin-totp.php';

    $submitted = trim($submitted);
    // Recovery codes are 8 base32 chars (optionally split with `-`); TOTP
    // is 6 digits. We classify by shape so a TOTP look-alike isn't tried
    // against the recovery table.
    $isRecovery = (bool)preg_match('/^[A-Za-z2-7]{4}-?[A-Za-z2-7]{4}$/', $submitted);
    if ($isRecovery) {
        require_once __DIR__ . '/functions-apikeys.php';
        try {
            $db = new \SQLite3(admin_apikey_db_path());
            $db->enableExceptions(true);
            $db->busyTimeout(1500);
            $rowId = admin_recovery_verify_and_consume($db, $submitted);
            if ($rowId !== null) {
                admin_audit_event('totp.recovery.use', $user, ['code_id' => $rowId]);
                $db->close();
                return true;
            }
            $db->close();
        } catch (\Throwable $e) {
            error_log('sc admin totp recovery verify error: ' . $e->getMessage());
        }
        return false;
    }
    if (admin_totp_verify((string)$admin_totp_secret, $submitted)) {
        return true;
    }
    return false;
}

/**
 * Best-effort wrapper around audit_log() that swallows DB errors and only
 * loads the audit module when needed. Callers in the auth path use this so
 * audit failures never block login decisions.
 *
 * @param array<string, mixed>|null $meta
 */
function admin_audit_event(string $action, ?string $actor, ?array $meta = null): void
{
    static $loaded = false;
    if (!$loaded) {
        $audit_path = __DIR__ . '/functions-audit.php';
        if (!is_file($audit_path)) {
            return;
        }
        require_once $audit_path;
        $loaded = true;
    }
    if (!function_exists('audit_log') || !function_exists('audit_ip_from_request')) {
        return;
    }
    try {
        $db = new \SQLite3(admin_apikey_db_path());
        $db->enableExceptions(true);
        $db->busyTimeout(1500);
        audit_log($db, $action, $actor, audit_ip_from_request(), null, $meta);
        $db->close();
    } catch (\Throwable $e) {
        error_log('sc admin_audit_event error: ' . $e->getMessage());
    }
}

/**
 * Best-effort audit log entry for an admin auth attempt. Lazily loads
 * functions-audit.php so the audit module remains optional at install time
 * (some self-hosters may strip the admin DB writeability for read-only
 * inspection). Any error is swallowed — auth must remain authoritative.
 */
function admin_audit_login_outcome(bool $ok, string $attemptedUser): void
{
    static $loaded = false;
    if (!$loaded) {
        $audit_path = __DIR__ . '/functions-audit.php';
        if (!is_file($audit_path)) {
            return;
        }
        require_once $audit_path;
        $loaded = true;
    }
    if (!function_exists('audit_log') || !function_exists('audit_ip_from_request')) {
        return;
    }
    try {
        $db = new \SQLite3(admin_apikey_db_path());
        $db->enableExceptions(true);
        $db->busyTimeout(1500);
        audit_log(
            $db,
            $ok ? 'login.ok' : 'login.fail',
            $attemptedUser !== '' ? $attemptedUser : null,
            audit_ip_from_request(),
            null,
            null
        );
        $db->close();
    } catch (\Throwable $e) {
        error_log('sc admin_audit_login_outcome error: ' . $e->getMessage());
    }
}

/**
 * Resolve the SQLite path used for the api_keys table.
 *
 * Reuses $apikey_db_path when set, then $session_db_path, then defaults
 * to <docroot>/data/sessions.sqlite (matching where the rate_limit table
 * already lives). Creates the parent directory on first call.
 */
function admin_apikey_db_path(): string
{
    global $apikey_db_path, $session_db_path;
    if (is_string($apikey_db_path ?? null) && $apikey_db_path !== '') {
        $path = $apikey_db_path;
    } elseif (is_string($session_db_path ?? null) && $session_db_path !== '') {
        $path = $session_db_path;
    } else {
        $path = dirname(__DIR__) . '/data/sessions.sqlite';
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $path;
}
