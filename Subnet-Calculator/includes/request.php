<?php // phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

declare(strict_types=1);

// ─── Input resolvers (shared by GET/POST handlers and API) ────────────────────
require_once __DIR__ . '/functions-resolve.php';

// ─── Turnstile verification ───────────────────────────────────────────────────

function turnstile_verify(string $token, string $secret, string $remoteip): bool
{
    if (!function_exists('curl_init')) {
        error_log('sc Turnstile: curl extension not available — verification skipped');
        return true;
    }
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $remoteip,
        ]),
        CURLOPT_TIMEOUT        => 5,
    ]);
    $raw  = curl_exec($ch);
    curl_close($ch);
    $json = $raw ? json_decode((string)$raw, true) : null;
    return (bool)($json['success'] ?? false);
}

function hcaptcha_verify(string $token, string $secret, string $remoteip): bool
{
    if (!function_exists('curl_init')) {
        error_log('sc hCaptcha: curl extension not available — verification failed');
        return false;
    }
    $ch = curl_init('https://api.hcaptcha.com/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $remoteip,
        ]),
        CURLOPT_TIMEOUT        => 5,
    ]);
    $raw  = curl_exec($ch);
    curl_close($ch);
    $json = $raw ? json_decode((string)$raw, true) : null;
    return (bool)($json['success'] ?? false);
}

function recaptcha_enterprise_verify(
    string $token,
    string $api_key,
    string $project_id,
    string $site_key,
    float $threshold
): bool {
    if (!function_exists('curl_init')) {
        error_log('sc reCAPTCHA Enterprise: curl extension not available — verification failed');
        return false;
    }
    $url  = 'https://recaptchaenterprise.googleapis.com/v1/projects/'
        . rawurlencode($project_id) . '/assessments?key=' . rawurlencode($api_key);
    $body = json_encode([
        'event' => [
            'token'          => $token,
            'siteKey'        => $site_key,
            'expectedAction' => 'SUBMIT',
        ],
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body !== false ? $body : '{}',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 5,
    ]);
    $raw  = curl_exec($ch);
    curl_close($ch);
    $json = $raw ? json_decode((string)$raw, true) : null;
    if (!is_array($json)) {
        return false;
    }
    $valid = (bool)($json['tokenProperties']['valid'] ?? false);
    $score = (float)($json['riskAnalysis']['score'] ?? 0.0);
    return $valid && $score >= $threshold;
}

// ─── Lookup helper (shared by POST handler and GET shareable URL) ────────────

/**
 * Run the IP lookup tool against the given raw textarea inputs and write the
 * outcome into $result_out / $error_out by reference. Used by both the POST
 * handler and the GET shareable-URL hydration path.
 *
 * @param list<array{ip: string, matches: list<string>, deepest: string|null}>|null $result_out
 */
function sc_run_lookup(
    string $cidrs_input,
    string $ips_input,
    ?array &$result_out,
    ?string &$error_out,
    int $max_cidrs = 100,
    int $max_ips = 1000
): void {
    // Enforce absolute safety ceilings (hard caps documented in OpenAPI spec).
    $max_cidrs = max(1, min($max_cidrs, 1000));
    $max_ips   = max(1, min($max_ips, 10000));

    $cidr_lines = array_values(array_filter(array_map('trim', explode("\n", $cidrs_input))));
    $ip_lines   = array_values(array_filter(array_map('trim', explode("\n", $ips_input))));
    if ($cidr_lines === []) {
        $error_out = 'At least one CIDR is required.';
        return;
    }
    if ($ip_lines === []) {
        $error_out = 'At least one IP is required.';
        return;
    }
    if (count($cidr_lines) > $max_cidrs) {
        $error_out = 'Too many CIDRs (max ' . $max_cidrs . ').';
        return;
    }
    if (count($ip_lines) > $max_ips) {
        $error_out = 'Too many IPs (max ' . $max_ips . ').';
        return;
    }
    try {
        $result_out = lookup_ips($cidr_lines, $ip_lines);
    } catch (\InvalidArgumentException $e) {
        $error_out = $e->getMessage();
    }
}

// ─── Diff helper (shared by POST handler and GET shareable URL) ──────────────

/**
 * Run subnet_diff against the given raw textarea inputs and write the outcome
 * into $result_out / $error_out by reference. Used by both the POST handler
 * and the GET shareable-URL hydration path.
 *
 * @param array{added: list<string>, removed: list<string>, unchanged: list<string>,
 *               changed: list<array{from: string, to: string, reason: string}>}|null $result_out
 */
function sc_run_diff(
    string $before_input,
    string $after_input,
    ?array &$result_out,
    ?string &$error_out,
    int $max_entries = 1000
): void {
    $before_lines = array_values(array_filter(array_map('trim', explode("\n", $before_input))));
    $after_lines  = array_values(array_filter(array_map('trim', explode("\n", $after_input))));
    if ($before_lines === [] && $after_lines === []) {
        $error_out = 'At least one CIDR is required in either Before or After.';
        return;
    }
    if (count($before_lines) > $max_entries) {
        $error_out = 'Too many CIDRs in Before (max ' . $max_entries . ').';
        return;
    }
    if (count($after_lines) > $max_entries) {
        $error_out = 'Too many CIDRs in After (max ' . $max_entries . ').';
        return;
    }
    try {
        $result_out = subnet_diff($before_lines, $after_lines);
    } catch (\InvalidArgumentException $e) {
        $error_out = $e->getMessage();
    }
}

// ─── Request handling ─────────────────────────────────────────────────────────

$get_tab    = $_GET['tab'] ?? $default_tab;
$active_tab = in_array($get_tab, ['ipv4', 'ipv6', 'vlsm', 'vlsm6'], true) ? $get_tab : 'ipv4';

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

$multi_overlap_input  = '';
/** @var array<array{a: string, b: string, relation: string}>|null $multi_overlap_result */
$multi_overlap_result = null;
$multi_overlap_error  = null;

$vlsm_result = $vlsm_error = null;
$vlsm_network = $vlsm_cidr_input = '';
/** @var array<array{name: string, hosts: int}> $vlsm_requirements */
$vlsm_requirements = [];

/** @var list<array{name: string, hosts_needed: int|string, subnet: string, usable: int|string}>|null $vlsm6_result */
$vlsm6_result = null;
$vlsm6_error  = null;
$vlsm6_network = $vlsm6_cidr_input = '';
/** @var array<array{name: string, hosts: int|string}> $vlsm6_requirements */
$vlsm6_requirements = [];

$supernet_input  = '';
$supernet_action = '';
/** @var array{supernet?: string, summaries?: string[]}|null $supernet_result */
$supernet_result = null;
$supernet_error  = null;

$ula_global_id_input = '';
/** @var array{prefix?: string, global_id?: string, example_64s?: string[], available_64s?: int}|null $ula_result */
$ula_result = null;
$ula_error  = null;

$session_save_id  = '';
$session_load_id  = '';
$session_error    = null;

$range_start  = '';
$range_end    = '';
/** @var list<string>|null $range_result */
$range_result = null;
$range_error  = null;

$tree_parent   = '';
$tree_children = '';
/** @var array<string, mixed>|null $tree_result */
$tree_result = null;
$tree_error  = null;

$wildcard_input  = '';
/** @var array{cidr: string, wildcard: string}|null $wildcard_result */
$wildcard_result = null;
$wildcard_error  = null;

$lookup_cidrs_input = '';
$lookup_ips_input   = '';
/** @var list<array{ip: string, matches: list<string>, deepest: string|null}>|null $lookup_result */
$lookup_result = null;
$lookup_error  = null;

$diff_before_input = '';
$diff_after_input  = '';
/** @var array{added: list<string>, removed: list<string>, unchanged: list<string>, changed: list<array{from: string, to: string, reason: string}>}|null $diff_result */
$diff_result = null;
$diff_error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_tab   = $_POST['tab'] ?? $default_tab;
    $active_tab = in_array($post_tab, ['ipv4', 'ipv6', 'vlsm', 'vlsm6'], true) ? $post_tab : 'ipv4';

    $form_blocked = false;
    $is_splitter      = isset($_POST['split_prefix']) || isset($_POST['split_prefix6']);
    $is_overlap       = isset($_POST['overlap_cidr_a']) || isset($_POST['overlap_cidr_b']);
    $is_multi_overlap = isset($_POST['multi_overlap_input']);
    $is_vlsm          = isset($_POST['vlsm_network']);
    $is_vlsm6         = isset($_POST['vlsm6_network']);
    $is_supernet      = isset($_POST['supernet_action']);
    $is_ula           = isset($_POST['ula_generate']);
    $is_session_save  = isset($_POST['session_action']) && (string)($_POST['session_action'] ?? '') === 'save';
    $is_range         = isset($_POST['range_start']) || isset($_POST['range_end']);
    $is_tree          = isset($_POST['tree_parent']);
    $is_wildcard      = isset($_POST['wildcard_input']);
    $is_lookup        = isset($_POST['lookup_cidrs']) || isset($_POST['lookup_ips']);
    $is_diff          = isset($_POST['diff_before']) || isset($_POST['diff_after']);

    // Tool drawers (splitter/overlap/vlsm/vlsm6/supernet/ula/session/range/tree/wildcard/lookup/diff)
    // bypass honeypot/CAPTCHA gates because they're follow-on actions in an already-loaded session,
    // not entry-point form posts. Only the main IPv4/IPv6 calculator forms are gated.
    $is_tool = $is_splitter || $is_overlap || $is_multi_overlap || $is_vlsm
        || $is_vlsm6 || $is_supernet || $is_ula || $is_session_save || $is_range
        || $is_tree || $is_wildcard || $is_lookup || $is_diff;

    if (!$is_tool && $form_protection === 'honeypot') {
        if (trim((string)($_POST['url'] ?? '')) !== '') {
            $form_blocked = true;
        }
    } elseif (!$is_tool && $turnstile_active) {
        $token = trim((string)($_POST['cf-turnstile-response'] ?? ''));
        if ($token === '') {
            $form_blocked = true;
            if ($active_tab === 'ipv6') {
                $error6 = 'Please complete the CAPTCHA.';
            } else {
                $error = 'Please complete the CAPTCHA.';
            }
        } else {
            if (!turnstile_verify($token, $turnstile_secret_key, $_SERVER['REMOTE_ADDR'] ?? '')) {
                $form_blocked = true;
                if ($active_tab === 'ipv6') {
                    $error6 = 'CAPTCHA verification failed. Please try again.';
                } else {
                    $error = 'CAPTCHA verification failed. Please try again.';
                }
            }
        }
    } elseif (!$is_tool && $hcaptcha_active) {
        $token = trim((string)($_POST['h-captcha-response'] ?? ''));
        if ($token === '') {
            $form_blocked = true;
            if ($active_tab === 'ipv6') {
                $error6 = 'Please complete the CAPTCHA.';
            } else {
                $error = 'Please complete the CAPTCHA.';
            }
        } elseif (!hcaptcha_verify($token, $hcaptcha_secret_key, $_SERVER['REMOTE_ADDR'] ?? '')) {
            $form_blocked = true;
            if ($active_tab === 'ipv6') {
                $error6 = 'CAPTCHA verification failed. Please try again.';
            } else {
                $error = 'CAPTCHA verification failed. Please try again.';
            }
        }
    } elseif (!$is_tool && $recaptcha_active) {
        $token = trim((string)($_POST['g-recaptcha-response'] ?? ''));
        if ($token === '') {
            $form_blocked = true;
            if ($active_tab === 'ipv6') {
                $error6 = 'Please complete the CAPTCHA.';
            } else {
                $error = 'Please complete the CAPTCHA.';
            }
        } else {
            $recaptcha_ok = recaptcha_enterprise_verify(
                $token,
                $recaptcha_enterprise_api_key,
                $recaptcha_enterprise_project_id,
                $recaptcha_enterprise_site_key,
                $recaptcha_score_threshold
            );
            if (!$recaptcha_ok) {
                $form_blocked = true;
                if ($active_tab === 'ipv6') {
                    $error6 = 'CAPTCHA verification failed. Please try again.';
                } else {
                    $error = 'CAPTCHA verification failed. Please try again.';
                }
            }
        }
    }

    if (!$form_blocked && $active_tab === 'ipv4' && !$is_supernet) {
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
    } elseif (!$form_blocked && !$is_ula) {
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
        $a_is_v6 = strpos($overlap_cidr_a, ':') !== false;
        $b_is_v6 = strpos($overlap_cidr_b, ':') !== false;
        if ($a_is_v6 !== $b_is_v6) {
            $overlap_error = 'Cannot compare IPv4 and IPv6 addresses.';
        } elseif ($a_is_v6) {
            if (!extension_loaded('gmp')) {
                $overlap_error = 'IPv6 overlap check requires the PHP GMP extension.';
            } else {
                [$a_ip, $a_pfx] = array_pad(explode('/', $overlap_cidr_a, 2), 2, '');
                [$b_ip, $b_pfx] = array_pad(explode('/', $overlap_cidr_b, 2), 2, '');
                $a_ip  = trim($a_ip);
                $a_pfx = trim($a_pfx);
                $b_ip  = trim($b_ip);
                $b_pfx = trim($b_pfx);
                if (!is_valid_ipv6($a_ip) || !ctype_digit($a_pfx) || (int)$a_pfx > 128) {
                    $overlap_error = 'First subnet: Invalid IPv6 CIDR.';
                } elseif (!is_valid_ipv6($b_ip) || !ctype_digit($b_pfx) || (int)$b_pfx > 128) {
                    $overlap_error = 'Second subnet: Invalid IPv6 CIDR.';
                } else {
                    try {
                        $r6a = calculate_subnet6($a_ip, (int)$a_pfx);
                        $r6b = calculate_subnet6($b_ip, (int)$b_pfx);
                        $overlap_result = cidrs_overlap6($r6a['network_cidr'], $r6b['network_cidr']);
                    } catch (\Exception $e) {
                        error_log('sc IPv6 overlap error: ' . $e->getMessage());
                        $overlap_error = 'An error occurred during calculation. Please check your input.';
                    }
                }
            }
        } else {
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
    }

    if ($is_multi_overlap && !$form_blocked) {
        $multi_overlap_input = trim((string)($_POST['multi_overlap_input'] ?? ''));
        $raw_lines = array_filter(array_map('trim', explode("\n", $multi_overlap_input)));
        $lines = array_values($raw_lines);
        if (count($lines) < 2) {
            $multi_overlap_error = 'Enter at least two CIDRs (one per line).';
        } elseif (count($lines) > 50) {
            $multi_overlap_error = 'Maximum 50 CIDRs per check.';
        } else {
            $normalised = [];
            $multi_err  = null;
            foreach ($lines as $line) {
                $is_v6 = strpos($line, ':') !== false;
                if ($is_v6) {
                    if (!extension_loaded('gmp')) {
                        $multi_err = 'IPv6 overlap check requires the PHP GMP extension.';
                        break;
                    }
                    [$m_ip, $m_pfx] = array_pad(explode('/', $line, 2), 2, '');
                    $m_ip  = trim($m_ip);
                    $m_pfx = trim($m_pfx);
                    if (!is_valid_ipv6($m_ip) || !ctype_digit($m_pfx) || (int)$m_pfx > 128) {
                        $multi_err = 'Invalid CIDR: ' . $line;
                        break;
                    }
                    try {
                        $r6m = calculate_subnet6($m_ip, (int)$m_pfx);
                        $normalised[] = ['cidr' => $r6m['network_cidr'], 'v6' => true];
                    } catch (\Exception $e) {
                        $multi_err = 'Invalid CIDR: ' . $line;
                        break;
                    }
                } else {
                    $rm = resolve_ipv4_input($line, '');
                    if (!$rm['result']) {
                        $multi_err = 'Invalid CIDR: ' . $line;
                        break;
                    }
                    $normalised[] = ['cidr' => $rm['result']['network_cidr'], 'v6' => false];
                }
            }
            if ($multi_err !== null) {
                $multi_overlap_error = $multi_err;
            } else {
                $has_v4 = false;
                $has_v6 = false;
                foreach ($normalised as $n) {
                    if ($n['v6']) {
                        $has_v6 = true;
                    } else {
                        $has_v4 = true;
                    }
                }
                if ($has_v4 && $has_v6) {
                    $multi_overlap_error = 'Cannot mix IPv4 and IPv6 CIDRs.';
                } else {
                    $conflicts = [];
                    $n_count = count($normalised);
                    for ($mi = 0; $mi < $n_count; $mi++) {
                        for ($mj = $mi + 1; $mj < $n_count; $mj++) {
                            $rel = $normalised[$mi]['v6']
                                ? cidrs_overlap6($normalised[$mi]['cidr'], $normalised[$mj]['cidr'])
                                : cidrs_overlap($normalised[$mi]['cidr'], $normalised[$mj]['cidr']);
                            if ($rel !== 'none') {
                                $conflicts[] = [
                                    'a'        => $normalised[$mi]['cidr'],
                                    'b'        => $normalised[$mj]['cidr'],
                                    'relation' => $rel,
                                ];
                            }
                        }
                    }
                    $multi_overlap_result = $conflicts;
                }
            }
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
                    $name  = mb_substr(trim((string)$name), 0, 100);
                    $hval  = trim((string)($hosts[$i] ?? ''));
                    if ($name === '' || !ctype_digit($hval) || (int)$hval < 1) {
                        continue;
                    }
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
    if ($is_vlsm6 && !$form_blocked) {
        $vlsm6_network    = trim((string)($_POST['vlsm6_network'] ?? ''));
        $vlsm6_cidr_input = trim((string)($_POST['vlsm6_cidr']    ?? ''));
        $rv6 = resolve_ipv6_input($vlsm6_network, $vlsm6_cidr_input);
        if (!$rv6['result6']) {
            $vlsm6_error = 'Parent network: ' . ($rv6['error6'] ?? 'Invalid input.');
        } else {
            $names6 = $_POST['vlsm6_name']  ?? [];
            $hosts6 = $_POST['vlsm6_hosts'] ?? [];
            if (!is_array($names6) || !is_array($hosts6) || count($names6) === 0) {
                $vlsm6_error = 'Add at least one requirement.';
            } else {
                $reqs6 = [];
                $name6_too_long = false;
                foreach ($names6 as $i => $name6) {
                    $name6 = trim((string)$name6);
                    if (mb_strlen($name6) > 100) {
                        $name6_too_long = true;
                        break;
                    }
                    $hval6 = trim((string)($hosts6[$i] ?? ''));
                    if ($name6 === '' || $hval6 === '') {
                        continue;
                    }
                    if (!preg_match('/^(\d+|2\^\d{1,3})$/', $hval6)) {
                        continue;
                    }
                    $reqs6[] = ['name' => $name6, 'hosts' => $hval6];
                }
                if ($name6_too_long) {
                    $vlsm6_error = 'Each requirement name must be 100 characters or fewer.';
                } elseif ($reqs6 === []) {
                    $vlsm6_error = 'Add at least one valid requirement.';
                } else {
                    $vlsm6_cidr_int   = (int)ltrim($rv6['result6']['prefix'], '/');
                    $vlsm6_network_ip = explode('/', $rv6['result6']['network_cidr'])[0];
                    $vlsm6_requirements = $reqs6;
                    $vr6 = vlsm6_allocate($vlsm6_network_ip, $vlsm6_cidr_int, $reqs6);
                    if (isset($vr6['error'])) {
                        $vlsm6_error = $vr6['error'];
                    } else {
                        $vlsm6_result = $vr6['allocations'] ?? [];
                    }
                }
            }
        }
    }

    if ($is_supernet && !$form_blocked) {
        $supernet_action = in_array((string)($_POST['supernet_action'] ?? ''), ['find', 'summarise'], true)
            ? (string)$_POST['supernet_action']
            : 'find';
        $supernet_input  = trim((string)($_POST['supernet_input'] ?? ''));
        $lines = array_values(array_filter(array_map('trim', explode("\n", $supernet_input))));
        if (count($lines) < 1) {
            $supernet_error = 'Enter at least one CIDR.';
        } elseif (count($lines) > 50) {
            $supernet_error = 'Maximum 50 CIDRs per check.';
        } else {
            $sr = $supernet_action === 'find' ? supernet_find($lines) : summarise_cidrs($lines);
            if (isset($sr['error'])) {
                $supernet_error = $sr['error'];
            } else {
                $supernet_result = $sr;
            }
        }
    }

    if ($is_ula && !$form_blocked) {
        $ula_global_id_input = trim((string)($_POST['ula_global_id'] ?? ''));
        $ur = generate_ula_prefix($ula_global_id_input);
        if (isset($ur['error'])) {
            $ula_error = $ur['error'];
        } else {
            $ula_result = $ur;
        }
    }

    if ($is_session_save && !$form_blocked && $session_enabled) {
        $vlsm_network    = trim((string)($_POST['vlsm_network'] ?? ''));
        $vlsm_cidr_input = trim((string)($_POST['vlsm_cidr']   ?? ''));
        $rv = resolve_ipv4_input($vlsm_network, $vlsm_cidr_input);
        if (!$rv['result']) {
            $session_error = 'Parent network: ' . ($rv['error'] ?? 'Invalid input.');
        } else {
            $names = $_POST['vlsm_name']  ?? [];
            $hosts = $_POST['vlsm_hosts'] ?? [];
            $reqs  = [];
            if (is_array($names) && is_array($hosts)) {
                foreach ($names as $i => $name) {
                    $name = mb_substr(trim((string)$name), 0, 100);
                    $hval = trim((string)($hosts[$i] ?? ''));
                    if ($name !== '' && ctype_digit($hval) && (int)$hval >= 1) {
                        $reqs[] = ['name' => $name, 'hosts' => (int)$hval];
                    }
                }
            }
            if ($reqs === []) {
                $session_error = 'No valid VLSM requirements to save.';
            } else {
                $vlsm_cidr_int   = (int)ltrim($rv['result']['netmask_cidr'], '/');
                $vlsm_network_ip = explode('/', $rv['result']['network_cidr'])[0];
                $vlsm_requirements = $reqs;
                $vr = vlsm_allocate($vlsm_network_ip, $vlsm_cidr_int, $reqs);
                if (isset($vr['error'])) {
                    $session_error = $vr['error'];
                } else {
                    $vlsm_result = $vr['allocations'] ?? [];
                    try {
                        $db_path = $session_db_path !== '' ? $session_db_path
                            : dirname(__DIR__) . '/data/sessions.sqlite';
                        $db_dir  = dirname($db_path);
                        if (!is_dir($db_dir)) {
                            mkdir($db_dir, 0755, true);
                        }
                        $sdb  = session_db_open($db_path);
                        $session_save_id = session_create($sdb, [
                            'network'      => $vlsm_network,
                            'cidr'         => ltrim($vlsm_cidr_input, '/'),
                            'requirements' => $reqs,
                        ], $session_ttl_days);
                        $sdb->close();
                    } catch (\Exception $e) {
                        error_log('sc session save error: ' . $e->getMessage());
                        $session_error = 'Failed to save session. Please try again.';
                    }
                }
            }
        }
    }
    if ($is_range && !$form_blocked) {
        $range_start = trim((string)($_POST['range_start'] ?? ''));
        $range_end   = trim((string)($_POST['range_end']   ?? ''));
        if ($range_start === '' || $range_end === '') {
            $range_error = 'Both start and end IP addresses are required.';
        } else {
            $rr = range_to_cidrs($range_start, $range_end);
            if (isset($rr['error'])) {
                $range_error = $rr['error'];
            } else {
                $range_result = $rr['cidrs'] ?? [];
            }
        }
    }

    if ($is_wildcard && !$form_blocked) {
        $wildcard_input = trim((string)($_POST['wildcard_input'] ?? ''));
        if ($wildcard_input === '') {
            $wildcard_error = 'A CIDR prefix or wildcard mask is required.';
        } else {
            try {
                if (str_contains($wildcard_input, '.')) {
                    $wildcard_result = [
                        'cidr'     => wildcard_to_cidr($wildcard_input),
                        'wildcard' => $wildcard_input,
                    ];
                } else {
                    $wildcard_result = [
                        'cidr'     => '/' . ltrim($wildcard_input, '/'),
                        'wildcard' => cidr_to_wildcard($wildcard_input),
                    ];
                }
            } catch (\InvalidArgumentException $e) {
                $wildcard_error = $e->getMessage();
            }
        }
    }

    if ($is_lookup && !$form_blocked) {
        $lookup_cidrs_input = (string)($_POST['lookup_cidrs'] ?? '');
        $lookup_ips_input   = (string)($_POST['lookup_ips']   ?? '');
        sc_run_lookup(
            $lookup_cidrs_input,
            $lookup_ips_input,
            $lookup_result,
            $lookup_error,
            isset($lookup_max_cidrs) ? (int)$lookup_max_cidrs : 100,
            isset($lookup_max_ips)   ? (int)$lookup_max_ips   : 1000,
        );
    }

    if ($is_diff && !$form_blocked) {
        $diff_before_input = (string)($_POST['diff_before'] ?? '');
        $diff_after_input  = (string)($_POST['diff_after']  ?? '');
        sc_run_diff(
            $diff_before_input,
            $diff_after_input,
            $diff_result,
            $diff_error,
        );
    }

    if ($is_tree && !$form_blocked) {
        $tree_parent   = trim((string)($_POST['tree_parent']   ?? ''));
        $tree_children = trim((string)($_POST['tree_children'] ?? ''));
        $child_lines   = array_values(array_filter(array_map('trim', explode("\n", $tree_children))));
        if ($tree_parent === '') {
            $tree_error = 'Parent CIDR is required.';
        } elseif (count($child_lines) > 100) {
            $tree_error = 'Maximum 100 child CIDRs per request.';
        } else {
            $tr = build_subnet_tree($tree_parent, $child_lines);
            if (isset($tr['error'])) {
                $tree_error = $tr['error'];
            } else {
                $tree_result = $tr['tree'] ?? [];
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Session load: ?tab=vlsm&s=<id>
    if ($session_enabled && $active_tab === 'vlsm' && isset($_GET['s'])) {
        $session_load_id = trim((string)$_GET['s']);
        if (preg_match('/^[0-9a-f]{8}$/', $session_load_id)) {
            try {
                $db_path = $session_db_path !== '' ? $session_db_path
                    : dirname(__DIR__) . '/data/sessions.sqlite';
                if (file_exists($db_path)) {
                    $sdb     = session_db_open($db_path);
                    $payload = session_load($sdb, $session_load_id);
                    $sdb->close();
                    if ($payload === null) {
                        $session_error = 'Session not found or expired.';
                    } else {
                        $vlsm_network    = (string)($payload['network'] ?? '');
                        $vlsm_cidr_input = (string)($payload['cidr']    ?? '');
                        $raw_reqs        = $payload['requirements'] ?? [];
                        if (is_array($raw_reqs)) {
                            foreach ($raw_reqs as $req) {
                                if (is_array($req) && isset($req['name'], $req['hosts'])) {
                                    $vlsm_requirements[] = [
                                        'name'  => (string)$req['name'],
                                        'hosts' => (int)$req['hosts'],
                                    ];
                                }
                            }
                        }
                        if ($vlsm_requirements !== [] && $vlsm_network !== '') {
                            $rv = resolve_ipv4_input($vlsm_network, $vlsm_cidr_input);
                            if ($rv['result']) {
                                $vlsm_cidr_int   = (int)ltrim($rv['result']['netmask_cidr'], '/');
                                $vlsm_network_ip = explode('/', $rv['result']['network_cidr'])[0];
                                $vr = vlsm_allocate($vlsm_network_ip, $vlsm_cidr_int, $vlsm_requirements);
                                if (isset($vr['error'])) {
                                    $vlsm_error = $vr['error'];
                                } else {
                                    $vlsm_result = $vr['allocations'] ?? [];
                                }
                            }
                        }
                    }
                } else {
                    $session_error = 'Session storage is not initialised.';
                }
            } catch (\Exception $e) {
                error_log('sc session load error: ' . $e->getMessage());
                $session_error = 'Failed to load session.';
            }
        } else {
            $session_error = 'Invalid session ID format.';
        }
    }

    // IP Lookup shareable GET URL (works on both ipv4 and ipv6 tabs)
    if (
        ($active_tab === 'ipv4' || $active_tab === 'ipv6')
        && (isset($_GET['lookup_cidrs']) || isset($_GET['lookup_ips']))
    ) {
        $lookup_cidrs_input = (string)($_GET['lookup_cidrs'] ?? '');
        $lookup_ips_input   = (string)($_GET['lookup_ips']   ?? '');
        sc_run_lookup(
            $lookup_cidrs_input,
            $lookup_ips_input,
            $lookup_result,
            $lookup_error,
            isset($lookup_max_cidrs) ? (int)$lookup_max_cidrs : 100,
            isset($lookup_max_ips)   ? (int)$lookup_max_ips   : 1000,
        );
    }

    // Subnet Diff shareable GET URL (works on both ipv4 and ipv6 tabs)
    if (
        ($active_tab === 'ipv4' || $active_tab === 'ipv6')
        && (isset($_GET['diff_before']) || isset($_GET['diff_after']))
    ) {
        $diff_before_input = (string)($_GET['diff_before'] ?? '');
        $diff_after_input  = (string)($_GET['diff_after']  ?? '');
        sc_run_diff(
            $diff_before_input,
            $diff_after_input,
            $diff_result,
            $diff_error,
        );
    }

    // Supernet / summarise shareable GET URL
    if ($active_tab === 'ipv4' && isset($_GET['supernet_action'])) {
        $supernet_action = in_array((string)($_GET['supernet_action'] ?? ''), ['find', 'summarise'], true)
            ? (string)$_GET['supernet_action']
            : 'find';
        $supernet_input = trim((string)($_GET['supernet_input'] ?? ''));
        $lines = array_values(array_filter(array_map('trim', explode("\n", $supernet_input))));
        if (count($lines) >= 1 && count($lines) <= 50) {
            $sr = $supernet_action === 'find' ? supernet_find($lines) : summarise_cidrs($lines);
            if (isset($sr['error'])) {
                $supernet_error = $sr['error'];
            } else {
                $supernet_result = $sr;
            }
        }
    }

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
    } elseif ($active_tab === 'ipv6') {
        $get_ipv6   = trim((string)($_GET['ipv6']   ?? ''));
        $get_prefix = trim((string)($_GET['prefix'] ?? ''));
        if ($get_ipv6 !== '') {
            $r = resolve_ipv6_input($get_ipv6, $get_prefix);
            $result6      = $r['result6'];
            $error6       = $r['error6'];
            $input_ipv6   = $r['ip'];
            $input_prefix = $r['prefix'];
        }
    } elseif ($active_tab === 'vlsm6') {
        $get_vlsm6_network = trim((string)($_GET['vlsm6_network'] ?? ''));
        $get_vlsm6_cidr    = trim((string)($_GET['vlsm6_cidr']    ?? ''));
        if ($get_vlsm6_network !== '') {
            $rv6 = resolve_ipv6_input($get_vlsm6_network, $get_vlsm6_cidr);
            if (!$rv6['result6']) {
                $vlsm6_error = 'Parent network: ' . ($rv6['error6'] ?? 'Invalid input.');
            } else {
                $vlsm6_network    = $rv6['ip'];
                $vlsm6_cidr_input = ltrim($rv6['result6']['prefix'], '/');
                $get_names6 = $_GET['vlsm6_name']  ?? [];
                $get_hosts6 = $_GET['vlsm6_hosts'] ?? [];
                if (is_array($get_names6) && is_array($get_hosts6) && count($get_names6) > 0) {
                    $reqs6 = [];
                    $name6_too_long = false;
                    foreach ($get_names6 as $i => $name6) {
                        $name6 = trim((string)$name6);
                        if (mb_strlen($name6) > 100) {
                            $name6_too_long = true;
                            break;
                        }
                        $hval6 = trim((string)($get_hosts6[$i] ?? ''));
                        if (
                            $name6 !== '' && $hval6 !== ''
                            && preg_match('/^(\d+|2\^\d{1,3})$/', $hval6)
                        ) {
                            $reqs6[] = ['name' => $name6, 'hosts' => $hval6];
                        }
                    }
                    if ($name6_too_long) {
                        $vlsm6_error = 'Each requirement name must be 100 characters or fewer.';
                    } elseif ($reqs6 !== []) {
                        $vlsm6_requirements = $reqs6;
                        $vlsm6_cidr_int     = (int)ltrim((string)$rv6['result6']['prefix'], '/');
                        $vlsm6_network_ip   = explode('/', (string)$rv6['result6']['network_cidr'])[0];
                        $vr6 = vlsm6_allocate($vlsm6_network_ip, $vlsm6_cidr_int, $reqs6);
                        if (isset($vr6['error'])) {
                            $vlsm6_error = $vr6['error'];
                        } else {
                            $vlsm6_result = $vr6['allocations'] ?? [];
                        }
                    }
                }
            }
        }
    } elseif ($active_tab === 'vlsm') {
        $get_vlsm_network = trim((string)($_GET['vlsm_network'] ?? ''));
        $get_vlsm_cidr    = trim((string)($_GET['vlsm_cidr']   ?? ''));
        if ($get_vlsm_network !== '') {
            $rv = resolve_ipv4_input($get_vlsm_network, $get_vlsm_cidr);
            if (!$rv['result']) {
                $vlsm_error = 'Parent network: ' . ($rv['error'] ?? 'Invalid input.');
            } else {
                $vlsm_network    = $rv['ip'];
                $vlsm_cidr_input = ltrim($rv['result']['netmask_cidr'], '/');
                $get_names = $_GET['vlsm_name']  ?? [];
                $get_hosts = $_GET['vlsm_hosts'] ?? [];
                if (is_array($get_names) && is_array($get_hosts) && count($get_names) > 0) {
                    $reqs = [];
                    foreach ($get_names as $i => $name) {
                        $name = mb_substr(trim((string)$name), 0, 100);
                        $hval = trim((string)($get_hosts[$i] ?? ''));
                        if ($name !== '' && ctype_digit($hval) && (int)$hval >= 1) {
                            $reqs[] = ['name' => $name, 'hosts' => (int)$hval];
                        }
                    }
                    if ($reqs !== []) {
                        $vlsm_requirements = $reqs;
                        $vlsm_cidr_int     = (int)ltrim($rv['result']['netmask_cidr'], '/');
                        $vlsm_network_ip   = explode('/', $rv['result']['network_cidr'])[0];
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
$bg_regex = '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/';
if (
    $fixed_bg_color !== 'null' && $fixed_bg_color !== ''
    && preg_match($bg_regex, (string)$fixed_bg_color)
) {
    $bg_override_style = ':root,html[data-theme="light"]{--color-bg:' . htmlspecialchars((string)$fixed_bg_color) . '}';
}

// Build shareable URL
$share_url = '';
if ($result) {
    $sp = $split_result ? ['split_prefix' => ltrim($input_split_prefix, '/')] : [];
    $share_url = '?' . http_build_query(
        ['tab' => 'ipv4', 'ip' => $input_ip, 'mask' => ltrim($result['netmask_cidr'], '/')] + $sp
    );
} elseif ($result6) {
    $sp6 = $split_result6 ? ['split_prefix6' => ltrim($input_split_prefix6, '/')] : [];
    $share_url = '?' . http_build_query(
        ['tab' => 'ipv6', 'ipv6' => $input_ipv6, 'prefix' => ltrim($result6['prefix'], '/')] + $sp6
    );
} elseif ($vlsm_result !== null && $vlsm_network !== '') {
    $vlsm_names = array_map(fn($r) => $r['name'], $vlsm_requirements);
    $vlsm_qhosts = array_map(fn($r) => $r['hosts'], $vlsm_requirements);
    $share_url = '?' . http_build_query([
        'tab'          => 'vlsm',
        'vlsm_network' => $vlsm_network,
        'vlsm_cidr'    => ltrim($vlsm_cidr_input, '/'),
        'vlsm_name'    => $vlsm_names,
        'vlsm_hosts'   => $vlsm_qhosts,
    ]);
} elseif ($vlsm6_result !== null && $vlsm6_network !== '') {
    $vlsm6_names  = array_map(fn($r) => $r['name'], $vlsm6_requirements);
    $vlsm6_qhosts = array_map(fn($r) => $r['hosts'], $vlsm6_requirements);
    $share_url = '?' . http_build_query([
        'tab'           => 'vlsm6',
        'vlsm6_network' => $vlsm6_network,
        'vlsm6_cidr'    => ltrim($vlsm6_cidr_input, '/'),
        'vlsm6_name'    => $vlsm6_names,
        'vlsm6_hosts'   => $vlsm6_qhosts,
    ]);
}
$share_proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$share_base_server = $share_proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$share_url_abs = $share_url !== '' ? $share_base_server . $share_url : '';

// Session save URL (shown after a successful session save)
$session_save_url = $session_save_id !== '' ? '?tab=vlsm&s=' . urlencode($session_save_id) : '';
