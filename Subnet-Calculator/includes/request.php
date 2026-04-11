<?php

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

$multi_overlap_input  = '';
/** @var array<array{a: string, b: string, relation: string}>|null $multi_overlap_result */
$multi_overlap_result = null;
$multi_overlap_error  = null;

$vlsm_result = $vlsm_error = null;
$vlsm_network = $vlsm_cidr_input = '';
/** @var array<array{name: string, hosts: int}> $vlsm_requirements */
$vlsm_requirements = [];

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_tab   = $_POST['tab'] ?? $default_tab;
    $active_tab = in_array($post_tab, ['ipv4', 'ipv6', 'vlsm'], true) ? $post_tab : 'ipv4';

    $form_blocked = false;
    $is_splitter      = isset($_POST['split_prefix']) || isset($_POST['split_prefix6']);
    $is_overlap       = isset($_POST['overlap_cidr_a']) || isset($_POST['overlap_cidr_b']);
    $is_multi_overlap = isset($_POST['multi_overlap_input']);
    $is_vlsm          = isset($_POST['vlsm_network']);
    $is_supernet      = isset($_POST['supernet_action']);
    $is_ula           = isset($_POST['ula_generate']);
    $is_session_save  = isset($_POST['session_action']) && (string)($_POST['session_action'] ?? '') === 'save';

    $is_tool = $is_splitter || $is_overlap || $is_multi_overlap || $is_vlsm
        || $is_supernet || $is_ula || $is_session_save;

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
                                    $vlsm_requirements[] = ['name' => (string)$req['name'], 'hosts' => (int)$req['hosts']];
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
}
$share_proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$share_base_server = $share_proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$share_url_abs = $share_url !== '' ? $share_base_server . $share_url : '';

// Session save URL (shown after a successful session save)
$session_save_url = $session_save_id !== '' ? '?tab=vlsm&s=' . urlencode($session_save_id) : '';
