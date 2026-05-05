<?php

declare(strict_types=1);

/**
 * Shared /admin/ page shell — introduced in v3.2.0 (#345) to align the
 * admin chrome with the calculator. Renders:
 *
 *   <!doctype html>
 *   <head> (meta + theme-init + assets/app.css)
 *   <body>
 *     skip-link → <main id="main-content" class="card admin-card">
 *       title-row (logo + page title + version pill + breadcrumb + theme toggle)
 *       admin-card body (caller-supplied $admin_card_body)
 *       interim .admin-footer-links cluster (until #344's sidebar lands)
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

// Resolve the active admin page from SCRIPT_NAME so the footer link
// cluster can suppress the link to "this page" and tag it aria-current.
$_admin_script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));

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
<body>
<?php require __DIR__ . '/_app_header.php'; ?>
    <?= $admin_card_body ?? '' ?>

    <?php
    // Interim footer link cluster. Replaced by the left sidebar in #344.
    // Renders the active page as a non-link <span aria-current="page">.
    $admin_links = [
        'keys.php'  => 'API Keys',
        'totp.php'  => 'TOTP / 2FA',
        'audit.php' => 'Audit Log',
    ];
    ?>
    <nav class="admin-footer-links" aria-label="Admin sections">
        <?php foreach ($admin_links as $slug => $label) : ?>
            <?php if ($_admin_script === $slug) : ?>
                <span aria-current="page"><?= $_h($label) ?></span>
            <?php else : ?>
                <a href="<?= $_h($slug) ?>"><?= $_h($label) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
</main>
<script src="<?= $_h($asset_base) ?>/app.js?v=<?= $_h((string)$app_version) ?>" defer></script>
</body>
</html>
