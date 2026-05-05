<?php

declare(strict_types=1);

// /admin/keys.php — API key management UI (v2.12.0, #297).
// Server-rendered HTML; no JS; HTTP Basic Auth via admin_authenticate().
// POST actions are CSRF-protected by a per-request token derived from the
// admin password hash + client IP; since admin auth re-prompts every
// request, a bare GET cannot mint a usable token.
//
// Mutating actions follow the POST-Redirect-GET pattern so a browser
// refresh / back-forward cache cannot replay a create or revoke. The
// freshly-minted token (shown once) survives the redirect via a one-shot
// PHP session flash and is wiped on read.

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions-util.php';
require __DIR__ . '/../includes/functions-admin-auth.php';
require __DIR__ . '/../includes/functions-apikeys.php';
require __DIR__ . '/../includes/functions-audit.php';
require __DIR__ . '/../includes/functions-admin-wizard.php';

if (admin_wizard_needed()) {
    require __DIR__ . '/_wizard.php';
    exit;
}

admin_session_require();

// Never cache admin output — the one-time minted token must not survive
// in browser/disk/back-forward caches.
header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// PRG flash carrier — short-lived session, secure cookie attributes.
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

try {
    $db_path = admin_apikey_db_path();
    $db      = apikey_db_open($db_path);
} catch (\Throwable $e) {
    error_log('sc admin keys db_open error: ' . $e->getMessage());
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><title>API key store unavailable</title>'
       . '<p>The API key store could not be opened. Check filesystem permissions on the '
       . 'configured <code>$apikey_db_path</code> / sessions DB. The server log has details.</p>';
    exit;
}

// CSRF token: hashed admin password + IP. Stable for the auth context, not
// guessable without the admin credentials.
$csrf_seed   = ($admin_pass_hash ?? '') . '|' . ($_SERVER['REMOTE_ADDR'] ?? '');
$csrf_expect = hash('sha256', $csrf_seed);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $flash_token = null;
    $flash_error = null;
    $flash_msg   = null;

    $csrf = (string)($_POST['_csrf'] ?? '');
    if (!hash_equals($csrf_expect, $csrf)) {
        $flash_error = 'Invalid CSRF token. Reload the page and retry.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'create') {
                $name    = (string)($_POST['name'] ?? '');
                $rpmRaw  = trim((string)($_POST['rate_limit_rpm'] ?? ''));
                $rpm     = $rpmRaw === '' ? null : (int)$rpmRaw;
                if ($rpm !== null && (!ctype_digit($rpmRaw) || $rpm < 0)) {
                    throw new InvalidArgumentException('Rate limit (RPM) must be empty, 0 (unlimited), or a positive integer.');
                }
                $created = apikey_create($db, $name, $rpm);
                audit_log(
                    $db,
                    'key.mint',
                    audit_actor_from_request(),
                    audit_ip_from_request(),
                    (int)$created['id'],
                    [
                        'name'           => $created['name'],
                        'prefix'         => $created['prefix'],
                        'rate_limit_rpm' => $rpm,
                    ]
                );
                $flash_token = $created['token'];
                $flash_msg   = 'Key "' . $created['name']
                             . '" created. Copy the token now — it is not shown again.';
            } elseif ($action === 'set_rpm') {
                $id     = (int)($_POST['id'] ?? 0);
                $rpmRaw = trim((string)($_POST['rate_limit_rpm'] ?? ''));
                $rpm    = $rpmRaw === '' ? null : (int)$rpmRaw;
                if ($rpm !== null && (!ctype_digit($rpmRaw) || $rpm < 0)) {
                    throw new InvalidArgumentException('Rate limit (RPM) must be empty, 0 (unlimited), or a positive integer.');
                }
                if ($id > 0 && apikey_set_rate_limit($db, $id, $rpm)) {
                    audit_log(
                        $db,
                        'key.rate_limit',
                        audit_actor_from_request(),
                        audit_ip_from_request(),
                        $id,
                        ['rate_limit_rpm' => $rpm]
                    );
                    $flash_msg = $rpm === null
                        ? 'Rate-limit override cleared (key inherits global default).'
                        : 'Rate limit set to ' . ($rpm === 0 ? 'unlimited' : $rpm . ' RPM') . '.';
                } else {
                    $flash_error = 'Key not found, already revoked, or RPM unchanged.';
                }
            } elseif ($action === 'revoke') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0 && apikey_revoke($db, $id)) {
                    audit_log(
                        $db,
                        'key.revoke',
                        audit_actor_from_request(),
                        audit_ip_from_request(),
                        $id,
                        null
                    );
                    $flash_msg = 'Key revoked.';
                } else {
                    $flash_error = 'Key not found or already revoked.';
                }
            } else {
                $flash_error = 'Unknown action.';
            }
        } catch (\InvalidArgumentException $e) {
            $flash_error = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('sc admin keys error: ' . $e->getMessage());
            $flash_error = 'Internal error processing the request.';
        }
    }

    $db->close();

    $_SESSION['flash'] = [
        'token'   => $flash_token,
        'success' => $flash_msg,
        'error'   => $flash_error,
    ];

    // 303 See Other forces the browser to use GET on follow.
    $self = (string)($_SERVER['REQUEST_URI'] ?? '/admin/keys.php');
    $self = strtok($self, '?'); // strip any query string
    header('Location: ' . $self, true, 303);
    exit;
}

// GET: read and clear flash data populated by the previous POST.
$flash         = $_SESSION['flash'] ?? null;
$created_token = is_array($flash) && isset($flash['token']) && is_string($flash['token'])
    ? $flash['token'] : null;
$success       = is_array($flash) && isset($flash['success']) && is_string($flash['success'])
    ? $flash['success'] : null;
$error         = is_array($flash) && isset($flash['error']) && is_string($flash['error'])
    ? $flash['error'] : null;
unset($_SESSION['flash']);

// Distinguish a true empty state ([]) from a DB read failure (exception):
// apikey_list() throws on prepare/execute failure so the operator sees a
// loud error banner instead of "No keys yet" hiding a real DB problem.
try {
    $keys = apikey_list($db);
} catch (\Throwable $e) {
    error_log('sc admin keys list error: ' . $e->getMessage());
    $keys  = [];
    $error = $error
        ?? 'Failed to read the API key store. Operator should check the server log.';
}
$db->close();

/** Format a unix timestamp or render an em-dash. */
$fmt = static function (?int $ts): string {
    if ($ts === null) {
        return '—';
    }
    return gmdate('Y-m-d H:i', $ts) . ' UTC';
};

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

ob_start();
$keys_help = 'Keys authenticate against POST/GET /api/v1/* via Authorization: Bearer <token>. '
    . 'Plain-string entries in $api_tokens still work alongside SQLite-stored keys. '
    . 'Tokens are shown once at mint time; lost tokens must be re-minted, not recovered.';
?>
<section class="admin-section" aria-labelledby="keys-heading">
    <h1 id="keys-heading">API Keys <?= help_bubble('admin-keys', $keys_help) ?></h1>
    <?php if ($success !== null) : ?>
        <div class="alert alert-success" role="status"><?= $h($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== null) : ?>
        <div class="alert alert-error" role="alert"><?= $h($error) ?></div>
    <?php endif; ?>
    <?php if ($created_token !== null) : ?>
        <div class="alert alert-accent" role="alert">
            <strong>New token (shown once)</strong>
            <p>Copy this token now. It cannot be retrieved again — only revoked.</p>
            <div class="api-key-display"><?= $h($created_token) ?></div>
        </div>
    <?php endif; ?>
</section>

<section class="admin-section" aria-labelledby="keys-create-heading">
    <h2 id="keys-create-heading">Create a key</h2>
    <form method="post" action="" class="admin-form">
        <input type="hidden" name="_csrf" value="<?= $h($csrf_expect) ?>">
        <input type="hidden" name="action" value="create">
        <div class="form-group">
            <label for="key-name">Name</label>
            <input id="key-name" type="text" name="name" required maxlength="128" placeholder="production">
        </div>
        <div class="form-group form-group-narrow">
            <label for="key-rpm">RPM</label>
            <input id="key-rpm" type="number" name="rate_limit_rpm" min="0" step="1"
                   placeholder="(default)"
                   title="Per-key rate limit. Leave blank to inherit global. 0 = unlimited.">
        </div>
        <div class="form-group form-group-action">
            <button class="splitter-btn" type="submit">Mint key</button>
        </div>
    </form>
    <p class="admin-meta-note">RPM blank = inherit global default (<code><?= (int)($api_rate_limit_rpm ?? 60) ?></code>); <code>0</code> = unlimited.</p>
</section>

<section class="admin-section" aria-labelledby="keys-existing-heading">
    <h2 id="keys-existing-heading">Existing keys</h2>
    <?php if ($keys === []) : ?>
        <p>No keys yet.</p>
    <?php else : ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Name</th><th>Prefix</th><th>Created</th><th>Last used</th><th>RPM</th><th>Status</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($keys as $k) :
                    $is_revoked = $k['revoked_at'] !== null;
                    $rpm        = $k['rate_limit_rpm']; ?>
                    <tr<?= $is_revoked ? ' class="is-revoked"' : '' ?>>
                        <td><?= $h($k['name']) ?></td>
                        <td class="mono">sk_live_<?= $h($k['prefix']) ?>…</td>
                        <td><?= $h($fmt($k['created_at'])) ?></td>
                        <td><?= $h($fmt($k['last_used_at'])) ?></td>
                        <td>
                            <?php if ($is_revoked) : ?>
                                <span class="badge badge-revoked">—</span>
                            <?php else : ?>
                                <form method="post" action="" class="admin-inline-form">
                                    <input type="hidden" name="_csrf" value="<?= $h($csrf_expect) ?>">
                                    <input type="hidden" name="action" value="set_rpm">
                                    <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                                    <input type="number" name="rate_limit_rpm" min="0" step="1"
                                           value="<?= $rpm === null ? '' : (int)$rpm ?>"
                                           placeholder="default"
                                           class="admin-input-narrow"
                                           aria-label="Rate limit (RPM)"
                                           title="0 = unlimited; blank = inherit global default">
                                    <button type="submit" class="splitter-btn splitter-btn-sm">Save</button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($is_revoked) : ?>
                                <span class="badge badge-revoked">revoked <?= $h($fmt($k['revoked_at'])) ?></span>
                            <?php else : ?>
                                <span class="badge badge-active">active</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$is_revoked) : ?>
                                <form method="post" action="" class="admin-inline-form" onsubmit="return confirm('Revoke this key?');">
                                    <input type="hidden" name="_csrf" value="<?= $h($csrf_expect) ?>">
                                    <input type="hidden" name="action" value="revoke">
                                    <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                                    <button type="submit" class="btn-danger">Revoke</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php
$admin_card_body  = ob_get_clean();
$page_title       = 'API Keys';
$admin_breadcrumb = 'API Keys';
require __DIR__ . '/../templates/_admin_layout.php';
