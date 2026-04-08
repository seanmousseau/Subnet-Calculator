<?php
declare(strict_types=1);

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
        if (!ctype_digit($pfx) || (int)$pfx > 128) {
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
        return true;
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
$active_tab = in_array($get_tab, ['ipv4', 'ipv6', 'vlsm'], true) ? $get_tab : 'ipv4';

$result = $error = null;
$input_ip = $input_mask = '';

$result6 = $error6 = null;
$input_ipv6 = $input_prefix = '';

$split_result  = $split_error  = null;
$split_result6 = $split_error6 = null;
$input_split_prefix  = '';
$input_split_prefix6 = '';

$overlap_result = $overlap_error = null;
$overlap_cidr_a = $overlap_cidr_b = '';

$vlsm_result = $vlsm_error = null;
$vlsm_network = $vlsm_cidr_input = '';
/** @var array<array{name: string, hosts: int}> $vlsm_requirements */
$vlsm_requirements = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_tab   = $_POST['tab'] ?? $default_tab;
    $active_tab = in_array($post_tab, ['ipv4', 'ipv6', 'vlsm'], true) ? $post_tab : 'ipv4';

    $form_blocked = false;
    $is_splitter  = isset($_POST['split_prefix']) || isset($_POST['split_prefix6']);
    $is_overlap   = isset($_POST['overlap_cidr_a']) || isset($_POST['overlap_cidr_b']);
    $is_vlsm      = isset($_POST['vlsm_network']);

    $is_tool = $is_splitter || $is_overlap || $is_vlsm;

    if (!$is_tool && $form_protection === 'honeypot') {
        if (trim((string)($_POST['url'] ?? '')) !== '') {
            $form_blocked = true;
        }
    } elseif (!$is_tool && $turnstile_active) {
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

        if ($result && isset($_POST['split_prefix'])) {
            $input_split_prefix = trim((string)$_POST['split_prefix']);
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

        if ($result6 && isset($_POST['split_prefix6'])) {
            $input_split_prefix6 = trim((string)$_POST['split_prefix6']);
            $sp6 = ltrim($input_split_prefix6, '/');
            if (!ctype_digit($sp6) || (int)$sp6 < 1 || (int)$sp6 > 128) {
                $split_error6 = 'New prefix must be between 1 and 128.';
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

    if ($is_overlap && !$form_blocked) {
        $overlap_cidr_a = trim((string)($_POST['overlap_cidr_a'] ?? ''));
        $overlap_cidr_b = trim((string)($_POST['overlap_cidr_b'] ?? ''));
        $ra = resolve_ipv4_input($overlap_cidr_a, '');
        $rb = resolve_ipv4_input($overlap_cidr_b, '');
        if (!$ra['result']) {
            $overlap_error = 'First subnet: ' . ($ra['error'] ?? 'Invalid CIDR.');
        } elseif (!$rb['result']) {
            $overlap_error = 'Second subnet: ' . ($rb['error'] ?? 'Invalid CIDR.');
        } else {
            $overlap_result = cidrs_overlap($ra['result']['network_cidr'], $rb['result']['network_cidr']);
        }
    }

    if ($is_vlsm && !$form_blocked) {
        $vlsm_network   = trim((string)($_POST['vlsm_network'] ?? ''));
        $vlsm_cidr_input = trim((string)($_POST['vlsm_cidr']   ?? ''));
        $rv = resolve_ipv4_input($vlsm_network, $vlsm_cidr_input);
        if (!$rv['result']) {
            $vlsm_error = 'Parent network: ' . ($rv['error'] ?? 'Invalid input.');
        } else {
            $names  = $_POST['vlsm_name']  ?? [];
            $hosts  = $_POST['vlsm_hosts'] ?? [];
            if (!is_array($names) || !is_array($hosts) || count($names) === 0) {
                $vlsm_error = 'Add at least one requirement.';
            } else {
                $reqs = [];
                foreach ($names as $i => $name) {
                    $name  = trim((string)$name);
                    $hval  = trim((string)($hosts[$i] ?? ''));
                    if ($name === '' || !ctype_digit($hval) || (int)$hval < 1) continue;
                    $reqs[] = ['name' => $name, 'hosts' => (int)$hval];
                }
                if ($reqs === []) {
                    $vlsm_error = 'Add at least one valid requirement.';
                } else {
                    $vlsm_cidr_int   = (int)ltrim($rv['result']['netmask_cidr'], '/');
                    $vlsm_network_ip = explode('/', $rv['result']['network_cidr'])[0];
                    $vlsm_requirements = $reqs;
                    $vr = vlsm_allocate($vlsm_network_ip, $vlsm_cidr_int, $reqs);
                    if (isset($vr['error'])) {
                        $vlsm_error = $vr['error'];
                    } else {
                        $vlsm_result = $vr['allocations'] ?? [];
                    }
                }
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($active_tab === 'ipv4') {
        $get_ip   = trim((string)($_GET['ip']   ?? ''));
        $get_mask = trim((string)($_GET['mask'] ?? ''));
        if ($get_ip !== '') {
            $r = resolve_ipv4_input($get_ip, $get_mask);
            $result     = $r['result'];
            $error      = $r['error'];
            $input_ip   = $r['ip'];
            $input_mask = $r['mask'];
        }
    } else {
        $get_ipv6   = trim((string)($_GET['ipv6']   ?? ''));
        $get_prefix = trim((string)($_GET['prefix'] ?? ''));
        if ($get_ipv6 !== '') {
            $r = resolve_ipv6_input($get_ipv6, $get_prefix);
            $result6      = $r['result6'];
            $error6       = $r['error6'];
            $input_ipv6   = $r['ip'];
            $input_prefix = $r['prefix'];
        }
    }

    // GET-based splitter (for shareable URLs that include split_prefix)
    if ($result && isset($_GET['split_prefix'])) {
        $input_split_prefix = trim((string)$_GET['split_prefix']);
        $sp = ltrim($input_split_prefix, '/');
        if (ctype_digit($sp) && (int)$sp >= 1 && (int)$sp <= 32) {
            $new_pfx      = (int)$sp;
            $current_cidr = (int)ltrim($result['netmask_cidr'], '/');
            $network_ip   = explode('/', $result['network_cidr'])[0];
            if ($new_pfx > $current_cidr) {
                $split_result = split_subnet($network_ip, $current_cidr, $new_pfx, $split_max_subnets);
            }
        }
    } elseif ($result6 && isset($_GET['split_prefix6'])) {
        $input_split_prefix6 = trim((string)$_GET['split_prefix6']);
        $sp6 = ltrim($input_split_prefix6, '/');
        if (ctype_digit($sp6) && (int)$sp6 >= 1 && (int)$sp6 <= 128) {
            $new_pfx6     = (int)$sp6;
            $current_pfx6 = (int)ltrim($result6['prefix'], '/');
            $network_ipv6 = explode('/', $result6['network_cidr'])[0];
            if ($new_pfx6 > $current_pfx6) {
                $split_result6 = split_subnet6($network_ipv6, $current_pfx6, $new_pfx6, $split_max_subnets);
            }
        }
    }
}

// Build fixed background override style
$bg_override_style = '';
if ($fixed_bg_color !== 'null' && $fixed_bg_color !== '' && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', (string)$fixed_bg_color)) {
    $bg_override_style = ':root,html[data-theme="light"]{--color-bg:' . htmlspecialchars((string)$fixed_bg_color) . '}';
}

// Build shareable URL
$share_url = '';
if ($result) {
    $sp = $split_result ? ['split_prefix' => ltrim($input_split_prefix, '/')] : [];
    $share_url = '?' . http_build_query(['tab' => 'ipv4', 'ip' => $input_ip, 'mask' => ltrim($result['netmask_cidr'], '/')] + $sp);
} elseif ($result6) {
    $sp6 = $split_result6 ? ['split_prefix6' => ltrim($input_split_prefix6, '/')] : [];
    $share_url = '?' . http_build_query(['tab' => 'ipv6', 'ipv6' => $input_ipv6, 'prefix' => ltrim($result6['prefix'], '/')] + $sp6);
}
$share_proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$share_base_server = $share_proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$share_url_abs = $share_url !== '' ? $share_base_server . $share_url : '';
