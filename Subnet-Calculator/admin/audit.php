<?php

declare(strict_types=1);

// /admin/audit.php — admin audit log viewer (v3.0.0, #306).
//
// Read-only paginated table of `admin_audit` rows. Uses the same Basic
// Auth + cache headers as /admin/keys.php and shares the same SQLite file
// (resolved through admin_apikey_db_path()). No POST actions — this page
// never mutates state, so no CSRF token is needed.

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions-util.php';
require __DIR__ . '/../includes/functions-admin-auth.php';
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
$fmtIso = static function (?int $ts): string {
    if ($ts === null) {
        return '';
    }
    return gmdate('Y-m-d\TH:i:s\Z', $ts);
};
$fmtHuman = static function (?int $ts): string {
    if ($ts === null) {
        return '—';
    }
    return gmdate('Y-m-d H:i:s', $ts) . ' UTC';
};

$filterLabels = ['' => 'all', 'login.' => 'login', 'key.' => 'key', 'wizard.' => 'wizard', 'totp.' => 'totp'];

ob_start();
?>
<section class="admin-section" aria-labelledby="audit-heading">
    <h1 id="audit-heading">Admin Audit Log <?= help_bubble('admin-audit', 'Read-only paginated audit trail. Rows older than the configured retention window are purged automatically on every audit write.') ?></h1>
    <?php if ($error !== null) : ?>
        <div class="alert alert-error" role="alert"><?= $h($error) ?></div>
    <?php endif; ?>
    <form method="get" class="admin-filters" role="tablist" aria-label="Filter audit entries by action">
        <?php foreach ($filterLabels as $val => $label) :
            $selected = $filter === $val;
            ?>
            <button type="submit"
                    name="filter"
                    value="<?= $h($val) ?>"
                    role="tab"
                    class="tab-btn audit-filter-tab<?= $selected ? ' active' : '' ?>"
                    aria-selected="<?= $selected ? 'true' : 'false' ?>"
                    aria-controls="audit-rows"
                    tabindex="<?= $selected ? '0' : '-1' ?>"><?= $h($label) ?></button>
        <?php endforeach; ?>
        <span class="admin-filters-count">(<?= $total ?> total)</span>
    </form>
</section>

<section class="admin-section" aria-labelledby="audit-table-heading">
    <h2 id="audit-table-heading" class="sr-only">Audit log entries</h2>
    <?php if ($rows === []) : ?>
        <p>No audit entries.</p>
    <?php else : ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <caption class="sr-only">Recent admin actions, newest first. Use the filter tabs to narrow by category.</caption>
                <thead>
                    <tr><th scope="col">Time (UTC)</th><th scope="col">Action</th><th scope="col">Actor</th><th scope="col">IP</th><th scope="col">Target</th><th scope="col">Meta</th></tr>
                </thead>
                <tbody id="audit-rows">
                <?php foreach ($rows as $r) :
                    $action = $r['action'];
                    $badge  = audit_action_badge_class($action);
                    $iso    = $fmtIso($r['ts']);
                    ?>
                    <tr>
                        <td class="mono"><?php if ($iso !== '') : ?><time datetime="<?= $h($iso) ?>"><?= $h($fmtHuman($r['ts'])) ?></time><?php else : ?>—<?php endif; ?></td>
                        <td><span class="badge <?= $h($badge) ?>"><?= $h($action) ?></span></td>
                        <td class="mono"><?= $h($r['actor'] ?? '—') ?></td>
                        <td class="mono"><?= $h($r['ip']    ?? '—') ?></td>
                        <td class="mono"><?= $r['target_id'] !== null ? (int)$r['target_id'] : '—' ?></td>
                        <td class="meta"><?= $h($r['meta']  ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($rows !== []) : ?>
    <section class="admin-section" aria-labelledby="audit-pager-heading">
        <h2 id="audit-pager-heading" class="sr-only">Pagination</h2>
        <?php
        $qs = static function (int $p) use ($filter): string {
            $parts = ['page=' . $p];
            if ($filter !== '') {
                $parts[] = 'filter=' . urlencode($filter);
            }
            return '?' . implode('&', $parts);
        };
        $prevDisabled = $page <= 1;
        $nextDisabled = $page >= $totalPages;
        ?>
        <div class="admin-pager">
            <span>Page <?= (int)$page ?> of <?= (int)$totalPages ?></span>
            <?php if ($prevDisabled) : ?>
                <span class="admin-pager-item is-disabled" aria-disabled="true" aria-label="Previous page (unavailable)">← prev</span>
            <?php else : ?>
                <a href="<?= $h($qs($page - 1)) ?>" class="admin-pager-item" aria-label="Previous page">← prev</a>
            <?php endif; ?>
            <?php if ($nextDisabled) : ?>
                <span class="admin-pager-item is-disabled" aria-disabled="true" aria-label="Next page (unavailable)">next →</span>
            <?php else : ?>
                <a href="<?= $h($qs($page + 1)) ?>" class="admin-pager-item" aria-label="Next page">next →</a>
            <?php endif; ?>
        </div>
        <p class="admin-meta-note">
            Retention: rows older than <code><?= (int)($admin_audit_retention_days ?? 90) ?></code> days
            are purged automatically on every audit write.
        </p>
    </section>
<?php endif; ?>
<?php
$admin_card_body       = ob_get_clean();
$page_title            = 'Audit Log';
$admin_breadcrumb      = 'Audit Log';
$admin_user_signed_in  = (string)($admin_ctx['user'] ?? '');
$admin_csrf_token      = (string)($admin_ctx['csrf'] ?? '');
require __DIR__ . '/../templates/_admin_layout.php';
