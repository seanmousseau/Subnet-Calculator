<?php

declare(strict_types=1);

/**
 * Shared app header partial — extracted from templates/layout.php in
 * v3.2.0 (#345) so /admin/ pages can mount the same logo / wordmark /
 * version pill / theme toggle as the calculator.
 *
 * Caller contract (locals expected in scope when this file is required):
 *
 *   string  $page_title         Page <h1> text.
 *   string  $app_version        e.g. "3.2.0".
 *   bool    $show_app_actions   true → render the calculator-only
 *                               history + keyboard-shortcuts buttons.
 *                               Defaults to true via null-coalescing
 *                               so existing callers keep working.
 *   ?string $breadcrumb_html    null → no breadcrumb. Pre-rendered HTML
 *                               (already escaped) inserted between the
 *                               version pill and the theme toggle.
 *
 * The skip-link target (#main-content) and the <main class="card"> wrapper
 * are EMITTED HERE so the partial owns the calculator/admin landmark
 * boundary. Callers must close the </main> tag themselves (or, for the
 * admin layout, the outer card-wrapping helper closes it).
 */

$_show_app_actions    = $show_app_actions ?? true;
$_breadcrumb_html     = $breadcrumb_html ?? null;
$_header_session_html = (isset($header_session_html) && is_string($header_session_html))
    ? $header_session_html : null;
// v3.2.0 #344 — admin layout pre-emits the skip-link before the sidebar
// so keyboard users can jump straight to #main-content. When the caller
// has already emitted the skip-link, suppress the duplicate here.
$_suppress_skip_link  = !empty($skip_link_emitted);
?>
<?php if (!$_suppress_skip_link) : ?>
<a href="#main-content" class="skip-link">Skip to main content</a>
<?php endif; ?>
<main class="card<?= isset($admin_card_extra_class) ? ' ' . htmlspecialchars((string)$admin_card_extra_class) : '' ?>" id="main-content">
    <div class="title-row">
        <?php $logo_v = htmlspecialchars($app_version) . '-2'; ?>
        <picture>
            <source srcset="<?= htmlspecialchars($asset_base ?? 'assets') ?>/logo.webp?v=<?= $logo_v ?>" type="image/webp">
            <img src="<?= htmlspecialchars($asset_base ?? 'assets') ?>/logo.png?v=<?= $logo_v ?>" alt="Subnet Calculator logo" class="logo">
        </picture>
        <h1><?= htmlspecialchars($page_title) ?></h1>
        <span class="version">v<?= htmlspecialchars($app_version) ?></span>
        <?php if ($_breadcrumb_html !== null) {
            echo $_breadcrumb_html;
        } ?>
        <?php if ($_header_session_html !== null) {
            // Signed-in chip + Logout form (admin pages only, v3.2.0 #343).
            // Pre-rendered + escaped by _admin_layout.php.
            echo $_header_session_html;
        } ?>
        <button id="theme-toggle" class="theme-toggle" title="Toggle light/dark mode" aria-label="Switch to light mode">
            <svg class="icon-sun" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
            <svg class="icon-moon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        </button>
        <?php if ($_show_app_actions) : ?>
        <button id="history-toggle" class="header-icon-btn" type="button"
                title="Recent calculations (H)" aria-label="Recent calculations" aria-haspopup="dialog">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/></svg>
        </button>
        <button id="kbd-help-toggle" class="header-icon-btn" type="button"
                title="Keyboard shortcuts (?)" aria-label="Keyboard shortcuts" aria-haspopup="dialog">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M6 14h.01M18 14h.01M10 14h4"/></svg>
        </button>
        <?php endif; ?>
    </div>
