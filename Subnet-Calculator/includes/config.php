<?php

declare(strict_types=1);

// ─── Configuration defaults ───────────────────────────────────────────────────
// These are the built-in defaults. To override, copy config.php.example to
// config.php alongside this file — config.php is never overwritten by upgrades.

$app_version          = '2.9.1';
$locale               = 'en'; // BCP 47 locale tag for number formatting (e.g. 'de', 'fr')
$fixed_bg_color       = 'null';
$default_tab          = 'ipv4'; // 'ipv4', 'ipv6', or 'vlsm'
$split_max_subnets    = 16;
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

// REST API (v2.0.0)
$api_tokens              = [];   // [] = open; ['token1'] = auth required
$api_rate_limit_rpm      = 60;   // requests/minute per IP (0 = disabled)
$api_rate_limit_tokens   = [];   // per-token RPM overrides: ['token' => rpm]
$api_allowed_endpoints   = [];   // [] = all allowed; non-empty = allowlist
$api_cors_origins        = '*';  // CORS Access-Control-Allow-Origin

// Session persistence (v2.0.0)
$session_enabled    = false;  // Enable SQLite-backed VLSM session save/restore
$session_db_path    = '';     // Absolute path to SQLite file (auto if empty)
$session_ttl_days   = 30;     // Days before a saved session expires

// API request logging (v2.6.0)
$api_request_log         = false;  // Enable SQLite-backed API request logging
$api_request_log_db_path = '';     // Absolute path to log DB (auto if empty)

if (file_exists(__DIR__ . '/../config.php')) {
    require __DIR__ . '/../config.php';
}

// Sanitise config values
$split_max_subnets = max(1, min((int)$split_max_subnets, 256));
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
if (!is_string($api_cors_origins) || $api_cors_origins === '') {
    $api_cors_origins = '*';
}
$session_enabled  = (bool)$session_enabled;
$session_db_path  = is_string($session_db_path) ? $session_db_path : '';
$session_ttl_days = max(1, (int)$session_ttl_days);
$api_request_log         = (bool)$api_request_log;
$api_request_log_db_path = is_string($api_request_log_db_path) ? $api_request_log_db_path : '';
