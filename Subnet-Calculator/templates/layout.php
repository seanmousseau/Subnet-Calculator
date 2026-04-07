?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= htmlspecialchars($page_description) ?>">
    <meta property="og:title"       content="<?= htmlspecialchars($page_title) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($page_description) ?>">
    <meta property="og:type"        content="website">
    <meta property="og:url"         content="<?= $canonical_url ?>">
    <link rel="canonical" href="<?= $canonical_url ?>">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link rel="icon" type="image/webp" href="logo/favicon-32.webp">
    <link rel="icon" type="image/png"  href="logo/favicon-32.png">
    <meta property="og:image" content="<?= $canonical_url ?>/logo/logo.webp">
    <meta name="twitter:card" content="summary">
    <?php if ($turnstile_curl_missing): ?>
    <!-- sc-warning: Turnstile is configured but the PHP cURL extension is not loaded.
         Captcha verification is being skipped. Install php-curl to enable it. -->
    <?php endif; ?>
    <script nonce="<?= htmlspecialchars($csp_nonce) ?>">(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
    <style nonce="<?= htmlspecialchars($csp_nonce) ?>">
        :root {
            /* Page background */
            --color-bg:           #0f172a;
            /* Card & surfaces */
            --color-surface:      #1e293b;
            --color-surface-alt:  #111827;
            --color-border:       #334155;
            /* Text */
            --color-text:         #e2e8f0;
            --color-text-heading: #f8fafc;
            --color-text-muted:   #64748b;
            --color-text-subtle:  #94a3b8;
            --color-text-faint:   #475569;
            /* Inputs — separate from page bg so each can be themed independently */
            --color-input-bg:     #0f172a;
            --color-input-text:   #f1f5f9;
            /* Accents */
            --color-accent:       #3b82f6;
            --color-accent-hover: #2563eb;
            --color-accent-light: #38bdf8;
            --color-green:        #4ade80;
            /* Error */
            --color-error-bg:     #450a0a;
            --color-error-border: #7f1d1d;
            --color-error-text:   #fca5a5;
            /* Button text */
            --color-btn-text:     #fff;
        }

        html[data-theme="light"] {
            --color-bg:           #ffffff;
            --color-surface:      #ffffff;
            --color-surface-alt:  #f8fafc;
            --color-border:       #cbd5e1;
            --color-text:         #1e293b;
            --color-text-heading: #0f172a;
            --color-text-muted:   #64748b;
            --color-text-subtle:  #475569;
            --color-text-faint:   #94a3b8;
            --color-input-bg:     #ffffff;
            --color-input-text:   #0f172a;
            --color-accent:       #3b82f6;
            --color-accent-hover: #2563eb;
            --color-accent-light: #1d4ed8;
            --color-green:        #16a34a;
            --color-error-bg:     #fef2f2;
            --color-error-border: #fca5a5;
            --color-error-text:   #dc2626;
            --color-btn-text:     #fff;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: var(--color-bg);
            color: var(--color-text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        .card {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: 12px;
            padding: 2rem;
            width: 100%;
            max-width: 560px;
        }

        .title-row {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin-bottom: 1.25rem;
        }

        .logo { width: 48px; height: 48px; flex-shrink: 0; }

        h1 { font-size: 1.5rem; font-weight: 700; color: var(--color-text-heading); }

        .version {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--color-text-faint);
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: 4px;
            padding: 0.15rem 0.4rem;
            letter-spacing: 0.04em;
        }

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--color-border);
            margin-bottom: 1.5rem;
        }

        .tab-btn {
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            border-radius: 0;
            color: var(--color-text-muted);
            cursor: pointer;
            flex: 0;
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: -1px;
            padding: 0.5rem 1.25rem;
            transition: color 0.15s, border-color 0.15s;
            width: auto;
        }

        .tab-btn:hover { background: none; color: var(--color-text); }
        .tab-btn.active { border-bottom-color: var(--color-accent); color: var(--color-accent-light); }

        .panel { display: none; }
        .panel.active { display: block; }

        /* Form */
        .form-row {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .form-group { display: flex; flex-direction: column; flex: 1; }

        label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--color-text-subtle);
            margin-bottom: 0.4rem;
        }

        input[type="text"] {
            background: var(--color-input-bg);
            border: 1px solid var(--color-border);
            border-radius: 6px;
            color: var(--color-input-text);
            font-family: 'Courier New', monospace;
            font-size: 0.95rem;
            padding: 0.55rem 0.75rem;
            transition: border-color 0.15s;
            width: 100%;
        }

        input[type="text"]:focus { outline: none; border-color: var(--color-accent); }
        input[type="text"]::placeholder { color: var(--color-text-faint); }

        .btn-row { display: flex; gap: 0.75rem; margin-top: 0.25rem; }

        button[type="submit"] {
            flex: 1;
            background: var(--color-accent);
            border: none;
            border-radius: 6px;
            color: var(--color-btn-text);
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 600;
            padding: 0.65rem 1rem;
            transition: background 0.15s;
        }

        button[type="submit"]:hover { background: var(--color-accent-hover); }

        a.btn.reset {
            flex: 1;
            background: var(--color-input-bg);
            border: 1px solid var(--color-border);
            border-radius: 6px;
            color: var(--color-text-subtle);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            font-weight: 600;
            padding: 0.65rem 1rem;
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }

        a.btn.reset:hover { background: var(--color-border); color: var(--color-text); }

        /* Error */
        .error {
            background: var(--color-error-bg);
            border: 1px solid var(--color-error-border);
            border-radius: 6px;
            color: var(--color-error-text);
            font-size: 0.875rem;
            margin-top: 1.25rem;
            padding: 0.65rem 0.85rem;
        }

        /* Results */
        .results {
            margin-top: 1.5rem;
            border: 1px solid var(--color-border);
            border-radius: 8px;
            overflow: hidden;
        }

        .results-header {
            background: var(--color-input-bg);
            color: var(--color-text-muted);
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            padding: 0.6rem 1rem;
            text-transform: uppercase;
        }

        .result-row {
            align-items: center;
            background: var(--color-input-bg);
            border-top: 1px solid var(--color-surface);
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            padding: 0.65rem 1rem;
            transition: background 0.1s;
        }

        .result-row:nth-child(odd) { background: var(--color-surface-alt); }
        .result-row:hover { background: var(--color-border) !important; }

        .result-label { color: var(--color-text-subtle); font-size: 0.8rem; }

        .result-value {
            color: var(--color-accent-light);
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .result-value::after {
            content: ' \29d8';
            color: transparent;
            font-size: 0.8em;
            transition: color 0.15s;
        }

        .result-row:hover .result-value::after { color: var(--color-text-faint); }

        .hosts-row .result-value { color: var(--color-green); }

        /* Address type badge */
        .badge {
            border-radius: 4px;
            font-size: 0.72rem;
            font-weight: 600;
            padding: 0.15rem 0.45rem;
        }
        .badge-private   { background: rgba(245,158,11,0.15); color: #f59e0b; }
        .badge-public    { background: rgba(74,222,128,0.15); color: #4ade80; }
        .badge-loopback  { background: rgba(167,139,250,0.15); color: #a78bfa; }
        .badge-link-local{ background: rgba(251,191,36,0.15); color: #fbbf24; }
        .badge-multicast { background: rgba(248,113,113,0.15); color: #f87171; }
        .badge-doc       { background: rgba(96,165,250,0.15); color: #60a5fa; }
        .badge-ula       { background: rgba(148,163,184,0.15); color: var(--color-text-subtle); }
        .badge-other     { background: rgba(148,163,184,0.15); color: var(--color-text-subtle); }

        html[data-theme="light"] .badge-private   { color: #92400e; }
        html[data-theme="light"] .badge-public    { color: #14532d; }
        html[data-theme="light"] .badge-loopback  { color: #4c1d95; }
        html[data-theme="light"] .badge-link-local{ color: #78350f; }
        html[data-theme="light"] .badge-multicast { color: #991b1b; }
        html[data-theme="light"] .badge-doc       { color: #1e3a5f; }

        /* Theme toggle */
        .theme-toggle {
            background: none;
            border: 1px solid var(--color-border);
            border-radius: 6px;
            color: var(--color-text-subtle);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-left: auto;
            padding: 0.3rem 0.4rem;
            transition: background 0.15s, color 0.15s;
        }
        .theme-toggle:hover { background: var(--color-border); color: var(--color-text); }
        .icon-moon { display: none; }
        html[data-theme="light"] .icon-moon { display: block; }
        html[data-theme="light"] .icon-sun  { display: none; }

        /* Honeypot — always hidden regardless of CSS cascade */
        .sc-honeypot { display: none !important; }

        /* Turnstile container */
        .cf-turnstile { margin-top: 0.75rem; }

        footer {
            margin-top: 1.25rem;
            text-align: center;
        }

        footer a {
            color: var(--color-text-faint);
            font-size: 0.75rem;
            text-decoration: none;
            transition: color 0.15s;
        }

        footer a:hover { color: var(--color-text-subtle); }

        /* Share bar */
        .share-bar {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.75rem;
            background: var(--color-surface-alt);
            border: 1px solid var(--color-border);
            border-radius: 6px;
            padding: 0.5rem 0.75rem;
        }

        .share-label {
            color: var(--color-text-faint);
            font-size: 0.7rem;
            font-weight: 600;
            flex-shrink: 0;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .share-url {
            color: var(--color-text-subtle);
            font-family: 'Courier New', monospace;
            font-size: 0.75rem;
            flex: 1;
            word-break: break-all;
            overflow-wrap: anywhere;
            user-select: all;
        }

        .share-copy {
            background: none;
            border: 1px solid var(--color-border);
            border-radius: 4px;
            color: var(--color-text-subtle);
            cursor: pointer;
            font-size: 0.7rem;
            font-weight: 600;
            padding: 0.2rem 0.5rem;
            flex-shrink: 0;
            transition: background 0.15s, color 0.15s;
        }

        .share-copy:hover { background: var(--color-border); color: var(--color-text); }

        /* Toast */
        .toast {
            position: fixed;
            bottom: 1.5rem;
            left: 50%;
            transform: translateX(-50%) translateY(0.5rem);
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: 6px;
            color: var(--color-text);
            font-size: 0.8rem;
            font-weight: 600;
            opacity: 0;
            padding: 0.5rem 1rem;
            pointer-events: none;
            transition: opacity 0.2s, transform 0.2s;
            z-index: 100;
        }

        .toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        /* Subnet splitter */
        .splitter {
            margin-top: 1rem;
            border: 1px solid var(--color-border);
            border-radius: 8px;
            overflow: hidden;
        }

        .splitter-title {
            background: var(--color-input-bg);
            color: var(--color-text-muted);
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            padding: 0.6rem 1rem;
            text-transform: uppercase;
        }

        .splitter-form {
            padding: 0.75rem 1rem;
            border-top: 1px solid var(--color-border);
            background: var(--color-surface-alt);
        }

        .splitter-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .splitter-label {
            color: var(--color-text-subtle);
            font-size: 0.8rem;
            flex-shrink: 0;
        }

        .splitter-input {
            width: 80px !important;
        }

        .splitter-btn {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: 6px;
            color: var(--color-text-subtle);
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            padding: 0.45rem 0.75rem;
            transition: background 0.15s, color 0.15s;
            white-space: nowrap;
        }

        .splitter-btn:hover { background: var(--color-border); color: var(--color-text); }
        .splitter .error { margin: 0.75rem 1rem; }
        .form-group-narrow { max-width: 120px; }

        .split-list {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.4rem;
            padding: 0.75rem 1rem;
            border-top: 1px solid var(--color-border);
            background: var(--color-surface-alt);
        }

        .split-item {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: 4px;
            color: var(--color-accent-light);
            cursor: pointer;
            font-family: 'Courier New', monospace;
            font-size: 0.78rem;
            padding: 0.3rem 0.5rem;
            text-align: center;
            transition: background 0.1s;
        }

        .split-item:hover { background: var(--color-border); }

        .result-row:focus-visible {
            outline: 2px solid var(--color-accent);
            outline-offset: -2px;
            background: var(--color-border) !important;
        }

        .split-item:focus-visible {
            outline: 2px solid var(--color-accent);
            outline-offset: -1px;
        }

        .split-more {
            color: var(--color-text-faint);
            font-size: 0.75rem;
            grid-column: 1 / -1;
            text-align: center;
            padding: 0.15rem 0;
        }

        @media (max-width: 480px) {
            .form-row { flex-direction: column; }
            .split-list { grid-template-columns: 1fr; }
        }

        @media print {
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            html, body, .card {
                background: #fff !important;
                color: #000 !important;
                box-shadow: none !important;
                border: none !important;
            }
            body { min-height: 0; }
            .tabs, .btn-row, .share-bar, .splitter-form,
            .theme-toggle, .version, footer, .toast { display: none !important; }
            .panel.active { display: block !important; }
            .card { padding: 0; max-width: 100%; }
            .result-row { border-bottom: 1px solid #ccc; }
            .badge { border: 1px solid #999; }
            .split-list { columns: 2; display: block; }
        }

        html.in-iframe,
        html.in-iframe body {
            margin: 0;
            padding: 0;
            overflow: hidden;
        }
        html.in-iframe body {
            min-height: 0;
            align-items: flex-start;
        }
    </style>
    <?php if ($bg_override_style) echo '<style nonce="' . htmlspecialchars($csp_nonce) . '">' . $bg_override_style . '</style>'; ?>
    <?php if ($turnstile_active): ?>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>
</head>
<body>
<div class="card">
    <div class="title-row">
        <picture>
            <source srcset="logo/logo.webp" type="image/webp">
            <img src="logo/logo.png" alt="Subnet Calculator logo" class="logo">
        </picture>
        <h1><?= htmlspecialchars($page_title) ?></h1>
        <span class="version">v1.0.1</span>
        <button id="theme-toggle" class="theme-toggle" title="Toggle light/dark mode" aria-label="Switch to light mode">
            <svg class="icon-sun" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
            <svg class="icon-moon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        </button>
    </div>

    <div class="tabs" role="tablist" aria-label="IP version">
        <button class="tab-btn<?= $active_tab === 'ipv4' ? ' active' : '' ?>"
                role="tab" id="tab-ipv4"
                aria-selected="<?= $active_tab === 'ipv4' ? 'true' : 'false' ?>"
                aria-controls="panel-ipv4"
                data-tab="ipv4">IPv4</button>
        <button class="tab-btn<?= $active_tab === 'ipv6' ? ' active' : '' ?>"
                role="tab" id="tab-ipv6"
                aria-selected="<?= $active_tab === 'ipv6' ? 'true' : 'false' ?>"
                aria-controls="panel-ipv6"
                data-tab="ipv6">IPv6</button>
    </div>

    <!-- IPv4 Panel -->
    <div id="panel-ipv4" class="panel<?= $active_tab === 'ipv4' ? ' active' : '' ?>"
         role="tabpanel" aria-labelledby="tab-ipv4" tabindex="-1">
        <form method="post" novalidate>
            <input type="hidden" name="tab" value="ipv4">
            <div class="form-row">
                <div class="form-group">
                    <label for="ip">IP Address</label>
                    <input type="text" id="ip" name="ip"
                           placeholder="192.168.1.0 or 192.168.1.0/24"
                           value="<?= htmlspecialchars($input_ip) ?>"
                           autocomplete="off" spellcheck="false"
                           <?= $error ? 'aria-invalid="true" aria-describedby="ipv4-error"' : '' ?>>
                </div>
                <div class="form-group">
                    <label for="mask">Netmask</label>
                    <input type="text" id="mask" name="mask"
                           placeholder="/24 or 255.255.255.0"
                           value="<?= htmlspecialchars($input_mask) ?>"
                           autocomplete="off" spellcheck="false"
                           <?= $error ? 'aria-invalid="true" aria-describedby="ipv4-error"' : '' ?>>
                </div>
            </div>
            <div class="btn-row">
                <button type="submit">Calculate</button>
                <a href="?" class="btn reset">Reset</a>
            </div>
            <?php if ($form_protection === 'honeypot'): ?>
                <input type="text" name="url" class="sc-honeypot" tabindex="-1" autocomplete="off" value="">
            <?php endif; ?>
            <?php if ($turnstile_active): ?>
                <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($turnstile_site_key) ?>"></div>
            <?php endif; ?>
        </form>

        <?php if ($error): ?>
            <div class="error" id="ipv4-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($result): ?>
            <div class="results">
                <div class="results-header">Results</div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Subnet (CIDR)</span>
                    <span class="result-value"><?= htmlspecialchars($result['network_cidr']) ?></span>
                </div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Netmask (CIDR)</span>
                    <span class="result-value"><?= htmlspecialchars($result['netmask_cidr']) ?></span>
                </div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Netmask (Octet)</span>
                    <span class="result-value"><?= htmlspecialchars($result['netmask_octet']) ?></span>
                </div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Wildcard Mask</span>
                    <span class="result-value"><?= htmlspecialchars($result['wildcard']) ?></span>
                </div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">First Usable IP</span>
                    <span class="result-value"><?= htmlspecialchars($result['first_usable']) ?></span>
                </div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Last Usable IP</span>
                    <span class="result-value"><?= htmlspecialchars($result['last_usable']) ?></span>
                </div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Broadcast IP</span>
                    <span class="result-value"><?= htmlspecialchars($result['broadcast']) ?></span>
                </div>
                <div class="result-row hosts-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Usable IPs</span>
                    <span class="result-value"><?= number_format($result['usable_hosts']) ?></span>
                </div>
                <?php $ip4type = get_ipv4_type($input_ip); ?>
                <div class="result-row">
                    <span class="result-label">Address Type</span>
                    <span class="result-value"><span class="badge badge-<?= type_badge_class($ip4type) ?>"><?= htmlspecialchars($ip4type) ?></span></span>
                </div>
            </div>
            <?php if ($show_share_bar): ?>
            <div class="share-bar">
                <span class="share-label">Share</span>
                <code class="share-url"><?= htmlspecialchars($share_url_abs) ?></code>
                <button type="button" class="share-copy" data-copy="<?= htmlspecialchars($share_url) ?>">Copy</button>
            </div>
            <?php endif; ?>
            <div class="splitter">
                <div class="splitter-title">Split Subnet</div>
                <form method="post" class="splitter-form">
                    <input type="hidden" name="tab" value="ipv4">
                    <input type="hidden" name="ip" value="<?= htmlspecialchars($input_ip) ?>">
                    <input type="hidden" name="mask" value="<?= htmlspecialchars($input_mask) ?>">
                    <div class="splitter-row">
                        <span class="splitter-label">Split into</span>
                        <input type="text" name="split_prefix" class="splitter-input"
                               placeholder="/25" value="<?= htmlspecialchars($input_split_prefix) ?>"
                               autocomplete="off" spellcheck="false"
                               <?= $split_error ? 'aria-invalid="true" aria-describedby="split-error-ipv4"' : '' ?>>
                        <button type="submit" class="splitter-btn">Split</button>
                    </div>
                </form>
                <?php if ($split_error): ?>
                    <div class="error" id="split-error-ipv4"><?= htmlspecialchars($split_error) ?></div>
                <?php elseif ($split_result && $split_result['showing'] > 0): ?>
                    <div class="split-list">
                        <?php foreach ($split_result['subnets'] as $s): ?>
                            <div class="split-item" tabindex="0" role="button" data-copy="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></div>
                        <?php endforeach; ?>
                        <?php if ($split_result['total'] > $split_result['showing']): ?>
                            <div class="split-more">+&nbsp;<?= number_format($split_result['total'] - $split_result['showing']) ?> more</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- IPv6 Panel -->
    <div id="panel-ipv6" class="panel<?= $active_tab === 'ipv6' ? ' active' : '' ?>"
         role="tabpanel" aria-labelledby="tab-ipv6" tabindex="-1">
        <form method="post" novalidate>
            <input type="hidden" name="tab" value="ipv6">
            <div class="form-row">
                <div class="form-group">
                    <label for="ipv6">IPv6 Address</label>
                    <input type="text" id="ipv6" name="ipv6"
                           placeholder="2001:db8::1 or 2001:db8::/32"
                           value="<?= htmlspecialchars($input_ipv6) ?>"
                           autocomplete="off" spellcheck="false"
                           <?= $error6 ? 'aria-invalid="true" aria-describedby="ipv6-error"' : '' ?>>
                </div>
                <div class="form-group form-group-narrow">
                    <label for="prefix">Prefix</label>
                    <input type="text" id="prefix" name="prefix"
                           placeholder="/64"
                           value="<?= htmlspecialchars($input_prefix) ?>"
                           autocomplete="off" spellcheck="false"
                           <?= $error6 ? 'aria-invalid="true" aria-describedby="ipv6-error"' : '' ?>>
                </div>
            </div>
            <div class="btn-row">
                <button type="submit">Calculate</button>
                <a href="?tab=ipv6" class="btn reset">Reset</a>
            </div>
            <?php if ($form_protection === 'honeypot'): ?>
                <input type="text" name="url" class="sc-honeypot" tabindex="-1" autocomplete="off" value="">
            <?php endif; ?>
            <?php if ($turnstile_active): ?>
                <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($turnstile_site_key) ?>"></div>
            <?php endif; ?>
        </form>

        <?php if ($error6): ?>
            <div class="error" id="ipv6-error"><?= htmlspecialchars($error6) ?></div>
        <?php endif; ?>

        <?php if ($result6): ?>
            <div class="results">
                <div class="results-header">Results</div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Network (CIDR)</span>
                    <span class="result-value"><?= htmlspecialchars($result6['network_cidr']) ?></span>
                </div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Prefix Length</span>
                    <span class="result-value"><?= htmlspecialchars($result6['prefix']) ?></span>
                </div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">First IP</span>
                    <span class="result-value"><?= htmlspecialchars($result6['first_ip']) ?></span>
                </div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Last IP</span>
                    <span class="result-value"><?= htmlspecialchars($result6['last_ip']) ?></span>
                </div>
                <div class="result-row hosts-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Total Addresses</span>
                    <span class="result-value"><?= htmlspecialchars($result6['total']) ?></span>
                </div>
                <?php $ip6type = get_ipv6_type($input_ipv6); ?>
                <div class="result-row">
                    <span class="result-label">Address Type</span>
                    <span class="result-value"><span class="badge badge-<?= type_badge_class($ip6type) ?>"><?= htmlspecialchars($ip6type) ?></span></span>
                </div>
            </div>
            <?php if ($show_share_bar): ?>
            <div class="share-bar">
                <span class="share-label">Share</span>
                <code class="share-url"><?= htmlspecialchars($share_url_abs) ?></code>
                <button type="button" class="share-copy" data-copy="<?= htmlspecialchars($share_url) ?>">Copy</button>
            </div>
            <?php endif; ?>
            <div class="splitter">
                <div class="splitter-title">Split Subnet</div>
                <form method="post" class="splitter-form">
                    <input type="hidden" name="tab" value="ipv6">
                    <input type="hidden" name="ipv6" value="<?= htmlspecialchars($input_ipv6) ?>">
                    <input type="hidden" name="prefix" value="<?= htmlspecialchars($input_prefix) ?>">
                    <div class="splitter-row">
                        <span class="splitter-label">Split into</span>
                        <input type="text" name="split_prefix6" class="splitter-input"
                               placeholder="/65" value="<?= htmlspecialchars($input_split_prefix6) ?>"
                               autocomplete="off" spellcheck="false"
                               <?= $split_error6 ? 'aria-invalid="true" aria-describedby="split-error-ipv6"' : '' ?>>
                        <button type="submit" class="splitter-btn">Split</button>
                    </div>
                </form>
                <?php if ($split_error6): ?>
                    <div class="error" id="split-error-ipv6"><?= htmlspecialchars($split_error6) ?></div>
                <?php elseif ($split_result6 && $split_result6['showing'] > 0): ?>
                    <div class="split-list">
                        <?php foreach ($split_result6['subnets'] as $s): ?>
                            <div class="split-item" tabindex="0" role="button" data-copy="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></div>
                        <?php endforeach; ?>
                        <?php
                            $total6   = $split_result6['total'];
                            $showing6 = $split_result6['showing'];
                            $has_more6 = is_numeric($total6) ? ($showing6 < (int)$total6) : true;
                            $more_label6 = is_numeric($total6) ? number_format((int)$total6 - $showing6) : $total6 . ' total';
                        ?>
                        <?php if ($has_more6): ?>
                            <div class="split-more">+&nbsp;<?= $more_label6 ?> more</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <footer>
        <a href="https://github.com/seanmousseau/Subnet-Calculator" target="_blank" rel="noopener noreferrer">github.com/seanmousseau/Subnet-Calculator</a>
    </footer>
</div>

<div id="toast" class="toast" role="status" aria-live="polite" aria-atomic="true">Copied!</div>

<script nonce="<?= htmlspecialchars($csp_nonce) ?>">
// ── Theme toggle ─────────────────────────────────────────────────────────────
// Icon visibility is driven by CSS html[data-theme="light"] selectors — no JS needed.
function updateThemeToggleLabel() {
    const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
    document.getElementById('theme-toggle')
        .setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
}
document.getElementById('theme-toggle').addEventListener('click', () => {
    const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
    updateThemeToggleLabel();
});
updateThemeToggleLabel();

// ── Tab switcher ─────────────────────────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(b => {
            b.classList.remove('active');
            b.setAttribute('aria-selected', 'false');
        });
        document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        btn.setAttribute('aria-selected', 'true');
        document.getElementById('panel-' + btn.dataset.tab).classList.add('active');
        autoFocusActive();
    });

    btn.addEventListener('keydown', e => {
        const tabs = [...document.querySelectorAll('.tab-btn')];
        const idx = tabs.indexOf(e.currentTarget);
        let next = null;
        if (e.key === 'ArrowRight') next = tabs[(idx + 1) % tabs.length];
        if (e.key === 'ArrowLeft')  next = tabs[(idx - 1 + tabs.length) % tabs.length];
        if (next) { e.preventDefault(); next.focus(); next.click(); }
    });
});

// ── Copy to clipboard (with execCommand fallback for cross-origin iframes) ───
function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(window._toastTimer);
    window._toastTimer = setTimeout(() => t.classList.remove('show'), 1500);
}

function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try { document.execCommand('copy'); showToast('Copied!'); } catch (e) { showToast('Copy failed'); }
    document.body.removeChild(ta);
}

function copyText(text, successMsg) {
    successMsg = successMsg || 'Copied!';
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => showToast(successMsg)).catch(() => fallbackCopy(text));
    } else {
        fallbackCopy(text);
    }
}

document.querySelectorAll('.results').forEach(results => {
    results.addEventListener('click', e => {
        const row = e.target.closest('.result-row');
        if (!row) return;
        const val = row.querySelector('.result-value');
        if (!val) return;
        copyText(val.textContent.trim());
    });
});

// ── Share URL: show full URL and copy it ─────────────────────────────────────
const _base = window.location.origin + window.location.pathname;
document.querySelectorAll('.share-url').forEach(el => {
    // Override server-provided absolute URL with window.location for reverse-proxy accuracy
    const btn = el.closest('.share-bar')?.querySelector('.share-copy');
    if (btn) el.textContent = _base + btn.dataset.copy;
});
document.querySelectorAll('.share-copy').forEach(btn => {
    btn.addEventListener('click', () => {
        copyText(_base + btn.dataset.copy, 'Link copied!');
    });
});

// ── Subnet splitter: click to copy ──────────────────────────────────────────
document.querySelectorAll('.split-item').forEach(item => {
    item.addEventListener('click', () => copyText(item.dataset.copy));
});

// ── Keyboard activation for copy targets (result rows + split items) ─────────
document.querySelectorAll('.result-row[tabindex], .split-item').forEach(function (el) {
    el.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            el.click();
        }
    });
});

// ── Input auto-detection (paste "192.168.1.0/24" into IP field) ──────────────
function autoDetect(ipId, maskId) {
    const ipEl   = document.getElementById(ipId);
    const maskEl = document.getElementById(maskId);
    if (!ipEl || !maskEl) return;
    const val   = ipEl.value.trim();
    const slash = val.indexOf('/');
    if (slash !== -1) {
        ipEl.value   = val.slice(0, slash).trim();
        maskEl.value = val.slice(slash).trim();
    }
}

document.getElementById('ip')?.addEventListener('blur',   () => autoDetect('ip',   'mask'));
document.getElementById('ipv6')?.addEventListener('blur', () => autoDetect('ipv6', 'prefix'));

// ── Auto-focus first empty input on active panel ─────────────────────────────
function autoFocusActive() {
    const panel = document.querySelector('.panel.active');
    if (!panel) return;
    const first = panel.querySelector('input[type="text"]');
    if (first && !first.value) first.focus();
}

autoFocusActive();

// ── iframe: auto-detect and report height to parent via postMessage ───────────
if (window.self !== window.top) {
    document.documentElement.classList.add('in-iframe');
    var _parentOrigin = (function () {
        // ancestorOrigins is always accurate (Chrome/Edge); unaffected by navigation
        if (window.location.ancestorOrigins && window.location.ancestorOrigins.length) {
            return window.location.ancestorOrigins[0];
        }
        // Firefox: persist the parent origin in sessionStorage so it
        // survives same-origin form-submit navigations within the iframe
        try {
            var stored = sessionStorage.getItem('_sc_parent_origin');
            if (stored) return stored;
        } catch (e) {}
        try {
            var o = new URL(document.referrer).origin;
            if (o !== window.location.origin) {
                try { sessionStorage.setItem('_sc_parent_origin', o); } catch (e) {}
                return o;
            }
        } catch (e) {}
        return null;
    })();
    (function () {
        function postHeight() {
            var card = document.querySelector('.card');
            // Use only the card's own height — body/document scrollHeight reflects
            // the iframe's current (parent-set) height and never shrinks on Reset.
            var h = card ? Math.ceil(card.getBoundingClientRect().height) : 0;
            window.parent.postMessage({ type: 'sc-resize', height: h }, _parentOrigin || '*');
        }
        postHeight();
        requestAnimationFrame(function () { postHeight(); });
        window.addEventListener('load', postHeight);
        if (window.ResizeObserver) {
            var card = document.querySelector('.card');
            if (card) new ResizeObserver(postHeight).observe(card);
            document.querySelectorAll('.cf-turnstile').forEach(function (el) {
                new ResizeObserver(postHeight).observe(el);
            });
        } else {
            // Fallback for browsers without ResizeObserver: poll 300 ms × 20 = 6 s
            var polls = 0;
            var timer = setInterval(function () { postHeight(); if (++polls >= 20) clearInterval(timer); }, 300);
        }
    })();
    // Listen for background colour commands from the parent page
    window.addEventListener('message', function (e) {
        if (_parentOrigin && e.origin !== _parentOrigin) return;
        if (!e.data || e.data.type !== 'sc-set-bg') return;
        var color = e.data.color;
        if (color && color !== 'null' && /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/.test(color)) {
            document.documentElement.style.setProperty('--color-bg', color);
            document.body.style.backgroundColor = color;
        } else {
            document.documentElement.style.removeProperty('--color-bg');
            document.body.style.backgroundColor = '';
        }
    });
}
</script>
</body>
</html>
