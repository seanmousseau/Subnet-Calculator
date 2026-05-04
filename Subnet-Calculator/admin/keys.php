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
require __DIR__ . '/../includes/functions-admin-auth.php';
require __DIR__ . '/../includes/functions-apikeys.php';
require __DIR__ . '/../includes/functions-audit.php';

admin_authenticate();

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

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>API Keys — Subnet Calculator Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<style>
  :root { color-scheme: dark; }
  body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background:#0a1a12; color:#e5e7eb; margin:0; padding:2rem; }
  h1 { font-size:1.5rem; margin:0 0 1rem; }
  main { max-width: 960px; margin:0 auto; }
  .card { background:#0f2419; border:1px solid #1e3a2a; border-radius:8px; padding:1.5rem; margin-bottom:1.5rem; }
  .alert { padding:0.75rem 1rem; border-radius:6px; margin-bottom:1rem; }
  .alert-ok    { background:#063b25; border:1px solid #06d6a0; color:#a7f3d0; }
  .alert-err   { background:#3b0606; border:1px solid #ef4444; color:#fecaca; }
  .token { font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace; background:#000; color:#06d6a0; padding:0.75rem; border-radius:4px; word-break:break-all; user-select:all; }
  table { width:100%; border-collapse:collapse; margin-top:1rem; }
  th, td { padding:0.5rem; text-align:left; border-bottom:1px solid #1e3a2a; }
  th { font-size:0.85rem; text-transform:uppercase; color:#6ee7b7; letter-spacing:0.05em; }
  td.prefix { font-family: ui-monospace, monospace; }
  td.revoked { color:#9ca3af; }
  .btn { background:#06d6a0; color:#0a1a12; border:none; padding:0.5rem 1rem; border-radius:4px; font-weight:600; cursor:pointer; }
  .btn:hover { background:#34e2b5; }
  .btn-danger { background:#ef4444; color:#fff; }
  .btn-danger:hover { background:#dc2626; }
  input[type=text] { background:#000; border:1px solid #1e3a2a; color:#e5e7eb; padding:0.5rem; border-radius:4px; font-size:1rem; }
  form.inline { display:inline; margin:0; }
  .badge { display:inline-block; padding:0.1rem 0.5rem; border-radius:3px; font-size:0.75rem; font-weight:600; }
  .badge-active { background:#063b25; color:#a7f3d0; }
  .badge-revoked { background:#3b0606; color:#fecaca; }
  footer { margin-top:2rem; color:#6b7280; font-size:0.85rem; }
  a { color:#06d6a0; }
</style>
</head>
<body>
<main>
<h1>API Keys</h1>

<?php if ($success !== null) : ?>
  <div class="alert alert-ok"><?= $h($success) ?></div>
<?php endif; ?>
<?php if ($error !== null) : ?>
  <div class="alert alert-err"><?= $h($error) ?></div>
<?php endif; ?>

<?php if ($created_token !== null) : ?>
  <div class="card">
    <strong>New token (shown once)</strong>
    <p>Copy this token now. It cannot be retrieved again — only revoked.</p>
    <div class="token"><?= $h($created_token) ?></div>
  </div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0;font-size:1.1rem">Create a key</h2>
  <form method="post" action="">
    <input type="hidden" name="_csrf" value="<?= $h($csrf_expect) ?>">
    <input type="hidden" name="action" value="create">
    <label>Name <input type="text" name="name" required maxlength="128" placeholder="production"></label>
    <label>RPM <input type="number" name="rate_limit_rpm" min="0" step="1" placeholder="(default)" style="width:7rem" title="Per-key rate limit. Leave blank to inherit global. 0 = unlimited."></label>
    <button class="btn" type="submit">Mint key</button>
  </form>
  <p style="font-size:0.85rem;color:#9ca3af;margin-top:0.5rem">RPM blank = inherit global default (<code><?= (int)($api_rate_limit_rpm ?? 60) ?></code>); <code>0</code> = unlimited.</p>
</div>

<div class="card">
  <h2 style="margin-top:0;font-size:1.1rem">Existing keys</h2>
  <?php if ($keys === []) : ?>
    <p>No keys yet.</p>
  <?php else : ?>
    <table>
      <thead>
        <tr>
          <th>Name</th><th>Prefix</th><th>Created</th><th>Last used</th><th>RPM</th><th>Status</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($keys as $k) :
            $is_revoked = $k['revoked_at'] !== null;
            $rpm        = $k['rate_limit_rpm']; ?>
        <tr<?= $is_revoked ? ' class="revoked"' : '' ?>>
          <td><?= $h($k['name']) ?></td>
          <td class="prefix">sk_live_<?= $h($k['prefix']) ?>…</td>
          <td><?= $h($fmt($k['created_at'])) ?></td>
          <td><?= $h($fmt($k['last_used_at'])) ?></td>
          <td>
            <?php if ($is_revoked) : ?>
              <span class="badge badge-revoked">—</span>
            <?php else : ?>
              <form method="post" action="" class="inline">
                <input type="hidden" name="_csrf" value="<?= $h($csrf_expect) ?>">
                <input type="hidden" name="action" value="set_rpm">
                <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                <input type="number" name="rate_limit_rpm" min="0" step="1"
                       value="<?= $rpm === null ? '' : (int)$rpm ?>"
                       placeholder="default"
                       style="width:5rem"
                       title="0 = unlimited; blank = inherit global default">
                <button type="submit" class="btn" style="padding:0.25rem 0.6rem;font-size:0.85rem">Save</button>
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
              <form method="post" action="" class="inline" onsubmit="return confirm('Revoke this key?');">
                <input type="hidden" name="_csrf" value="<?= $h($csrf_expect) ?>">
                <input type="hidden" name="action" value="revoke">
                <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                <button type="submit" class="btn btn-danger">Revoke</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<footer>
  Keys authenticate against <code>POST/GET /api/v1/*</code> via <code>Authorization: Bearer &lt;token&gt;</code>.
  Plain-string entries in <code>$api_tokens</code> still work alongside SQLite-stored keys.
  <br><a href="audit.php">View audit log →</a>
</footer>
</main>
</body>
</html>
