<?php

declare(strict_types=1);

/**
 * Persistent left sidebar for /admin/* pages — introduced in v3.2.0
 * (#344). Replaces the interim `.admin-footer-links` cluster that
 * shipped in #345 and absorbs the header user-chip + Logout form that
 * shipped in #343.
 *
 * Caller contract (locals expected in scope when this file is required):
 *
 *   string $admin_user          Username of the active admin session.
 *                               Already validated as non-empty by the
 *                               layout (the layout suppresses the entire
 *                               sidebar when this is empty, e.g. on
 *                               login.php). Escaped here.
 *   string $admin_csrf          Active session's CSRF token. Wired into
 *                               the hidden input on the Logout form.
 *
 * Renders a `<nav aria-label="Admin">` landmark + a hamburger toggle
 * button. The toggle is only visible at viewports <= 768px (CSS-driven);
 * `app.js` flips `.open` on the nav and `aria-expanded` on the button.
 */

$_h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// Resolve the active admin page so the matching row can be tagged
// aria-current="page" (and styled with the teal left-border treatment).
$_sidebar_script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));

// Canonical admin nav list. Settings is included as a live link — the
// page lands in Task 8 (#349); until then the link 404s by design (Sean
// approved including the live link rather than rendering it disabled).
$_sidebar_links = [
    'keys.php'     => 'API Keys',
    'audit.php'    => 'Audit Log',
    'totp.php'     => 'TOTP / 2FA',
    'settings.php' => 'Settings',
];
?>
<button class="admin-sidebar-toggle" type="button"
        aria-controls="admin-sidebar" aria-expanded="false"
        aria-label="Toggle admin navigation">
    <svg class="admin-sidebar-toggle-icon" width="20" height="20"
         viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
         aria-hidden="true">
        <line x1="3" y1="6" x2="21" y2="6"/>
        <line x1="3" y1="12" x2="21" y2="12"/>
        <line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
</button>
<nav id="admin-sidebar" class="admin-sidebar" aria-label="Admin">
    <div class="admin-sidebar-user">
        <span class="admin-user-chip admin-sidebar-user-chip"
              aria-label="Signed in as <?= $_h($admin_user) ?>">
            <svg class="admin-user-chip-icon" width="12" height="12" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                <circle cx="12" cy="7" r="4"/>
            </svg>
            <span class="admin-user-chip-name"><?= $_h($admin_user) ?></span>
        </span>
    </div>
    <ul class="admin-sidebar-list">
        <?php foreach ($_sidebar_links as $slug => $label) : ?>
            <li>
                <?php if ($_sidebar_script === $slug) : ?>
                    <a class="admin-sidebar-link is-active"
                       href="<?= $_h($slug) ?>"
                       aria-current="page"><?= $_h($label) ?></a>
                <?php else : ?>
                    <a class="admin-sidebar-link"
                       href="<?= $_h($slug) ?>"><?= $_h($label) ?></a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <div class="admin-sidebar-footer">
        <form class="admin-logout-form" method="post" action="logout.php"
              aria-label="Sign out of admin">
            <input type="hidden" name="csrf" value="<?= $_h($admin_csrf) ?>">
            <button class="admin-logout-btn" type="submit">Sign out</button>
        </form>
    </div>
</nav>
