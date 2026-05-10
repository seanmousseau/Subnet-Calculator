<?php

declare(strict_types=1);

// ─── Configuration defaults ───────────────────────────────────────────────────
// These are the built-in defaults. To override, copy config.php.example to
// config.php alongside this file — config.php is never overwritten by upgrades.

$app_version          = '3.7.0';
$locale               = 'en'; // BCP 47 locale tag for number formatting (e.g. 'de', 'fr')
$fixed_bg_color       = 'null';
$default_tab          = 'ipv4'; // 'ipv4', 'ipv6', or 'vlsm'
$split_max_subnets    = 16;
$lookup_max_cidrs     = 100;  // Inverse subnet lookup: max CIDRs per request
$lookup_max_ips       = 1000; // Inverse subnet lookup: max IPs per request
$range_max_cidrs      = 256;  // IP range → CIDR converter: max CIDRs returned (v3.3.0)
// Maximum number of children that prefix-plan6 will allocate in one request.
// Defaults to 256; clamped to a hard ceiling of 4096 regardless of operator
// override. Mirrors $split_max_subnets pattern. (v3.5.0)
$prefix_plan6_max_count = 256;
// Maximum number of reservation bits accepted by the RFC 3531
// sparse-allocation tool. Defaults to 8 (256 children); clamped to a
// hard ceiling of 12 (4096 children) regardless of operator override.
// Mirrors $prefix_plan6_max_count's clamped-cap pattern. (v3.5.0)
$rfc3531_max_bits = 8;
$form_protection      = 'none';
$turnstile_site_key   = '';
$turnstile_secret_key = '';
$hcaptcha_site_key    = '';
$hcaptcha_secret_key  = '';
$recaptcha_enterprise_site_key    = '';
$recaptcha_enterprise_api_key     = '';
$recaptcha_enterprise_project_id  = '';
$recaptcha_score_threshold        = 0.5;
$page_title           = 'Subnet Calculator';
$page_description     = 'Free online subnet calculator for IPv4 and IPv6. '
    . 'Calculate network address, broadcast, netmask, host range, and split subnets.';
$show_share_bar       = true;
$frame_ancestors      = '*';
$canonical_url        = '';

// CSP extension hooks (v3.1.1).  Operators can append space-separated origins
// to specific CSP directives without forking index.php.  Each value is
// concatenated as-is, so callers MUST pre-validate (origins only, no quotes,
// no commas, no newlines).  Production deploy at subnetcalculator.app uses
// these to allowlist the Cloudflare Zaraz / GA beacons that Cloudflare
// injects at the CDN edge.  Self-hosted operators can leave them empty.
$csp_connect_extra    = '';   // appended to connect-src
$csp_script_extra     = '';   // appended to script-src
$csp_img_extra        = '';   // appended to img-src

// REST API (v2.0.0)
$api_tokens              = [];   // [] = open; ['token1'] = auth required
$api_rate_limit_rpm      = 60;   // requests/minute per IP (0 = disabled)
$api_rate_limit_tokens   = [];   // per-token RPM overrides: ['token' => rpm]
$api_allowed_endpoints   = [];   // [] = all allowed; non-empty = allowlist
// CORS Access-Control-Allow-Origin (v3.6.3, #426).
// Default is ['*'] for back-compat with the open API. Set to a specific
// allowlist (e.g. ['https://example.com']) when API auth is configured.
// String '*' is also accepted for back-compat with pre-v3.6.3 configs.
$api_cors_origins        = ['*'];

// HSTS — Strict-Transport-Security header (v3.6.3, #425).
// Sent on HTTPS responses only. max-age clamped to 0..2 years.
$hsts_max_age = 31536000;  // 1 year (max 63072000 = 2 years)
$hsts_preload = false;     // operator opt-in; only enable after confirming
                            // HTTPS for all subdomains and a clean preload-list audit

// Trusted proxies — only honor X-Forwarded-For when REMOTE_ADDR is in this
// allowlist. Default empty = ignore XFF (safe for direct-exposure deploys).
// Set to ['127.0.0.1', '::1'] for a local reverse proxy, or to specific
// Cloudflare/other-CDN egress IPs. (v3.6.3, #427-M3)
$api_trusted_proxies = [];

// Session persistence (v2.0.0)
$session_enabled    = false;  // Enable SQLite-backed VLSM session save/restore
$session_db_path    = '';     // Absolute path to SQLite file (auto if empty)
$session_ttl_days   = 30;     // Days before a saved session expires

// API request logging (v2.6.0)
$api_request_log         = false;  // Enable SQLite-backed API request logging
$api_request_log_db_path = '';     // Absolute path to log DB (auto if empty)

// Admin UI + API key management (v2.12.0, #297)
$admin_ui_enabled  = false;  // Master switch for /admin/ pages and admin/keys API
$admin_user        = '';     // Admin username for HTTP Basic Auth on /admin/
$admin_pass_hash   = '';     // PASSWORD_BCRYPT hash of the admin password
$apikey_db_path    = '';     // SQLite file for api_keys (auto: data/sessions.sqlite or data/admin.sqlite)

// Admin audit log (v3.0.0, #306)
$admin_audit_retention_days = 90;  // 0 = never purge; rows older than this are removed on each audit write
// When true, prefer X-Forwarded-For over REMOTE_ADDR for audit IPs.
// Only enable behind a known-good reverse proxy that strips client-supplied XFF.
$admin_audit_trust_xff = false;

// Audit purge strategy (v3.1.0, #325). Controls when audit_log() invokes
// audit_purge() — the legacy 'inline' path purges on every write, which scales
// poorly under high audit volume (mass key rotation, login.fail floods).
//   'inline'  — purge on every write (legacy v3.0.0 behaviour)
//   'sampled' — purge probabilistically per $admin_audit_purge_sample_rate
//   'cron'    — never purge inline; operator runs bin/sc-audit-purge.php
$admin_audit_purge_strategy    = 'sampled';
// Probability (0.0–1.0) that any given audit_log() call triggers a purge when
// strategy is 'sampled'. Default 0.1% means ~1 in 1000 writes pays the cost.
$admin_audit_purge_sample_rate = 0.001;

// Admin TOTP / 2FA (v3.0.0, #313)
$admin_totp_secret = '';  // base32 RFC 6238 secret; empty = TOTP disabled

// Tree-editor preset library (v3.1.0, #323). Empty = use the bundled
// `Subnet-Calculator/data/tree-presets/` directory; set to an absolute path to
// load presets from elsewhere. A non-existent override falls back to the
// bundled location (with a single error_log warning).
$tree_presets_dir = '';

// Wizard-written config first (lowest tier); hand-edited config.php overrides.
// Order matters: PHP resolves the *last* assignment to a variable, so config.php
// runs second so an operator edit always wins over auto-written values.
if (file_exists(__DIR__ . '/../config-admin.php')) {
    require __DIR__ . '/../config-admin.php';
}
if (file_exists(__DIR__ . '/../config.php')) {
    require __DIR__ . '/../config.php';
}

// Sanitise config values
// Strict boolean parsing — `(bool)'false'` is true, but operators commonly
// write the word "false" in config strings.  filter_var with FILTER_VALIDATE_BOOLEAN
// handles "true"/"false"/"yes"/"no"/"on"/"off"/"0"/"1" correctly.
$admin_audit_trust_xff = filter_var(
    $admin_audit_trust_xff ?? false,
    FILTER_VALIDATE_BOOLEAN,
    FILTER_NULL_ON_FAILURE
) ?? false;
$split_max_subnets = max(1, min((int)$split_max_subnets, 256));
$lookup_max_cidrs  = max(1, min((int)$lookup_max_cidrs, 1000));
$lookup_max_ips    = max(1, min((int)$lookup_max_ips, 10000));
$range_max_cidrs   = max(1, min((int)$range_max_cidrs, 100000));
$prefix_plan6_max_count = min(max((int)$prefix_plan6_max_count, 1), 4096);
$rfc3531_max_bits        = min(max((int)$rfc3531_max_bits, 1), 12);
$fa = trim(preg_replace('/[\r\n]/', '', (string)$frame_ancestors));
if (!preg_match('/^(\*|\'none\'|\'self\'|(\s*(https?:\/\/[^\s;,]+))+)$/', $fa)) {
    error_log('sc: invalid $frame_ancestors value — reset to *');
    $frame_ancestors = '*';
} else {
    $frame_ancestors = $fa;
}
if (!in_array($form_protection, ['none', 'honeypot', 'turnstile', 'hcaptcha', 'recaptcha_enterprise'], true)) {
    error_log('sc: invalid $form_protection "' . $form_protection . '" — reset to "none"');
    $form_protection = 'none';
}
if (
    !is_numeric($recaptcha_score_threshold)
    || (float)$recaptcha_score_threshold < 0.0
    || (float)$recaptcha_score_threshold > 1.0
) {
    error_log('sc: invalid $recaptcha_score_threshold — reset to 0.5');
    $recaptcha_score_threshold = 0.5;
} else {
    $recaptcha_score_threshold = (float)$recaptcha_score_threshold;
}
if (!in_array($default_tab, ['ipv4', 'ipv6', 'vlsm'], true)) {
    error_log('sc: invalid $default_tab "' . $default_tab . '" — reset to "ipv4"');
    $default_tab = 'ipv4';
}

if ($canonical_url === '') {
    $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!preg_match('/^[a-zA-Z0-9.\-]+(:\d+)?$/', $_host)) {
        $_host = 'localhost';
    }
    $canonical_url = $proto . '://' . $_host
        . strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    unset($_host);
}
$canonical_url = htmlspecialchars((string)$canonical_url); // pre-encoded; output raw in template

// Sanitise v2.0.0 config values
if (!is_array($api_tokens)) {
    $api_tokens = [];
}
$api_rate_limit_rpm = max(0, (int)$api_rate_limit_rpm);
if (!is_array($api_rate_limit_tokens)) {
    $api_rate_limit_tokens = [];
}
if (!is_array($api_allowed_endpoints)) {
    $api_allowed_endpoints = [];
}
// Normalise $api_cors_origins. Pre-v3.6.3 configs may set this as a string;
// v3.6.3+ prefers an array. Accept both for back-compat.
if (is_string($api_cors_origins)) {
    $api_cors_origins = $api_cors_origins === '' ? ['*'] : [$api_cors_origins];
}
if (!is_array($api_cors_origins) || $api_cors_origins === []) {
    $api_cors_origins = ['*'];
}

// HSTS clamping (v3.6.3, #425). Max 2 years per the HSTS spec common cap.
$hsts_max_age = min(max((int)$hsts_max_age, 0), 63072000);
$hsts_preload = (bool)$hsts_preload;

// Trusted proxies (v3.6.3, #427-M3).
if (!is_array($api_trusted_proxies)) {
    $api_trusted_proxies = [];
}
$session_enabled  = (bool)$session_enabled;
$session_db_path  = is_string($session_db_path) ? $session_db_path : '';
$session_ttl_days = max(1, (int)$session_ttl_days);
$api_request_log         = (bool)$api_request_log;
$api_request_log_db_path = is_string($api_request_log_db_path) ? $api_request_log_db_path : '';

$admin_ui_enabled = (bool)$admin_ui_enabled;
$admin_user       = is_string($admin_user)      ? $admin_user      : '';
$admin_pass_hash  = is_string($admin_pass_hash) ? $admin_pass_hash : '';
$apikey_db_path   = is_string($apikey_db_path)  ? $apikey_db_path  : '';
$admin_audit_retention_days = max(0, (int)$admin_audit_retention_days);
// #325 — clamp purge strategy to one of the three known values; anything else
// (typo, malicious config, accidental int) falls back to the safe default.
if (
    !is_string($admin_audit_purge_strategy ?? null)
    || !in_array($admin_audit_purge_strategy, ['inline', 'sampled', 'cron'], true)
) {
    $admin_audit_purge_strategy = 'sampled';
}
// #325 — clamp the sample rate to [0.0, 1.0] and cast to float. Non-numeric
// input (string, null, array) collapses to 0.0 via (float) — fail closed:
// no purges rather than running every write.
if (!is_numeric($admin_audit_purge_sample_rate ?? null)) {
    $admin_audit_purge_sample_rate = 0.0;
} else {
    $admin_audit_purge_sample_rate = max(0.0, min(1.0, (float)$admin_audit_purge_sample_rate));
}
$admin_totp_secret = is_string($admin_totp_secret ?? null) ? trim((string)$admin_totp_secret) : '';
$tree_presets_dir  = is_string($tree_presets_dir ?? null) ? trim((string)$tree_presets_dir) : '';
