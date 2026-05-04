<?php

declare(strict_types=1);

// /admin/audit.php — admin audit log viewer (v3.0.0, #306).
//
// Read-only paginated table of `admin_audit` rows. Uses the same Basic
// Auth + cache headers as /admin/keys.php and shares the same SQLite file
// (resolved through admin_apikey_db_path()). No POST actions — this page
// never mutates state, so no CSRF token is needed.

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions-admin-auth.php';
require __DIR__ . '/../includes/functions-audit.php';
require __DIR__ . '/../includes/functions-admin-wizard.php';

if (admin_wizard_needed()) {
    header('Location: keys.php', true, 303);
    exit;
}

admin_authenticate();

header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

const AUDIT_PAGE_SIZE = 50;

$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * AUDIT_PAGE_SIZE;

$filterRaw = (string)($_GET['filter'] ?? '');
// Allow only well-known prefixes — defensive against arbitrary LIKE input
$allowedPrefixes = ['', 'login.', 'key.', 'wizard.', 'totp.'];
$filter = in_array($filterRaw, $allowedPrefixes, true) ? $filterRaw : '';

$rows  = [];
$total = 0;
$error = null;

try {
    $db = new \SQLite3(admin_apikey_db_path());
    $db->enableExceptions(true);
    $db->busyTimeout(3000);
    audit_db_init($db);

    $rows  = audit_list($db, AUDIT_PAGE_SIZE, $offset, $filter !== '' ? $filter : null);
    $total = audit_count($db, $filter !== '' ? $filter : null);
    $db->close();
} catch (\Throwable $e) {
    error_log('sc admin audit page error: ' . $e->getMessage());
    $error = 'Failed to read the audit log. Operator should check the server log.';
}

$totalPages = max(1, (int)ceil($total / AUDIT_PAGE_SIZE));

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$fmt = static function (?int $ts): string {
    if ($ts === null) {
        return '—';
    }
    return gmdate('Y-m-d H:i:s', $ts) . ' UTC';
};

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Audit Log — Subnet Calculator Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<style>
  :root { color-scheme: dark; }
  body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background:#0a1a12; color:#e5e7eb; margin:0; padding:2rem; }
  h1 { font-size:1.5rem; margin:0 0 1rem; }
  main { max-width: 1200px; margin:0 auto; }
  .card { background:#0f2419; border:1px solid #1e3a2a; border-radius:8px; padding:1.5rem; margin-bottom:1.5rem; }
  .alert-err { background:#3b0606; border:1px solid #ef4444; color:#fecaca; padding:0.75rem 1rem; border-radius:6px; margin-bottom:1rem; }
  table { width:100%; border-collapse:collapse; margin-top:1rem; }
  th, td { padding:0.5rem; text-align:left; border-bottom:1px solid #1e3a2a; vertical-align:top; }
  th { font-size:0.85rem; text-transform:uppercase; color:#6ee7b7; letter-spacing:0.05em; }
  td.mono { font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace; font-size:0.9rem; }
  td.meta { font-family: ui-monospace, monospace; font-size:0.8rem; color:#9ca3af; max-width:24rem; word-break:break-word; }
  .badge { display:inline-block; padding:0.1rem 0.5rem; border-radius:3px; font-size:0.75rem; font-weight:600; }
  .badge-ok { background:#063b25; color:#a7f3d0; }
  .badge-fail { background:#3b0606; color:#fecaca; }
  .badge-info { background:#1e3a2a; color:#a7f3d0; }
  .filters a { display:inline-block; margin-right:0.5rem; padding:0.25rem 0.6rem; border:1px solid #1e3a2a; border-radius:4px; color:#a7f3d0; text-decoration:none; }
  .filters a.active { background:#063b25; border-color:#06d6a0; }
  .pager { margin-top:1rem; color:#9ca3af; }
  .pager a { color:#06d6a0; text-decoration:none; padding:0 0.4rem; }
  .pager a.disabled { color:#374151; pointer-events:none; }
  footer { margin-top:2rem; color:#6b7280; font-size:0.85rem; }
  a { color:#06d6a0; }
</style>
</head>
<body>
<main>
<h1>Admin Audit Log</h1>

<?php if ($error !== null) : ?>
  <div class="alert-err"><?= $h($error) ?></div>
<?php endif; ?>

<div class="card">
  <div class="filters">
    Filter:
    <?php foreach (['' => 'all', 'login.' => 'login', 'key.' => 'key', 'wizard.' => 'wizard', 'totp.' => 'totp'] as $val => $label) : ?>
      <a href="?<?= $val === '' ? '' : 'filter=' . urlencode($val) ?>"
         class="<?= $filter === $val ? 'active' : '' ?>"><?= $h($label) ?></a>
    <?php endforeach; ?>
    &nbsp;<span style="color:#6b7280;font-size:0.85rem">(<?= $total ?> total)</span>
  </div>

  <?php if ($rows === []) : ?>
    <p>No audit entries.</p>
  <?php else : ?>
    <table>
      <thead>
        <tr><th>Time (UTC)</th><th>Action</th><th>Actor</th><th>IP</th><th>Target</th><th>Meta</th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r) :
        $action = $r['action'];
        $badge  = 'badge-info';
        if (str_ends_with($action, '.fail')) {
            $badge = 'badge-fail';
        } elseif (str_ends_with($action, '.ok')) {
            $badge = 'badge-ok';
        }
      ?>
        <tr>
          <td class="mono"><?= $h($fmt($r['ts'])) ?></td>
          <td><span class="badge <?= $badge ?>"><?= $h($action) ?></span></td>
          <td class="mono"><?= $h($r['actor'] ?? '—') ?></td>
          <td class="mono"><?= $h($r['ip']    ?? '—') ?></td>
          <td class="mono"><?= $r['target_id'] !== null ? (int)$r['target_id'] : '—' ?></td>
          <td class="meta"><?= $h($r['meta']  ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div class="pager">
      <?php
      $qs = static function (int $p) use ($filter): string {
          $parts = ['page=' . $p];
          if ($filter !== '') {
              $parts[] = 'filter=' . urlencode($filter);
          }
          return '?' . implode('&', $parts);
      };
      ?>
      Page <?= $page ?> of <?= $totalPages ?>
      <a href="<?= $h($qs(max(1, $page - 1))) ?>" class="<?= $page <= 1 ? 'disabled' : '' ?>">← prev</a>
      <a href="<?= $h($qs(min($totalPages, $page + 1))) ?>" class="<?= $page >= $totalPages ? 'disabled' : '' ?>">next →</a>
    </div>
  <?php endif; ?>
</div>

<footer>
  Retention: rows older than <code><?= (int)($admin_audit_retention_days ?? 90) ?></code> days
  are purged automatically on every audit write.
  <a href="keys.php">← back to API keys</a>
</footer>
</main>
</body>
</html>
