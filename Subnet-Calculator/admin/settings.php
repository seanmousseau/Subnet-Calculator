<?php

declare(strict_types=1);

// /admin/settings.php — operator settings for ~20 config.php knobs (v3.2.0+, #349).
//
// Per-section <form> + Save button. Schema-driven validation. Atomic write
// of config-admin.php with php -l + opcache_invalidate. Audit one
// `config.update` row per actual change (secrets redacted).

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions-util.php';
require __DIR__ . '/../includes/functions-admin-auth.php';
require __DIR__ . '/../includes/functions-apikeys.php';
require __DIR__ . '/../includes/functions-audit.php';
require __DIR__ . '/../includes/functions-admin-wizard.php';
require __DIR__ . '/../includes/functions-admin-settings.php';

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

// Use the session-row CSRF token (rotated on login + step-up) rather
// than an IP-bound hash. CSRF lives on $admin_ctx['csrf'] from
// admin_session_require().
$csrf_expect = (string)($admin_ctx['csrf'] ?? '');
$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$schema = settings_schema();
$flash  = ['success' => null, 'error' => null, 'errors' => []];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($csrf_expect === '' || !hash_equals($csrf_expect, (string)($_POST['_csrf'] ?? ''))) {
        $flash['error'] = 'Invalid CSRF token. Reload the page and retry.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $section = (string)($_POST['section'] ?? '');
        // Per-row reset button: a save-form button named "reset_for"
        // hijacks the action.
        if (!empty($_POST['reset_for']) && is_string($_POST['reset_for'])) {
            $action  = 'reset';
            $_POST['key'] = $_POST['reset_for'];
        }

        try {
            $db = new \SQLite3(admin_apikey_db_path());
            $db->enableExceptions(true);
            $db->busyTimeout(1500);

            if ($action === 'save' && in_array($section, ADMIN_SETTINGS_SECTIONS, true)) {
                $writability = settings_writability_error();
                if ($writability !== null) {
                    $flash['error'] = 'Cannot save: ' . $writability;
                } else {
                    // Resolve layered state once so we can skip keys that
                    // are pinned in config.php (the UI renders them as
                    // disabled, but a bool checkbox absence would otherwise
                    // be coerced to false here).
                    $layeredForSave = settings_load_layered();
                    $sectionKeys = [];
                    foreach ($schema as $k => $entry) {
                        if (($entry['section'] ?? null) !== $section) {
                            continue;
                        }
                        if (($layeredForSave[$k]['source'] ?? '') === 'config') {
                            continue;
                        }
                        $sectionKeys[] = $k;
                    }
                    $valid = [];
                    $errors = [];
                    foreach ($sectionKeys as $k) {
                        $entry = $schema[$k];
                        $isSecret = !empty($entry['secret']);

                        if ($entry['type'] === 'bool') {
                            // Unchecked checkboxes don't POST. Treat absence as false.
                            $raw = array_key_exists($k, $_POST) ? $_POST[$k] : '0';
                        } elseif ($isSecret) {
                            // Secret inputs: empty submission means "no change".
                            $raw = (string)($_POST[$k] ?? '');
                            if ($raw === '') {
                                continue;
                            }
                        } else {
                            $raw = $_POST[$k] ?? null;
                            if ($raw === null) {
                                continue;
                            }
                        }
                        $r = settings_validate($k, $raw, $schema);
                        if (!$r['ok']) {
                            $errors[$k] = $r['error'];
                        } else {
                            $valid[$k] = $r['value'];
                        }
                    }
                    if ($errors !== []) {
                        $flash['errors'] = $errors;
                        $flash['error'] = 'Some fields had errors — settings not saved.';
                    } else {
                        // Track before-values for the audit log.
                        $before = settings_parse_config_file(admin_wizard_config_path(), array_keys($valid));
                        $result = settings_save($valid);
                        if (!$result['ok']) {
                            $flash['error'] = 'Failed to save: ' . ($result['error'] ?? 'unknown error');
                        } else {
                            foreach ($result['written'] as $k) {
                                $entry = $schema[$k];
                                $isSecret = !empty($entry['secret']);
                                audit_log(
                                    $db,
                                    'config.update',
                                    audit_actor_from_request(),
                                    audit_ip_from_request(),
                                    null,
                                    [
                                        'key'    => $k,
                                        'before' => settings_audit_redact($before[$k] ?? $entry['default'] ?? null, $isSecret),
                                        'after'  => settings_audit_redact($valid[$k], $isSecret),
                                    ]
                                );
                            }
                            $count = count($result['written']);
                            $flash['success'] = $count === 0
                                ? 'No changes to save.'
                                : "Saved {$count} setting" . ($count === 1 ? '' : 's') . '.';
                        }
                    }
                }
            } elseif ($action === 'reset') {
                $key = (string)($_POST['key'] ?? '');
                $layeredForReset = settings_load_layered();
                if (isset($schema[$key]) && (($layeredForReset[$key]['source'] ?? '') === 'config')) {
                    $flash['error'] = "Cannot reset {$key}: it is locked in config.php.";
                } elseif (isset($schema[$key])) {
                    $existing = settings_parse_config_file(admin_wizard_config_path(), [$key]);
                    if (array_key_exists($key, $existing)) {
                        // Remove the admin-tier override so the row sources
                        // from the schema default again. (Writing the default
                        // value back would leave the row sourcing from `admin`
                        // and the source badge would lie.)
                        $entry = $schema[$key];
                        $isSecret = !empty($entry['secret']);
                        $defaultValue = $entry['default'] ?? '';
                        if (admin_config_admin_unset_keys([$key])) {
                            if (function_exists('opcache_invalidate')) {
                                @opcache_invalidate(admin_wizard_config_path(), true);
                            }
                            audit_log(
                                $db,
                                'config.reset',
                                audit_actor_from_request(),
                                audit_ip_from_request(),
                                null,
                                [
                                    'key'    => $key,
                                    'before' => settings_audit_redact($existing[$key], $isSecret),
                                    'after'  => settings_audit_redact($defaultValue, $isSecret),
                                ]
                            );
                            $flash['success'] = "Reset {$key} to default.";
                        } else {
                            $flash['error'] = 'Failed to reset: write to config-admin.php failed.';
                        }
                    } else {
                        $flash['success'] = "{$key} already at default.";
                    }
                } else {
                    $flash['error'] = 'Unknown setting key.';
                }
            } else {
                $flash['error'] = 'Unknown action.';
            }
            $db->close();
        } catch (\Throwable $e) {
            error_log('sc admin settings page error: ' . $e->getMessage());
            $flash['error'] = 'Internal error processing the request.';
        }
    }
    $_SESSION['settings_flash'] = $flash;
    $self = strtok((string)($_SERVER['REQUEST_URI'] ?? '/admin/settings.php'), '?');
    header('Location: ' . $self, true, 303);
    exit;
}

$flash = $_SESSION['settings_flash'] ?? ['success' => null, 'error' => null, 'errors' => []];
unset($_SESSION['settings_flash']);

$layered = settings_load_layered();
$writability = settings_writability_error();

$sections = [
    'branding' => 'Branding',
    'forms'    => 'Forms / Captcha',
    'api'      => 'API limits',
    'sessions' => 'Sessions',
    'admin'    => 'Admin & audit',
    'limits'   => 'Limits',
];

$render_input = function (string $key, array $entry, mixed $value, bool $locked = false) use ($h): string {
    $type     = $entry['type'] ?? 'string';
    $isSecret = !empty($entry['secret']);
    $id       = 'set-' . $key;
    $disAttr  = $locked ? ' disabled aria-disabled="true"' : '';

    if ($isSecret) {
        $hasValue = is_string($value) && $value !== '';
        $masked = $hasValue ? '••••••• (set)' : '(empty)';
        return '<div class="settings-secret-row">'
            . '<span class="settings-secret-masked" aria-label="' . ($hasValue ? 'value is set' : 'value is empty') . '">' . $h($masked) . '</span>'
            . '<input id="' . $h($id) . '" name="' . $h($key) . '" type="password" autocomplete="new-password" placeholder="' . ($locked ? 'Locked by config.php' : 'Enter new value to change') . '"' . $disAttr . '>'
            . '</div>';
    }
    if ($type === 'bool') {
        $checked = ((bool)$value) ? ' checked' : '';
        return '<input id="' . $h($id) . '" name="' . $h($key) . '" type="checkbox" value="1"' . $checked . $disAttr . '>';
    }
    if ($type === 'enum') {
        $opts = '';
        foreach (($entry['values'] ?? []) as $v) {
            $sel = ((string)$value === (string)$v) ? ' selected' : '';
            $opts .= '<option value="' . $h((string)$v) . '"' . $sel . '>' . $h((string)$v) . '</option>';
        }
        return '<select id="' . $h($id) . '" name="' . $h($key) . '"' . $disAttr . '>' . $opts . '</select>';
    }
    if ($type === 'multiselect') {
        $current = is_array($value) ? array_map('strval', $value) : [];
        $opts = '';
        foreach (($entry['values'] ?? []) as $v) {
            $sel = in_array((string)$v, $current, true) ? ' selected' : '';
            $opts .= '<option value="' . $h((string)$v) . '"' . $sel . '>' . $h((string)$v) . '</option>';
        }
        return '<select id="' . $h($id) . '" name="' . $h($key) . '[]" multiple size="6" class="settings-multiselect"' . $disAttr . '>' . $opts . '</select>';
    }
    if ($type === 'int') {
        return '<input id="' . $h($id) . '" name="' . $h($key) . '" type="number" step="1" '
            . (isset($entry['min']) ? 'min="' . (int)$entry['min'] . '" ' : '')
            . (isset($entry['max']) ? 'max="' . (int)$entry['max'] . '" ' : '')
            . 'value="' . $h((string)(int)$value) . '"' . $disAttr . '>';
    }
    if ($type === 'float') {
        return '<input id="' . $h($id) . '" name="' . $h($key) . '" type="number" step="any" '
            . (isset($entry['min']) ? 'min="' . (float)$entry['min'] . '" ' : '')
            . (isset($entry['max']) ? 'max="' . (float)$entry['max'] . '" ' : '')
            . 'value="' . $h((string)(float)$value) . '"' . $disAttr . '>';
    }
    if (!empty($entry['multiline'])) {
        return '<textarea id="' . $h($id) . '" name="' . $h($key) . '" rows="2" maxlength="' . (int)($entry['maxlen'] ?? 500) . '"' . $disAttr . '>' . $h((string)$value) . '</textarea>';
    }
    return '<input id="' . $h($id) . '" name="' . $h($key) . '" type="text" maxlength="' . (int)($entry['maxlen'] ?? 200) . '" value="' . $h((string)$value) . '"' . $disAttr . '>';
};

$render_section = function (string $sectionKey, string $title) use ($schema, $layered, $flash, $csrf_expect, $writability, $h, $render_input): string {
    ob_start();
    ?>
    <section id="section-<?= $h($sectionKey) ?>" class="admin-section settings-section" aria-labelledby="settings-<?= $h($sectionKey) ?>-heading">
        <h2 id="settings-<?= $h($sectionKey) ?>-heading"><?= $h($title) ?></h2>
        <form method="post" action="" class="settings-form">
            <input type="hidden" name="_csrf" value="<?= $h($csrf_expect) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="section" value="<?= $h($sectionKey) ?>">
            <?php foreach ($schema as $key => $entry) :
                if (($entry['section'] ?? null) !== $sectionKey) {
                    continue;
                }
                $row    = $layered[$key];
                $value  = $row['effective'];
                $source = $row['source'];
                $shadow = $row['shadowed'];
                $locked = $source === 'config';
                $err    = $flash['errors'][$key] ?? null;
                $canReset = $source === 'admin';
                ?>
                <div class="settings-row<?= $err !== null ? ' has-error' : '' ?><?= $shadow ? ' is-shadowed' : '' ?><?= $locked ? ' is-locked' : '' ?>">
                    <label for="set-<?= $h($key) ?>"><?= $h((string)($entry['label'] ?? $key)) ?></label>
                    <div class="settings-input"><?= $render_input($key, $entry, $value, $locked) ?></div>
                    <div class="settings-meta">
                        <span class="settings-source source-<?= $h($source) ?>" title="<?= $h('Source: ' . $source) ?>"><?= $h($source) ?></span>
                        <?php if ($locked) : ?>
                            <span class="settings-lock-tag" title="Set in config.php — edit the file to change this">locked</span>
                        <?php endif; ?>
                        <?php if ($canReset) : ?>
                            <button type="submit"
                                    name="reset_for"
                                    value="<?= $h($key) ?>"
                                    class="settings-reset-link"
                                    title="Reset <?= $h($key) ?> to its schema default"
                                    formnovalidate
                                    onclick="return confirm('Reset <?= $h($key) ?> to its default value?');"
                            >reset</button>
                        <?php elseif ($locked) : ?>
                            <span class="settings-reset-link is-disabled" title="Locked by config.php; remove the hand-edit there to restore the default" aria-disabled="true">reset</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($locked) : ?>
                        <small class="settings-shadow-banner" role="note">This value is set in <code>config.php</code> and cannot be changed from the UI. Edit the file (or remove the line) to change or unlock it.<?php if ($shadow) : ?> A previous UI save in <code>config-admin.php</code> will take effect once the <code>config.php</code> entry is removed.<?php endif; ?></small>
                    <?php endif; ?>
                    <?php if (!empty($entry['help'])) : ?>
                        <small class="settings-help"><?= $h((string)$entry['help']) ?></small>
                    <?php endif; ?>
                    <?php if ($err !== null) : ?>
                        <small class="settings-error" role="alert"><?= $h($err) ?></small>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="settings-actions">
                <button class="splitter-btn" type="submit"<?= $writability !== null ? ' disabled' : '' ?>>Save section</button>
            </div>
        </form>
    </section>
    <?php
    return (string)ob_get_clean();
};

ob_start();
?>
<section class="admin-section" aria-labelledby="settings-heading">
    <h1 id="settings-heading">Settings <?= help_bubble('admin-settings', 'Edit operator-tunable config knobs. Saves write to config-admin.php; hand-edits in config.php always win.') ?></h1>
    <div class="alert alert-info settings-precedence-card" role="note">
        <strong>How settings are layered</strong>
        <p>Each row resolves from the highest-priority source that has a value:</p>
        <ol class="settings-precedence-list">
            <li><code>config.php</code> — operator hand-edits. <span class="settings-source source-config">config</span> rows are <strong>locked</strong> here; edit the file to change them.</li>
            <li><code>config-admin.php</code> — what this page writes. Shown as <span class="settings-source source-admin">admin</span>.</li>
            <li>Built-in defaults. Shown as <span class="settings-source source-default">default</span>.</li>
        </ol>
    </div>
    <?php if ($writability !== null) : ?>
        <div class="alert alert-error" role="alert">
            <strong>Cannot save settings.</strong>
            <p><?= $h($writability) ?> Save buttons are disabled until this is fixed. See <code>docs/admin.md</code> for ownership guidance.</p>
        </div>
    <?php endif; ?>
    <?php if (!empty($flash['success'])) : ?>
        <div class="alert alert-success" role="status" aria-live="polite"><?= $h((string)$flash['success']) ?></div>
    <?php endif; ?>
    <?php if (!empty($flash['error'])) : ?>
        <div class="alert alert-error" role="alert"><?= $h((string)$flash['error']) ?></div>
    <?php endif; ?>
</section>

<?php foreach ($sections as $key => $title) : ?>
    <?= $render_section($key, $title) ?>
<?php endforeach; ?>

<details class="admin-section settings-section settings-advanced">
    <summary>Advanced — Content Security Policy extras</summary>
    <?= $render_section('csp', 'CSP extras') ?>
</details>
<?php
$admin_card_body       = ob_get_clean();
$page_title            = 'Settings';
$admin_breadcrumb      = 'Settings';
$admin_user_signed_in  = (string)($admin_ctx['user'] ?? '');
$admin_csrf_token      = (string)($admin_ctx['csrf'] ?? '');
require __DIR__ . '/../templates/_admin_layout.php';
