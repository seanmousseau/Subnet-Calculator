<?php

// ─── Configuration defaults ───────────────────────────────────────────────────
// These are the built-in defaults. To override, copy config.php.example to
// config.php alongside this file — config.php is never overwritten by upgrades.

$fixed_bg_color       = 'null';
$default_tab          = 'ipv4';
$split_max_subnets    = 16;
$form_protection      = 'none';
$turnstile_site_key   = '';
$turnstile_secret_key = '';

if (file_exists(__DIR__ . '/config.php')) {
    require __DIR__ . '/config.php';
}

// Sanitise config values
$split_max_subnets = max(1, min((int)$split_max_subnets, 256));

// ─── Security headers ─────────────────────────────────────────────────────────

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
$turnstile_active = ($form_protection === 'turnstile' && $turnstile_site_key !== '' && $turnstile_secret_key !== '');
$csp_script = $turnstile_active
    ? "'self' 'unsafe-inline' https://challenges.cloudflare.com"
    : "'self' 'unsafe-inline'";
$csp_frame = $turnstile_active
    ? "'self' https://challenges.cloudflare.com"
    : "'self'";
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; style-src 'self' 'unsafe-inline'; script-src {$csp_script}; img-src 'self' data:; frame-src {$csp_frame}; frame-ancestors *");

// ─── IPv4 ─────────────────────────────────────────────────────────────────────

function cidr_to_mask(int $cidr): string {
    $mask = $cidr === 0 ? 0 : (~0 << (32 - $cidr));
    return long2ip($mask);
}

function mask_to_cidr(string $mask): int {
    return strlen(str_replace('0', '', decbin(ip2long($mask))));
}

function is_valid_ipv4(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

function is_valid_mask_octet(string $mask): bool {
    if (!is_valid_ipv4($mask)) return false;
    $long     = ip2long($mask);
    $inverted = ~$long & 0xFFFFFFFF;
    return ($inverted & ($inverted + 1)) === 0;
}

function cidr_to_wildcard(int $cidr): string {
    $mask_long = $cidr === 0 ? 0 : (~0 << (32 - $cidr));
    return long2ip(~$mask_long & 0xFFFFFFFF);
}

function calculate_subnet(string $ip, int $cidr): array {
    $ip_long      = ip2long($ip);
    $mask_long    = $cidr === 0 ? 0 : (~0 << (32 - $cidr));
    $network_long = $ip_long & $mask_long;
    $broadcast    = $network_long | (~$mask_long & 0xFFFFFFFF);
    $first        = $cidr >= 31 ? $network_long : $network_long + 1;
    $last         = $cidr >= 31 ? $broadcast    : $broadcast - 1;
    $usable       = $cidr >= 31 ? (1 << (32 - $cidr)) : max(0, (1 << (32 - $cidr)) - 2);

    return [
        'network_cidr'  => long2ip($network_long) . '/' . $cidr,
        'netmask_cidr'  => '/' . $cidr,
        'netmask_octet' => cidr_to_mask($cidr),
        'wildcard'      => cidr_to_wildcard($cidr),
        'first_usable'  => long2ip($first),
        'last_usable'   => long2ip($last),
        'broadcast'     => long2ip($broadcast),
        'usable_hosts'  => $usable,
    ];
}

// ─── IPv6 ─────────────────────────────────────────────────────────────────────

function is_valid_ipv6(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
}

function ipv6_to_gmp(string $ip): \GMP {
    $bin = inet_pton($ip);
    if ($bin === false || strlen($bin) !== 16) {
        throw new \InvalidArgumentException('Invalid IPv6 address passed to ipv6_to_gmp.');
    }
    return gmp_init(bin2hex($bin), 16);
}

function gmp_to_ipv6(\GMP $n): string {
    $hex = str_pad(gmp_strval($n, 16), 32, '0', STR_PAD_LEFT);
    if (strlen($hex) > 32) {
        throw new \OverflowException('GMP value exceeds 128 bits.');
    }
    $result = inet_ntop(hex2bin($hex));
    if ($result === false) {
        throw new \RuntimeException('inet_ntop failed on computed IPv6 address.');
    }
    return $result;
}

function calculate_subnet6(string $ip, int $prefix): array {
    $ip_int    = ipv6_to_gmp($ip);
    $host_bits = 128 - $prefix;
    $host_mask = $host_bits > 0 ? gmp_sub(gmp_pow(2, $host_bits), 1) : gmp_init(0);
    $net_mask  = gmp_xor(gmp_sub(gmp_pow(2, 128), 1), $host_mask);
    $network   = gmp_and($ip_int, $net_mask);
    $last      = gmp_or($network, $host_mask);
    $total     = $host_bits === 0 ? '1' : '2^' . $host_bits;

    return [
        'network_cidr' => gmp_to_ipv6($network) . '/' . $prefix,
        'prefix'       => '/' . $prefix,
        'first_ip'     => gmp_to_ipv6($network),
        'last_ip'      => gmp_to_ipv6($last),
        'total'        => $total,
    ];
}

// ─── Subnet splitter ──────────────────────────────────────────────────────────

function split_subnet(string $network_ip, int $cidr, int $new_prefix, int $max = 16): array {
    if ($new_prefix <= $cidr || $new_prefix > 32) {
        return ['subnets' => [], 'total' => 0, 'showing' => 0];
    }
    $count      = 1 << ($new_prefix - $cidr);
    $showing    = min($count, $max);
    $base       = ip2long($network_ip) & 0xFFFFFFFF;
    $block_size = 1 << (32 - $new_prefix);
    $subnets    = [];
    for ($i = 0; $i < $showing; $i++) {
        $subnets[] = long2ip(($base + $i * $block_size) & 0xFFFFFFFF) . '/' . $new_prefix;
    }
    return ['subnets' => $subnets, 'total' => $count, 'showing' => $showing];
}

function split_subnet6(string $network_ip, int $prefix, int $new_prefix, int $max = 16): array {
    if ($new_prefix <= $prefix || $new_prefix > 128) {
        return ['subnets' => [], 'total' => '0', 'showing' => 0];
    }
    $diff       = $new_prefix - $prefix;
    $total_str  = $diff >= 63 ? '2^' . $diff : (string)(1 << $diff);
    $showing    = $diff >= 63 ? $max : min(1 << $diff, $max);
    $base       = ipv6_to_gmp($network_ip);
    $block_size = gmp_pow(2, 128 - $new_prefix);
    $subnets    = [];
    for ($i = 0; $i < $showing; $i++) {
        $start     = gmp_add($base, gmp_mul($block_size, gmp_init($i)));
        $subnets[] = gmp_to_ipv6($start) . '/' . $new_prefix;
    }
    return ['subnets' => $subnets, 'total' => $total_str, 'showing' => $showing];
}

// ─── Address type detection ───────────────────────────────────────────────────

function get_ipv4_type(string $ip): string {
    $n = ip2long($ip) & 0xFFFFFFFF;
    if ($n === 0)                                                  return 'Unspecified';
    if ($n === 0xFFFFFFFF)                                         return 'Broadcast';
    if (($n & 0xFF000000) === 0x7F000000)                          return 'Loopback';
    if (($n & 0xFF000000) === 0x0A000000)                          return 'Private';
    if (($n & 0xFFF00000) === 0xAC100000)                          return 'Private';
    if (($n & 0xFFFF0000) === 0xC0A80000)                          return 'Private';
    if (($n & 0xFFFF0000) === 0xA9FE0000)                          return 'Link-local';
    if (($n & 0xF0000000) === 0xE0000000)                          return 'Multicast';
    if (($n & 0xFFFFFF00) === 0xC0000200)                          return 'Documentation';
    if (($n & 0xFFFFFF00) === 0xC6336400)                          return 'Documentation';
    if (($n & 0xFFFFFF00) === 0xCB007100)                          return 'Documentation';
    if (($n & 0xF0000000) === 0xF0000000)                          return 'Reserved';
    if (($n & 0xFF000000) === 0x00000000)                          return 'This Network';
    if (($n & 0xFFC00000) === 0x64400000)                          return 'CGNAT';
    return 'Public';
}

function get_ipv6_type(string $ip): string {
    $bin = inet_pton($ip);
    if ($bin === false) return 'Unknown';
    $b = array_values(unpack('C*', $bin));
    if ($bin === str_repeat("\x00", 15) . "\x01")              return 'Loopback';
    if ($bin === str_repeat("\x00", 16))                       return 'Unspecified';
    if (substr($bin,0,10)===str_repeat("\x00",10) && substr($bin,10,2)==="\xff\xff") return 'IPv4-mapped';
    if ($b[0] === 0xFF)                                        return 'Multicast';
    if ($b[0] === 0xFE && ($b[1] & 0xC0) === 0x80)            return 'Link-local';
    if (($b[0] & 0xFE) === 0xFC)                              return 'Unique Local';
    if ($b[0]===0x20 && $b[1]===0x01 && $b[2]===0x0D && $b[3]===0xB8) return 'Documentation';
    if ($b[0]===0x20 && $b[1]===0x01 && $b[2]===0x00 && $b[3]===0x00) return 'Teredo';
    if ($b[0] === 0x20 && $b[1] === 0x02)                     return '6to4';
    if (($b[0] & 0xE0) === 0x20)                              return 'Global Unicast';
    return 'Unknown';
}

function type_badge_class(string $type): string {
    $map = [
        'Private'       => 'private',
        'Public'        => 'public',
        'Loopback'      => 'loopback',
        'Link-local'    => 'link-local',
        'Multicast'     => 'multicast',
        'Documentation' => 'doc',
        'Global Unicast'=> 'public',
        'Unique Local'  => 'ula',
        'CGNAT'         => 'other',
    ];
    return $map[$type] ?? 'other';
}

// ─── Input resolvers (shared by GET and POST handlers) ────────────────────────

function resolve_ipv4_input(string $ip, string $mask): array {
    // CIDR auto-detection: "192.168.1.0/24" typed into IP field (#27)
    if (strpos($ip, '/') !== false && $mask === '') {
        [$ip, $mask] = array_pad(explode('/', $ip, 2), 2, '');
        $ip   = trim($ip);
        $mask = trim($mask);
    }
    $result = $error = null;
    if (!is_valid_ipv4($ip)) {
        $error = 'Invalid IPv4 address.';
    } else {
        $mask_clean = ltrim($mask, '/');
        if (ctype_digit($mask_clean)) {
            $cidr = (int)$mask_clean;
            if ($cidr < 0 || $cidr > 32) {
                $error = 'CIDR prefix must be between 0 and 32.';
            } else {
                $result = calculate_subnet($ip, $cidr);
            }
        } elseif (is_valid_mask_octet($mask_clean)) {
            $result = calculate_subnet($ip, mask_to_cidr($mask_clean));
        } else {
            $error = 'Invalid netmask. Use CIDR (e.g. /24) or dotted-decimal (e.g. 255.255.255.0).';
        }
    }
    return ['result' => $result, 'error' => $error, 'ip' => $ip, 'mask' => $mask];
}

function resolve_ipv6_input(string $ip, string $prefix): array {
    // CIDR auto-detection: "2001:db8::/32" typed into IPv6 field (#27)
    if (strpos($ip, '/') !== false && $prefix === '') {
        [$ip, $prefix] = array_pad(explode('/', $ip, 2), 2, '');
        $ip     = trim($ip);
        $prefix = trim($prefix);
    }
    $result6 = $error6 = null;
    if (!extension_loaded('gmp')) {
        $error6 = 'IPv6 calculation requires the PHP GMP extension.';
    } elseif (!is_valid_ipv6($ip)) {
        $error6 = 'Invalid IPv6 address.';
    } else {
        $pfx = ltrim($prefix, '/');
        if (!ctype_digit($pfx) || (int)$pfx < 0 || (int)$pfx > 128) {
            $error6 = 'Prefix must be between 0 and 128.';
        } else {
            try {
                $result6 = calculate_subnet6($ip, (int)$pfx);
            } catch (\Exception $e) {
                error_log('sc IPv6 error: ' . $e->getMessage());
                $error6 = 'An error occurred during calculation. Please check your input.';
            }
        }
    }
    return ['result6' => $result6, 'error6' => $error6, 'ip' => $ip, 'prefix' => $prefix];
}

// ─── Turnstile verification ───────────────────────────────────────────────────

function turnstile_verify(string $token, string $secret, string $remoteip): bool {
    if (!function_exists('curl_init')) {
        error_log('sc Turnstile: curl extension not available — verification skipped');
        return true; // fail open: better than silently blocking all users
    }
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => $remoteip]),
        CURLOPT_TIMEOUT        => 5,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $json = $raw ? json_decode($raw, true) : null;
    return (bool)($json['success'] ?? false);
}

// ─── Request handling ─────────────────────────────────────────────────────────

$get_tab    = $_GET['tab'] ?? $default_tab;
$active_tab = $get_tab === 'ipv6' ? 'ipv6' : 'ipv4';

$result = $error = null;
$input_ip = $input_mask = '';

$result6 = $error6 = null;
$input_ipv6 = $input_prefix = '';

$split_result  = $split_error  = null;
$split_result6 = $split_error6 = null;
$input_split_prefix  = '';
$input_split_prefix6 = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_tab   = $_POST['tab'] ?? $default_tab;
    $active_tab = $post_tab === 'ipv6' ? 'ipv6' : 'ipv4';

    // ─── Form protection ─────────────────────────────────────────────────────────
    $form_blocked = false;
    $is_splitter  = isset($_POST['split_prefix']) || isset($_POST['split_prefix6']);

    if (!$is_splitter && $form_protection === 'honeypot') {
        // Honeypot: silently ignore submissions where the hidden field is non-empty
        if (trim((string)($_POST['url'] ?? '')) !== '') {
            $form_blocked = true;
        }
    } elseif (!$is_splitter && $turnstile_active) {
        $token = trim((string)($_POST['cf-turnstile-response'] ?? ''));
        if ($token === '') {
            $form_blocked = true;
            if ($active_tab === 'ipv6') { $error6 = 'Please complete the CAPTCHA.'; }
            else { $error = 'Please complete the CAPTCHA.'; }
        } else {
            if (!turnstile_verify($token, $turnstile_secret_key, $_SERVER['REMOTE_ADDR'] ?? '')) {
                $form_blocked = true;
                if ($active_tab === 'ipv6') { $error6 = 'CAPTCHA verification failed. Please try again.'; }
                else { $error = 'CAPTCHA verification failed. Please try again.'; }
            }
        }
    }

    if (!$form_blocked && $active_tab === 'ipv4') {
        $r = resolve_ipv4_input(
            trim((string)($_POST['ip']   ?? '')),
            trim((string)($_POST['mask'] ?? ''))
        );
        $result     = $r['result'];
        $error      = $r['error'];
        $input_ip   = $r['ip'];
        $input_mask = $r['mask'];

        // IPv4 subnet splitter (POST only)
        if ($result && isset($_POST['split_prefix'])) {
            $input_split_prefix = trim((string)($_POST['split_prefix'] ?? ''));
            $sp = ltrim($input_split_prefix, '/');
            if (!ctype_digit($sp) || (int)$sp < 1 || (int)$sp > 32) {
                $split_error = 'New prefix must be between 1 and 32.';
            } else {
                $new_pfx      = (int)$sp;
                $current_cidr = (int)ltrim($result['netmask_cidr'], '/');
                $network_ip   = explode('/', $result['network_cidr'])[0];
                if ($new_pfx <= $current_cidr) {
                    $split_error = 'New prefix must be larger than /' . $current_cidr . '.';
                } else {
                    $split_result = split_subnet($network_ip, $current_cidr, $new_pfx, $split_max_subnets);
                }
            }
        }
    } elseif (!$form_blocked) {
        $r = resolve_ipv6_input(
            trim((string)($_POST['ipv6']   ?? '')),
            trim((string)($_POST['prefix'] ?? ''))
        );
        $result6      = $r['result6'];
        $error6       = $r['error6'];
        $input_ipv6   = $r['ip'];
        $input_prefix = $r['prefix'];

        // IPv6 subnet splitter (POST only)
        if ($result6 && isset($_POST['split_prefix6'])) {
            $input_split_prefix6 = trim((string)($_POST['split_prefix6'] ?? ''));
            $sp6 = ltrim($input_split_prefix6, '/');
            if (!ctype_digit($sp6) || (int)$sp6 < 0 || (int)$sp6 > 128) {
                $split_error6 = 'New prefix must be between 0 and 128.';
            } else {
                $new_pfx6     = (int)$sp6;
                $current_pfx6 = (int)ltrim($result6['prefix'], '/');
                $network_ipv6 = explode('/', $result6['network_cidr'])[0];
                if ($new_pfx6 <= $current_pfx6) {
                    $split_error6 = 'New prefix must be larger than /' . $current_pfx6 . '.';
                } else {
                    $split_result6 = split_subnet6($network_ipv6, $current_pfx6, $new_pfx6, $split_max_subnets);
                }
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($active_tab === 'ipv4') {
        $get_ip   = trim((string)($_GET['ip']   ?? ''));
        $get_mask = trim((string)($_GET['mask'] ?? ''));
        if ($get_ip !== '' && $get_mask !== '') {
            $r = resolve_ipv4_input($get_ip, $get_mask);
            $result     = $r['result'];
            $error      = $r['error'];
            $input_ip   = $r['ip'];
            $input_mask = $r['mask'];
        }
    } else {
        $get_ipv6   = trim((string)($_GET['ipv6']   ?? ''));
        $get_prefix = trim((string)($_GET['prefix'] ?? ''));
        if ($get_ipv6 !== '' && $get_prefix !== '') {
            $r = resolve_ipv6_input($get_ipv6, $get_prefix);
            $result6      = $r['result6'];
            $error6       = $r['error6'];
            $input_ipv6   = $r['ip'];
            $input_prefix = $r['prefix'];
        }
    }
}

// Build fixed background override style (avoids inline PHP inside CSS block — #32)
$bg_override_style = '';
if ($fixed_bg_color !== 'null' && $fixed_bg_color !== '' && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', (string)$fixed_bg_color)) {
    $bg_override_style = ':root,html[data-theme="light"]{--color-bg:' . htmlspecialchars((string)$fixed_bg_color) . '}';
}

// Build shareable URL
$share_url = '';
if ($result) {
    $share_url = '?' . http_build_query(['tab' => 'ipv4', 'ip' => $input_ip, 'mask' => ltrim($result['netmask_cidr'], '/')]);
} elseif ($result6) {
    $share_url = '?' . http_build_query(['tab' => 'ipv6', 'ipv6' => $input_ipv6, 'prefix' => ltrim($result6['prefix'], '/')]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subnet Calculator</title>
    <link rel="icon" type="image/svg+xml" href="logo.svg">
    <script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
    <style>
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

        .logo { width: 32px; height: 32px; flex-shrink: 0; }

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
    <?php if ($bg_override_style) echo '<style>' . $bg_override_style . '</style>'; ?>
    <?php if ($turnstile_active): ?>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>
</head>
<body>
<div class="card">
    <div class="title-row">
        <img src="logo.svg" alt="Subnet Calculator logo" class="logo">
        <h1>Subnet Calculator</h1>
        <span class="version">v0.9</span>
        <button id="theme-toggle" class="theme-toggle" title="Toggle light/dark mode">
            <svg class="icon-sun" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
            <svg class="icon-moon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="display:none"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        </button>
    </div>

    <div class="tabs">
        <button class="tab-btn<?= $active_tab === 'ipv4' ? ' active' : '' ?>" data-tab="ipv4">IPv4</button>
        <button class="tab-btn<?= $active_tab === 'ipv6' ? ' active' : '' ?>" data-tab="ipv6">IPv6</button>
    </div>

    <!-- IPv4 Panel -->
    <div id="panel-ipv4" class="panel<?= $active_tab === 'ipv4' ? ' active' : '' ?>">
        <form method="post" novalidate>
            <input type="hidden" name="tab" value="ipv4">
            <div class="form-row">
                <div class="form-group">
                    <label for="ip">IP Address</label>
                    <input type="text" id="ip" name="ip"
                           placeholder="192.168.1.0 or 192.168.1.0/24"
                           value="<?= htmlspecialchars($input_ip) ?>"
                           autocomplete="off" spellcheck="false">
                </div>
                <div class="form-group">
                    <label for="mask">Netmask</label>
                    <input type="text" id="mask" name="mask"
                           placeholder="/24 or 255.255.255.0"
                           value="<?= htmlspecialchars($input_mask) ?>"
                           autocomplete="off" spellcheck="false">
                </div>
            </div>
            <div class="btn-row">
                <button type="submit">Calculate</button>
                <a href="?" class="btn reset">Reset</a>
            </div>
            <?php if ($form_protection !== 'none'): ?>
                <input type="text" name="url" style="display:none" tabindex="-1" autocomplete="off" value="">
            <?php endif; ?>
            <?php if ($turnstile_active): ?>
                <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($turnstile_site_key) ?>" style="margin-top:0.75rem"></div>
            <?php endif; ?>
        </form>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($result): ?>
            <div class="results">
                <div class="results-header">Results</div>
                <div class="result-row" title="Click to copy">
                    <span class="result-label">Subnet (CIDR)</span>
                    <span class="result-value"><?= htmlspecialchars($result['network_cidr']) ?></span>
                </div>
                <div class="result-row" title="Click to copy">
                    <span class="result-label">Netmask (CIDR)</span>
                    <span class="result-value"><?= htmlspecialchars($result['netmask_cidr']) ?></span>
                </div>
                <div class="result-row" title="Click to copy">
                    <span class="result-label">Netmask (Octet)</span>
                    <span class="result-value"><?= htmlspecialchars($result['netmask_octet']) ?></span>
                </div>
                <div class="result-row" title="Click to copy">
                    <span class="result-label">Wildcard Mask</span>
                    <span class="result-value"><?= htmlspecialchars($result['wildcard']) ?></span>
                </div>
                <div class="result-row" title="Click to copy">
                    <span class="result-label">First Usable IP</span>
                    <span class="result-value"><?= htmlspecialchars($result['first_usable']) ?></span>
                </div>
                <div class="result-row" title="Click to copy">
                    <span class="result-label">Last Usable IP</span>
                    <span class="result-value"><?= htmlspecialchars($result['last_usable']) ?></span>
                </div>
                <div class="result-row" title="Click to copy">
                    <span class="result-label">Broadcast IP</span>
                    <span class="result-value"><?= htmlspecialchars($result['broadcast']) ?></span>
                </div>
                <div class="result-row hosts-row" title="Click to copy">
                    <span class="result-label">Usable IPs</span>
                    <span class="result-value"><?= number_format($result['usable_hosts']) ?></span>
                </div>
                <?php $ip4type = get_ipv4_type($input_ip); ?>
                <div class="result-row">
                    <span class="result-label">Address Type</span>
                    <span class="result-value"><span class="badge badge-<?= type_badge_class($ip4type) ?>"><?= htmlspecialchars($ip4type) ?></span></span>
                </div>
            </div>
            <div class="share-bar">
                <span class="share-label">Share</span>
                <code class="share-url"><?= htmlspecialchars($share_url) ?></code>
                <button type="button" class="share-copy" data-copy="<?= htmlspecialchars($share_url) ?>">Copy</button>
            </div>
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
                               autocomplete="off" spellcheck="false">
                        <button type="submit" class="splitter-btn">Split</button>
                    </div>
                </form>
                <?php if ($split_error): ?>
                    <div class="error" style="margin:0.75rem 1rem"><?= htmlspecialchars($split_error) ?></div>
                <?php elseif ($split_result && $split_result['showing'] > 0): ?>
                    <div class="split-list">
                        <?php foreach ($split_result['subnets'] as $s): ?>
                            <div class="split-item" data-copy="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></div>
                        <?php endforeach; ?>
                        <?php if ($split_result['total'] > $split_max_subnets): ?>
                            <div class="split-more">+ <?= number_format($split_result['total'] - $split_max_subnets) ?> more</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- IPv6 Panel -->
    <div id="panel-ipv6" class="panel<?= $active_tab === 'ipv6' ? ' active' : '' ?>">
        <form method="post" novalidate>
            <input type="hidden" name="tab" value="ipv6">
            <div class="form-row">
                <div class="form-group">
                    <label for="ipv6">IPv6 Address</label>
                    <input type="text" id="ipv6" name="ipv6"
                           placeholder="2001:db8::1 or 2001:db8::/32"
                           value="<?= htmlspecialchars($input_ipv6) ?>"
                           autocomplete="off" spellcheck="false">
                </div>
                <div class="form-group" style="max-width:120px">
                    <label for="prefix">Prefix</label>
                    <input type="text" id="prefix" name="prefix"
                           placeholder="/64"
                           value="<?= htmlspecialchars($input_prefix) ?>"
                           autocomplete="off" spellcheck="false">
                </div>
            </div>
            <div class="btn-row">
                <button type="submit">Calculate</button>
                <a href="?tab=ipv6" class="btn reset">Reset</a>
            </div>
            <?php if ($form_protection !== 'none'): ?>
                <input type="text" name="url" style="display:none" tabindex="-1" autocomplete="off" value="">
            <?php endif; ?>
            <?php if ($turnstile_active): ?>
                <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($turnstile_site_key) ?>" style="margin-top:0.75rem"></div>
            <?php endif; ?>
        </form>

        <?php if ($error6): ?>
            <div class="error"><?= htmlspecialchars($error6) ?></div>
        <?php endif; ?>

        <?php if ($result6): ?>
            <div class="results">
                <div class="results-header">Results</div>
                <div class="result-row" title="Click to copy">
                    <span class="result-label">Network (CIDR)</span>
                    <span class="result-value"><?= htmlspecialchars($result6['network_cidr']) ?></span>
                </div>
                <div class="result-row" title="Click to copy">
                    <span class="result-label">Prefix Length</span>
                    <span class="result-value"><?= htmlspecialchars($result6['prefix']) ?></span>
                </div>
                <div class="result-row" title="Click to copy">
                    <span class="result-label">First IP</span>
                    <span class="result-value"><?= htmlspecialchars($result6['first_ip']) ?></span>
                </div>
                <div class="result-row" title="Click to copy">
                    <span class="result-label">Last IP</span>
                    <span class="result-value"><?= htmlspecialchars($result6['last_ip']) ?></span>
                </div>
                <div class="result-row hosts-row" title="Click to copy">
                    <span class="result-label">Total Addresses</span>
                    <span class="result-value"><?= htmlspecialchars($result6['total']) ?></span>
                </div>
                <?php $ip6type = get_ipv6_type($input_ipv6); ?>
                <div class="result-row">
                    <span class="result-label">Address Type</span>
                    <span class="result-value"><span class="badge badge-<?= type_badge_class($ip6type) ?>"><?= htmlspecialchars($ip6type) ?></span></span>
                </div>
            </div>
            <div class="share-bar">
                <span class="share-label">Share</span>
                <code class="share-url"><?= htmlspecialchars($share_url) ?></code>
                <button type="button" class="share-copy" data-copy="<?= htmlspecialchars($share_url) ?>">Copy</button>
            </div>
        <?php endif; ?>
        <?php if ($result6): ?>
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
                               autocomplete="off" spellcheck="false">
                        <button type="submit" class="splitter-btn">Split</button>
                    </div>
                </form>
                <?php if ($split_error6): ?>
                    <div class="error" style="margin:0.75rem 1rem"><?= htmlspecialchars($split_error6) ?></div>
                <?php elseif ($split_result6 && $split_result6['showing'] > 0): ?>
                    <div class="split-list">
                        <?php foreach ($split_result6['subnets'] as $s): ?>
                            <div class="split-item" data-copy="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></div>
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

<div id="toast" class="toast">Copied!</div>

<script>
// ── Theme toggle ─────────────────────────────────────────────────────────────
function syncThemeIcon() {
    const light = document.documentElement.getAttribute('data-theme') === 'light';
    document.querySelector('#theme-toggle .icon-sun').style.display  = light ? 'none' : '';
    document.querySelector('#theme-toggle .icon-moon').style.display = light ? '' : 'none';
}

document.getElementById('theme-toggle').addEventListener('click', () => {
    const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
    syncThemeIcon();
});

syncThemeIcon();

// ── Tab switcher ─────────────────────────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn, .panel').forEach(el => el.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('panel-' + btn.dataset.tab).classList.add('active');
        autoFocusActive();
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
    el.textContent = _base + el.textContent.trim();
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
    (function () {
        function postHeight() {
            var card = document.querySelector('.card');
            var h = Math.ceil(Math.max(
                card ? card.getBoundingClientRect().height : 0,
                document.body.scrollHeight,
                document.documentElement.scrollHeight
            ));
            window.parent.postMessage({ type: 'sc-resize', height: h }, '*');
        }
        postHeight();
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
