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
        $onFail('Invalid credentials.', 401);
        return;
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
