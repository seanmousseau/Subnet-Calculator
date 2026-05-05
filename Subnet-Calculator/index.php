<?php
declare(strict_types=1);

require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/functions-ipv4.php';
require __DIR__ . '/includes/functions-ipv6.php';
require __DIR__ . '/includes/functions-split.php';
require __DIR__ . '/includes/functions-util.php';
require __DIR__ . '/includes/functions-vlsm.php';
require __DIR__ . '/includes/functions-vlsm6.php';
require __DIR__ . '/includes/functions-supernet.php';
require __DIR__ . '/includes/functions-ula.php';
require __DIR__ . '/includes/functions-session.php';
require __DIR__ . '/includes/functions-range.php';
require __DIR__ . '/includes/functions-tree.php';
require __DIR__ . '/includes/functions-tree-diff.php';
require __DIR__ . '/includes/functions-lookup.php';
require __DIR__ . '/includes/functions-diff.php';

// ─── Security headers ─────────────────────────────────────────────────────────

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
if ($frame_ancestors === "'none'") {
    header('X-Frame-Options: DENY');
} elseif ($frame_ancestors === "'self'") {
    header('X-Frame-Options: SAMEORIGIN');
}
$csp_nonce    = base64_encode(random_bytes(16));
$turnstile_active = ($form_protection === 'turnstile'              && $turnstile_site_key !== ''                 && $turnstile_secret_key !== '');
$hcaptcha_active  = ($form_protection === 'hcaptcha'               && $hcaptcha_site_key !== ''                  && $hcaptcha_secret_key !== '');
$recaptcha_active = ($form_protection === 'recaptcha_enterprise'   && $recaptcha_enterprise_site_key !== ''      && $recaptcha_enterprise_api_key !== '' && $recaptcha_enterprise_project_id !== '');
// 'self' allows assets/app.css and assets/app.js; nonce covers remaining inline blocks
$csp_extra_script = '';
$csp_extra_frame  = '';
if ($turnstile_active) {
    $csp_extra_script = ' https://challenges.cloudflare.com';
    $csp_extra_frame  = ' https://challenges.cloudflare.com';
} elseif ($hcaptcha_active) {
    $csp_extra_script = ' https://js.hcaptcha.com https://newassets.hcaptcha.com https://assets.hcaptcha.com';
    $csp_extra_frame  = ' https://newassets.hcaptcha.com https://assets.hcaptcha.com';
} elseif ($recaptcha_active) {
    $csp_extra_script = ' https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/';
    $csp_extra_frame  = ' https://www.google.com/recaptcha/ https://recaptchaenterprise.googleapis.com';
}
// Sanitise operator-supplied CSP extension values: strip ; \r \n that could
// inject or terminate directives, then collapse whitespace. Defence-in-depth
// against a misconfigured config.php — operators are still expected to supply
// origin tokens only (the comment in config.php.example spells this out).
$_csp_sanitise = static function (mixed $v): string {
    if (!is_string($v)) { return ''; }
    return trim(preg_replace('/\s+/', ' ', preg_replace('/[;\r\n]/', ' ', $v)));
};
$csp_connect_extra = $_csp_sanitise($csp_connect_extra ?? '');
$csp_script_extra  = $_csp_sanitise($csp_script_extra  ?? '');
$csp_img_extra     = $_csp_sanitise($csp_img_extra     ?? '');
unset($_csp_sanitise);

$csp_script  = "'self' 'nonce-{$csp_nonce}'" . $csp_extra_script
    . ($csp_script_extra !== '' ? ' ' . $csp_script_extra : '');
$csp_style   = "'self' 'nonce-{$csp_nonce}'";
$csp_frame   = "'self'" . $csp_extra_frame;
$csp_img     = "'self' data:" . ($csp_img_extra !== '' ? ' ' . $csp_img_extra : '');
$csp_connect = "'self'" . ($csp_connect_extra !== '' ? ' ' . $csp_connect_extra : '');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; style-src {$csp_style}; script-src {$csp_script}; img-src {$csp_img}; connect-src {$csp_connect}; frame-src {$csp_frame}; frame-ancestors {$frame_ancestors}");
$turnstile_curl_missing = $turnstile_active  && !function_exists('curl_init');
$hcaptcha_curl_missing  = $hcaptcha_active   && !function_exists('curl_init');
$recaptcha_curl_missing = $recaptcha_active  && !function_exists('curl_init');

require __DIR__ . '/includes/request.php';

require __DIR__ . '/templates/layout.php';
