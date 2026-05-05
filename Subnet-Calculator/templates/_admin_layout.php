<?php

declare(strict_types=1);

/**
 * Shared /admin/ page shell — introduced in v3.2.0 (#345) to align the
 * admin chrome with the calculator. Refactored in v3.2.0 (#344) to host
 * the persistent left sidebar that replaces the interim
 * `.admin-footer-links` cluster + the header user chip / Logout form.
 *
 * Renders:
 *
 *   <!doctype html>
 *   <head> (meta + theme-init + assets/app.css)
 *   <body class="admin-shell[ no-sidebar]">
 *     skip-link → #main-content
 *     [ admin/_sidebar.php — only when signed in ]
 *     <main id="main-content" class="card admin-card">
 *       title-row (logo + page title + version pill + breadcrumb + theme toggle)
 *       admin-card body (caller-supplied $admin_card_body)
 *     </main>
 *     <script src="assets/app.js">
 *
 * Caller contract (locals expected in scope when this file is required):
 *
 *   string  $page_title         The H1 / <title> for the admin page.
 *   string  $admin_breadcrumb   Plain text rendered as the breadcrumb leaf
 *                               (e.g. "API Keys", "Audit Log"). Escaped here.
 *   string  $admin_card_body    Pre-rendered HTML body — typically captured
 *                               via ob_start() / ob_get_clean() in the
 *                               admin page itself. Already escaped.
 *   ?string $admin_user_signed_in  Username of the active admin session.
 *                                  When non-empty AND $admin_csrf_token
 *                                  is also non-empty, the sidebar is
 *                                  rendered. Pages without a signed-in
 *                                  session (login.php) leave this null,
 *                                  which suppresses the sidebar entirely.
 *   ?string $admin_csrf_token   Active session's CSRF token. Required
 *                               whenever $admin_user_signed_in is set —
 *                               wired into the hidden input on the
 *                               sidebar's Logout form.
 *
 * The required app-version + asset-base symbols are pulled from
 * includes/config.php (which the admin pages already require for their
 * boot). The CSP nonce is taken from $csp_nonce if it has been seeded;
 * otherwise a fresh nonce is minted for the inline theme-init script
 * (admin pages today do not emit a global CSP header — the inline
 * script-src 'unsafe-inline' implicitly applies, so the nonce is purely
 * for forward-compat with #349's CSP knobs).
 */

global $app_version;

$_h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

if (!isset($csp_nonce) || !is_string($csp_nonce) || $csp_nonce === '') {
    // Forward-compat with the upcoming admin CSP rollout. Lower entropy is
    // fine here — admin templates do not currently send a CSP header.
    $csp_nonce = bin2hex(random_bytes(8));
}

// Breadcrumb chip — inserted between version pill and theme toggle by
// _app_header.php. Uses the WAI-ARIA breadcrumb pattern (ordered list +
// aria-current="page" on the leaf).
$breadcrumb_leaf = $_h($admin_breadcrumb ?? $page_title);
$breadcrumb_html = '<nav class="admin-breadcrumb" aria-label="Breadcrumb">'
    . '<ol>'
    . '<li><a href="./">Admin</a></li>'
    . '<li aria-current="page">' . $breadcrumb_leaf . '</li>'
    . '</ol>'
    . '</nav>';

// Hint to the shared header partial that this is an admin page (no
// calculator-only buttons, outer card carries .admin-card alongside .card).
$show_app_actions       = false;
$admin_card_extra_class = 'admin-card';

// Sidebar gating. When both username + csrf are supplied we render the
// sidebar; otherwise we fall back to the no-sidebar shell (login.php).
$admin_user = isset($admin_user_signed_in) && is_string($admin_user_signed_in)
    ? $admin_user_signed_in : '';
$admin_csrf = isset($admin_csrf_token) && is_string($admin_csrf_token)
    ? $admin_csrf_token : '';
$render_sidebar = $admin_user !== '' && $admin_csrf !== '';

// The user-chip / logout cluster used to inject into the header in #343
// — the sidebar now owns that surface, so the header partial receives no
// session HTML.
$header_session_html = null;

// Asset base path: admin pages live one level deep, so static assets
// resolve via "../assets/…".
$asset_base = '../assets';
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $_h($page_title) ?> — Subnet Calculator Admin</title>
    <meta name="theme-color" content="#0d1117">
    <meta name="color-scheme" content="dark light">
    <link rel="icon" type="image/webp" href="<?= $_h($asset_base) ?>/favicon-32-dark.webp?v=<?= $_h((string)$app_version) ?>" media="(prefers-color-scheme: dark)">
    <link rel="icon" type="image/png"  href="<?= $_h($asset_base) ?>/favicon-32-dark.png?v=<?= $_h((string)$app_version) ?>"  media="(prefers-color-scheme: dark)">
    <link rel="icon" type="image/webp" href="<?= $_h($asset_base) ?>/favicon-32-light.webp?v=<?= $_h((string)$app_version) ?>" media="(prefers-color-scheme: light)">
    <link rel="icon" type="image/png"  href="<?= $_h($asset_base) ?>/favicon-32-light.png?v=<?= $_h((string)$app_version) ?>"  media="(prefers-color-scheme: light)">
    <script nonce="<?= $_h($csp_nonce) ?>">(function(){var t=localStorage.getItem('theme')||(window.matchMedia('(prefers-color-scheme: light)').matches?'light':null);if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="<?= $_h($asset_base) ?>/app.css?v=<?= $_h((string)$app_version) ?>">
</head>
<body class="admin-shell<?= $render_sidebar ? '' : ' no-sidebar' ?>">
<?php
// Skip-link first in the DOM order so keyboard users can bypass the
// sidebar straight to #main-content. _app_header.php is told to suppress
// its built-in skip-link so we don't render two of them.
?>
<a href="#main-content" class="skip-link">Skip to main content</a>
<?php $skip_link_emitted = true; ?>
<?php if ($render_sidebar) : ?>
<div class="admin-shell-grid">
    <?php require __DIR__ . '/../admin/_sidebar.php'; ?>
    <?php require __DIR__ . '/_app_header.php'; ?>
        <?= $admin_card_body ?? '' ?>
    </main>
</div>
<?php else : ?>
<?php require __DIR__ . '/_app_header.php'; ?>
    <?= $admin_card_body ?? '' ?>
</main>
<?php endif; ?>
<script src="<?= $_h($asset_base) ?>/app.js?v=<?= $_h((string)$app_version) ?>" defer></script>
</body>
</html>
