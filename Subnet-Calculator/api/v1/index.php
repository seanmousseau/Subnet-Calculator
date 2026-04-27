<?php

/**
 * API v1 — router
 *
 * Bootstrap order:
 *   1. config.php  — operator settings ($api_tokens, $api_rate_limit_rpm, …)
 *   2. helpers.php — json_ok/json_err, api_cors, api_authenticate, api_rate_limit
 *   3. function files — pure calculation functions
 *   4. Route to the matching handler
 */

$base = dirname(__DIR__, 2) . '/includes/';

require $base . 'config.php';
require __DIR__ . '/helpers.php';
require $base . 'functions-ipv4.php';
require $base . 'functions-ipv6.php';
require $base . 'functions-split.php';
require $base . 'functions-util.php';
require $base . 'functions-vlsm.php';
require $base . 'functions-vlsm6.php';
require $base . 'functions-supernet.php';
require $base . 'functions-ula.php';
require $base . 'functions-session.php';
require_once $base . 'functions-resolve.php';
require $base . 'functions-range.php';
require $base . 'functions-tree.php';
require $base . 'functions-lookup.php';
require $base . 'functions-diff.php';

header('Content-Type: application/json; charset=utf-8');

api_cors();
api_authenticate();
api_rate_limit(api_client_key());

$raw_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$method     = is_string($raw_method) ? $raw_method : 'GET';
$raw_uri    = $_SERVER['REQUEST_URI'] ?? '/';
$parsed_uri = is_string($raw_uri) ? (parse_url($raw_uri, PHP_URL_PATH) ?? '/') : '/';
$uri        = is_string($parsed_uri) ? $parsed_uri : '/';

if ($api_request_log) {
    api_log_request(api_client_key(), $uri, $method);
}

// Strip leading script path so the router sees only the part after /api/v1
// Works whether the app lives at docroot or a sub-path.
$raw_script = $_SERVER['SCRIPT_NAME'] ?? '';
$script_dir = is_string($raw_script) ? rtrim(dirname($raw_script), '/') : '';
if ($script_dir !== '' && str_starts_with($uri, $script_dir)) {
    $uri = substr($uri, strlen($script_dir));
}
$uri = '/' . ltrim($uri, '/');

// ── Meta endpoint ─────────────────────────────────────────────────────────────
if ($uri === '/' && $method === 'GET') {
    json_ok([
        'version'   => '1',
        'endpoints' => [
            'POST /api/v1/ipv4',
            'POST /api/v1/ipv6',
            'POST /api/v1/vlsm',
            'POST /api/v1/vlsm6',
            'POST /api/v1/overlap',
            'POST /api/v1/split/ipv4',
            'POST /api/v1/split/ipv6',
            'POST /api/v1/supernet',
            'POST /api/v1/ula',
            'POST /api/v1/rdns',
            'POST /api/v1/bulk',
            'POST /api/v1/sessions',
            'GET  /api/v1/sessions/{id}',
            'POST /api/v1/range/ipv4',
            'POST /api/v1/tree',
            'POST /api/v1/wildcard',
            'POST /api/v1/lookup',
            'POST /api/v1/diff',
            'GET  /api/v1/changelog',
        ],
    ]);
}

// ── Endpoint allowlist ────────────────────────────────────────────────────────
// When $api_allowed_endpoints is non-empty it acts as an allowlist: requests for
// any endpoint not in the list are rejected with 404 before dispatch.
// The meta endpoint (GET /) is always available. Sessions/{id} maps to 'sessions'.
if (!empty($api_allowed_endpoints) && $uri !== '/') {
    $ep = ltrim($uri, '/');
    if (str_starts_with($ep, 'sessions/')) {
        $ep = 'sessions';
    }
    if (!in_array($ep, $api_allowed_endpoints, true)) {
        json_err('Not found.', 404);
    }
}

$route_key = $method . ' ' . $uri;

// Sessions GET with ID: /sessions/{id}
if ($method === 'GET' && preg_match('#^/sessions/([0-9a-f]{8})$#', $uri, $m)) {
    $_GET['session_id'] = $m[1];
    require __DIR__ . '/handlers/sessions.php';
}

// ── Dispatch ─────────────────────────────────────────────────────────────────
// Each arm uses a literal __DIR__-relative path so no user-controlled data
// ever appears in a require statement (guards against path-traversal scanners).
switch ($route_key) {
    case 'POST /ipv4':
        require __DIR__ . '/handlers/ipv4.php';
        break;
    case 'POST /ipv6':
        require __DIR__ . '/handlers/ipv6.php';
        break;
    case 'POST /vlsm':
        require __DIR__ . '/handlers/vlsm.php';
        break;
    case 'POST /vlsm6':
        require __DIR__ . '/handlers/vlsm6.php';
        break;
    case 'POST /overlap':
        require __DIR__ . '/handlers/overlap.php';
        break;
    case 'POST /split/ipv4':
    case 'POST /split/ipv6':
        require __DIR__ . '/handlers/split.php';
        break;
    case 'POST /supernet':
        require __DIR__ . '/handlers/supernet.php';
        break;
    case 'POST /ula':
        require __DIR__ . '/handlers/ula.php';
        break;
    case 'POST /rdns':
        require __DIR__ . '/handlers/rdns.php';
        break;
    case 'POST /bulk':
        require __DIR__ . '/handlers/bulk.php';
        break;
    case 'POST /sessions':
        require __DIR__ . '/handlers/sessions.php';
        break;
    case 'POST /range/ipv4':
        require __DIR__ . '/handlers/range.php';
        break;
    case 'POST /tree':
        require __DIR__ . '/handlers/tree.php';
        break;
    case 'POST /wildcard':
        require __DIR__ . '/handlers/wildcard.php';
        break;
    case 'POST /lookup':
        require __DIR__ . '/handlers/lookup.php';
        break;
    case 'POST /diff':
        require __DIR__ . '/handlers/diff.php';
        break;
    case 'GET /changelog':
        require __DIR__ . '/handlers/changelog.php';
        break;
}

json_err('Not found.', 404);
