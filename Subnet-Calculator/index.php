<?php
declare(strict_types=1);

require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/functions-ipv4.php';
require __DIR__ . '/includes/functions-ipv6.php';
require __DIR__ . '/includes/functions-split.php';
require __DIR__ . '/includes/functions-util.php';

// ─── Security headers ─────────────────────────────────────────────────────────

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
if ($frame_ancestors === "'none'") {
    header('X-Frame-Options: DENY');
} elseif ($frame_ancestors === "'self'") {
    header('X-Frame-Options: SAMEORIGIN');
}
$csp_nonce    = base64_encode(random_bytes(16));
$turnstile_active = ($form_protection === 'turnstile' && $turnstile_site_key !== '' && $turnstile_secret_key !== '');
// 'self' allows assets/app.css and assets/app.js; nonce covers remaining inline blocks
$csp_script = $turnstile_active
    ? "'self' 'nonce-{$csp_nonce}' https://challenges.cloudflare.com"
    : "'self' 'nonce-{$csp_nonce}'";
$csp_style  = "'self' 'nonce-{$csp_nonce}'";
$csp_frame = $turnstile_active
    ? "'self' https://challenges.cloudflare.com"
    : "'self'";
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; style-src {$csp_style}; script-src {$csp_script}; img-src 'self' data:; frame-src {$csp_frame}; frame-ancestors {$frame_ancestors}");
$turnstile_curl_missing = $turnstile_active && !function_exists('curl_init');

require __DIR__ . '/includes/request.php';

require __DIR__ . '/templates/layout.php';
