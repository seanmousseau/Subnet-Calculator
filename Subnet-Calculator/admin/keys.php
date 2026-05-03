<?php

declare(strict_types=1);

// /admin/keys.php — API key management UI (v2.12.0, #297).
// Server-rendered HTML; no JS; HTTP Basic Auth via admin_authenticate().
// POST actions are CSRF-protected by a per-request token derived from the
// admin password hash + client IP; since admin auth re-prompts every
// request, a bare GET cannot mint a usable token.

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions-admin-auth.php';
require __DIR__ . '/../includes/functions-apikeys.php';

admin_authenticate();

$db_path = admin_apikey_db_path();
$db      = apikey_db_open($db_path);

$created_token = null;
$error         = null;
$success       = null;

// CSRF token: hashed admin password + IP. Stable for the auth context, not
// guessable without the admin credentials.
$csrf_seed   = ($admin_pass_hash ?? '') . '|' . ($_SERVER['REMOTE_ADDR'] ?? '');
$csrf_expect = hash('sha256', $csrf_seed);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrf = (string)($_POST['_csrf'] ?? '');
    if (!hash_equals($csrf_expect, $csrf)) {
        $error = 'Invalid CSRF token. Reload the page and retry.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'create') {
                $name = (string)($_POST['name'] ?? '');
                $created = apikey_create($db, $name);
                $created_token = $created['token'];
                $success = 'Key "' . $created['name'] . '" created. Copy the token now — it is not shown again.';
            } elseif ($action === 'revoke') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0 && apikey_revoke($db, $id)) {
                    $success = 'Key revoked.';
                } else {
                    $error = 'Key not found or already revoked.';
                }
            } else {
                $error = 'Unknown action.';
            }
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('sc admin keys error: ' . $e->getMessage());
            $error = 'Internal error processing the request.';
        }
    }
}

$keys = apikey_list($db);
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
    <button class="btn" type="submit">Mint key</button>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0;font-size:1.1rem">Existing keys</h2>
  <?php if ($keys === []) : ?>
    <p>No keys yet.</p>
  <?php else : ?>
    <table>
      <thead>
        <tr>
          <th>Name</th><th>Prefix</th><th>Created</th><th>Last used</th><th>Status</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($keys as $k) :
            $is_revoked = $k['revoked_at'] !== null; ?>
        <tr<?= $is_revoked ? ' class="revoked"' : '' ?>>
          <td><?= $h($k['name']) ?></td>
          <td class="prefix">sk_live_<?= $h($k['prefix']) ?>…</td>
          <td><?= $h($fmt($k['created_at'])) ?></td>
          <td><?= $h($fmt($k['last_used_at'])) ?></td>
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
</footer>
</main>
</body>
</html>
