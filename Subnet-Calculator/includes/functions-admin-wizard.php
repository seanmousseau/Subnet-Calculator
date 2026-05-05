<?php

declare(strict_types=1);

// First-run admin wizard (v3.0.0, #311).
//
// When the operator has set $admin_ui_enabled = true but has not yet
// configured $admin_user / $admin_pass_hash, the wizard offers a one-shot
// setup form on /admin/keys.php that writes Subnet-Calculator/config-admin.php
// — a sibling to the hand-edited config.php that the upgrade flow never
// touches. Once the password hash is set, the wizard self-locks and a
// repeat visitor sees the regular admin auth challenge.
//
// Why a separate file rather than mutating config.php? The agreed design in
// docs/superpowers/plans/v3.0.0-living-doc.md is "$admin_pass_hash is the
// wizard's only output; config.php remains hand-edited". config.php is
// loaded first; config-admin.php only fills gaps. An operator can override
// the wizard's output by setting the value in config.php, and the wizard
// will refuse to overwrite a non-empty hash.

const ADMIN_WIZARD_USER_MIN     = 1;
const ADMIN_WIZARD_USER_MAX     = 64;
const ADMIN_WIZARD_PASSWORD_MIN = 12;
const ADMIN_WIZARD_PASSWORD_MAX = 256;

/**
 * Wizard is required when admin UI is enabled but no password hash is set.
 * Once an operator pastes a hash into config.php (or the wizard writes one
 * to config-admin.php), this returns false forever.
 */
function admin_wizard_needed(): bool
{
    global $admin_ui_enabled, $admin_pass_hash;
    return (bool)$admin_ui_enabled
        && (!is_string($admin_pass_hash) || $admin_pass_hash === '');
}

/**
 * Absolute path to the wizard-managed config file. Sibling of config.php
 * inside the Subnet-Calculator/ docroot. Independent of the config.php
 * lookup so a user-renamed root won't strand the file.
 */
function admin_wizard_config_path(): string
{
    $override = admin_wizard_set_path_for_tests();
    if ($override !== null) {
        return $override;
    }
    return dirname(__DIR__) . '/config-admin.php';
}

/**
 * Test-only override for admin_wizard_config_path().  Pass a string to set
 * the override; pass null to clear it; pass nothing to read the current
 * value.  Production callers never invoke this with an argument — keeping
 * the override behind a function (rather than a static global) keeps the
 * surface area auditable.
 */
function admin_wizard_set_path_for_tests(?string $path = null, bool $clear = false): ?string
{
    static $override = null;
    if ($clear || ($path === null && func_num_args() > 0)) {
        $override = null;
        return null;
    }
    if ($path !== null) {
        $override = $path;
    }
    return $override;
}

/**
 * Validate a wizard submission, returning a list of human-readable errors
 * (empty array = valid). Lives separately from the writer so unit tests
 * can exercise the rules without touching the filesystem.
 *
 * @return array<int, string>
 */
function admin_wizard_validate(string $user, string $password, string $passwordConfirm): array
{
    $errors = [];

    $userTrim = trim($user);
    if ($userTrim === '') {
        $errors[] = 'Username is required.';
    } elseif (strlen($userTrim) > ADMIN_WIZARD_USER_MAX) {
        $errors[] = sprintf(
            'Username must be %d–%d characters.',
            ADMIN_WIZARD_USER_MIN,
            ADMIN_WIZARD_USER_MAX
        );
    } elseif (preg_match('/[\\x00-\\x1f\\x7f:]/', $userTrim)) {
        // Control chars and ':' break HTTP Basic Auth.
        $errors[] = 'Username may not contain control characters or ":".';
    }

    if ($password === '') {
        $errors[] = 'Password is required.';
    } elseif (
        strlen($password) < ADMIN_WIZARD_PASSWORD_MIN
        || strlen($password) > ADMIN_WIZARD_PASSWORD_MAX
    ) {
        $errors[] = sprintf(
            'Password must be %d–%d characters.',
            ADMIN_WIZARD_PASSWORD_MIN,
            ADMIN_WIZARD_PASSWORD_MAX
        );
    }

    if (!hash_equals($password, $passwordConfirm)) {
        $errors[] = 'Password confirmation does not match.';
    }

    return $errors;
}

/**
 * Persist the wizard output to config-admin.php. Atomic write via
 * temp-file + rename so a partial write can't lock the operator out.
 *
 * Returns:
 *   ['ok' => true]                                — file written, wizard self-locks on next request
 *   ['ok' => false, 'reason' => string,
 *    'snippet' => string]                         — write failed; operator must paste $snippet
 *                                                   into config.php (matches the historic
 *                                                   "php -r \"echo password_hash...\"" pattern)
 *
 * @return array{ok:bool, reason?:string, snippet?:string, path?:string}
 */
function admin_wizard_write(string $user, string $passwordHash): array
{
    // password_get_info() returns algoName 'unknown' for malformed strings
    // like '$bad' that pass a naive leading-`$` check.  Restrict to bcrypt
    // explicitly so a self-locking malformed hash never reaches disk.
    $info = $passwordHash !== '' ? password_get_info($passwordHash) : ['algoName' => 'unknown'];
    if (
        $passwordHash === ''
        || ($info['algoName'] ?? 'unknown') !== 'bcrypt'
    ) {
        return [
            'ok'      => false,
            'reason'  => 'Refusing to write an empty or non-bcrypt password hash.',
            'snippet' => admin_wizard_snippet($user, $passwordHash),
        ];
    }

    $path    = admin_wizard_config_path();
    $snippet = admin_wizard_snippet($user, $passwordHash);

    // Refuse to overwrite an existing config-admin.php — once the wizard
    // has run, only an operator should edit the file.
    if (file_exists($path)) {
        return [
            'ok'      => false,
            'reason'  => 'config-admin.php already exists. Edit or remove it manually.',
            'snippet' => $snippet,
            'path'    => $path,
        ];
    }

    $body = "<?php\n\n"
        . "// Auto-generated by the admin first-run wizard (v3.0.0, #311).\n"
        . "// This file is loaded after config.php; values here only fill gaps.\n"
        . "// Safe to edit by hand. Safe to delete to re-run the wizard.\n"
        . "// Generated " . gmdate('Y-m-d H:i:s') . " UTC.\n\n"
        . '$admin_user      = ' . var_export($user, true) . ";\n"
        . '$admin_pass_hash = ' . var_export($passwordHash, true) . ";\n";

    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
        return [
            'ok'      => false,
            'reason'  => 'Cannot write to ' . $path . ' — the directory is not writable by the web server. '
                       . 'Paste the snippet below into config.php instead.',
            'snippet' => $snippet,
            'path'    => $path,
        ];
    }

    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $body, LOCK_EX) === false) {
        return [
            'ok'      => false,
            'reason'  => 'Failed to write ' . $tmp . '. Paste the snippet below into config.php instead.',
            'snippet' => $snippet,
            'path'    => $path,
        ];
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, $path)) {
        // $tmp is fully server-derived: dirname(__DIR__) + suffix from
        // random_bytes(); no user input touches the path.
        // nosemgrep: php.lang.security.unlink-use.unlink-use
        @unlink($tmp);
        return [
            'ok'      => false,
            'reason'  => 'Failed to finalise ' . $path . '. Paste the snippet below into config.php instead.',
            'snippet' => $snippet,
            'path'    => $path,
        ];
    }

    return ['ok' => true, 'path' => $path];
}

/**
 * Atomically write the given body to a path (tmp file + rename). Used by
 * the wizard write and the v3.2.0 #347 enrol/disable merge helper.
 */
function admin_config_admin_atomic_write(string $path, string $body): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
        return false;
    }
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $body, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, $path)) {
        // $tmp is fully server-derived; no user input touches the path.
        // nosemgrep: php.lang.security.unlink-use.unlink-use
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Merge a set of `$key => value` pairs into config-admin.php (v3.2.0+, #347).
 * Used by `admin_totp_enrol_persist()` and `admin_totp_disable()` to set or
 * clear `$admin_totp_secret` without disturbing other wizard-written values.
 *
 * Behaviour:
 *   - If the file doesn't exist, creates it with just the supplied keys
 *     (plus the standard auto-generated header).
 *   - If the file exists, the given keys are replaced in-place if already
 *     present, otherwise appended at the end. Other lines are left
 *     untouched.
 *
 * Only callers under existing CSRF + admin auth gates should invoke this —
 * the helper itself has no auth check.
 *
 * NOTE for maintainers: values must be single-line `var_export()` output.
 * Multi-line literals (e.g. array `[...,...,]` written across multiple
 * lines) will not match the in-place replacement regex and will be
 * appended on every call instead of replaced.
 *
 * @param array<string, string> $kv variable name (without `$`) → value
 */
function admin_config_admin_set_keys(array $kv): bool
{
    $path = admin_wizard_config_path();

    if (!file_exists($path)) {
        $body = "<?php\n\n"
            . "// Auto-generated by the admin v3.2.0 update flow (#347).\n"
            . "// This file is loaded after config.php; values here only fill gaps.\n"
            . "// Safe to edit by hand. Safe to delete to re-run the wizard.\n"
            . "// Generated " . gmdate('Y-m-d H:i:s') . " UTC.\n\n";
        foreach ($kv as $k => $v) {
            $body .= '$' . $k . ' = ' . var_export($v, true) . ";\n";
        }
        return admin_config_admin_atomic_write($path, $body);
    }

    $existing = (string)file_get_contents($path);
    foreach ($kv as $k => $v) {
        $line = '$' . $k . ' = ' . var_export($v, true) . ';';
        $pattern = '/^\s*\$' . preg_quote($k, '/') . '\s*=\s*[^;]*;\s*$/m';
        if (preg_match($pattern, $existing)) {
            $replaced = preg_replace($pattern, $line, $existing, 1);
            if ($replaced !== null) {
                $existing = $replaced;
            }
        } else {
            $existing = rtrim($existing, " \t\n\r") . "\n" . $line . "\n";
        }
    }
    return admin_config_admin_atomic_write($path, $existing);
}

/**
 * Build the copy-pasteable PHP fallback shown when the writer cannot save
 * config-admin.php (e.g. read-only deployment, immutable container layer).
 * The operator pastes the snippet into config.php and the wizard self-locks
 * on the next request just as if it had written the file itself.
 */
function admin_wizard_snippet(string $user, string $passwordHash): string
{
    return '$admin_user      = ' . var_export($user, true) . ";\n"
         . '$admin_pass_hash = ' . var_export($passwordHash, true) . ';';
}
