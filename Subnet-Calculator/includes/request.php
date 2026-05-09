<?php // phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

declare(strict_types=1);

// ─── Input resolvers (shared by GET/POST handlers and API) ────────────────────
require_once __DIR__ . '/functions-resolve.php';
require_once __DIR__ . '/functions-tree-diff.php';

// ─── Turnstile verification ───────────────────────────────────────────────────

function turnstile_verify(string $token, string $secret, string $remoteip): bool
{
    if (!function_exists('curl_init')) {
        error_log('sc Turnstile: curl extension not available — verification failed');
        return false;
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
 * Run lookup_ips() against the given raw textarea inputs and return an
 * associative array with keys: result, error. Empty-input early-returns
 * with [] so empty-state defaults apply. (v3.4.0 — flat → array)
 *
 * @return array{result?: list<array{ip: string, matches: list<string>, deepest: string|null}>, error?: string}
 */
function sc_run_lookup(
    string $cidrs_input,
    string $ips_input,
    int $max_cidrs = 100,
    int $max_ips = 1000
): array {
    // Enforce absolute safety ceilings (hard caps documented in OpenAPI spec).
    $max_cidrs = max(1, min($max_cidrs, 1000));
    $max_ips   = max(1, min($max_ips, 10000));

    $cidr_lines = array_values(array_filter(array_map('trim', explode("\n", $cidrs_input))));
    $ip_lines   = array_values(array_filter(array_map('trim', explode("\n", $ips_input))));
    if ($cidr_lines === []) {
        return ['error' => 'At least one CIDR is required.'];
    }
    if ($ip_lines === []) {
        return ['error' => 'At least one IP is required.'];
    }
    if (count($cidr_lines) > $max_cidrs) {
        return ['error' => 'Too many CIDRs (max ' . $max_cidrs . ').'];
    }
    if (count($ip_lines) > $max_ips) {
        return ['error' => 'Too many IPs (max ' . $max_ips . ').'];
    }
    try {
        return ['result' => lookup_ips($cidr_lines, $ip_lines)];
    } catch (\InvalidArgumentException $e) {
        return ['error' => $e->getMessage()];
    }
}

// ─── Range6 helper (shared by POST handler and GET shareable URL) ───────────

/**
 * Run range6_to_cidrs() and return an associative array with keys: result
 * (cidrs list), error, warning, count, total. Empty inputs return [].
 * (v3.4.0 — flat → array)
 *
 * @return array{result?: list<string>, error?: string, warning?: string, count?: ?int, total?: int|string|null}
 */
function sc_run_range6(string $start, string $end): array
{
    if ($start === '' && $end === '') {
        return [];
    }
    if ($start === '' || $end === '') {
        return ['error' => 'Start and end IPv6 addresses are required.'];
    }
    $r = range6_to_cidrs($start, $end);
    if (isset($r['error'])) {
        return ['error' => $r['error']];
    }
    $out = [
        'result' => $r['cidrs'] ?? [],
        'count'  => $r['count'] ?? null,
        'total'  => $r['total_addresses'] ?? null,
    ];
    if (!empty($r['truncated'])) {
        $cap = (int)($r['cap'] ?? 256);
        $out['warning'] = 'Result truncated at ' . $cap . ' CIDRs (configure via $range_max_cidrs).';
    }
    return $out;
}

// ─── Zone-ID helper (shared by POST handler and GET shareable URL) ──────────

/**
 * Run parse_zone_id() against the given input and return an associative array
 * with keys: address, zone_id, is_link_local, warning, error. Empty input
 * returns an empty array. (v3.4.0 — flat globals → per-tool array)
 *
 * @return array{address?: string, zone_id?: ?string, is_link_local?: bool, warning?: ?string, error?: string}
 */
function sc_run_zoneid(string $input): array
{
    if ($input === '') {
        return [];
    }
    try {
        $r = parse_zone_id($input);
    } catch (\InvalidArgumentException $e) {
        return ['error' => $e->getMessage()];
    }
    return [
        'address'       => $r['address'],
        'zone_id'       => $r['zone_id'],
        'is_link_local' => $r['is_link_local'],
        'warning'       => $r['warning'],
    ];
}

// ─── Derive helper (shared by POST handler and GET shareable URL) ───────────

/**
 * Run derive_from_mac() and return an associative array with keys:
 * mac_canonical, eui64, ul_bit_flipped, link_local, solicited_node,
 * warning, error. Empty input returns []. (v3.4.0 — flat → array)
 *
 * @return array{
 *     mac_canonical?: string, eui64?: string, ul_bit_flipped?: bool,
 *     link_local?: string, solicited_node?: string, warning?: ?string, error?: string
 * }
 */
function sc_run_derive(string $mac): array
{
    if ($mac === '') {
        return [];
    }
    try {
        $r = derive_from_mac($mac);
    } catch (\InvalidArgumentException $e) {
        return ['error' => $e->getMessage()];
    }
    return [
        'mac_canonical'  => $r['mac_canonical'],
        'eui64'          => $r['eui64'],
        'ul_bit_flipped' => $r['ul_bit_flipped'],
        'link_local'     => $r['link_local'],
        'solicited_node' => $r['solicited_node'],
        'warning'        => $r['warning'],
    ];
}

// ─── SLAAC privacy helper (shared by POST handler and GET shareable URL) ────

/**
 * Run slaac_privacy_address() and return an associative array with keys:
 * prefix, address, interface_id, seed_used, seed_was_provided, warning,
 * error. Empty prefix returns []. (v3.4.0 — flat → array)
 *
 * Empty seed strings are treated as "unseeded" so a shareable URL with an
 * empty `slaac_seed=` parameter still produces a fresh address.
 *
 * @return array{
 *     prefix?: string, address?: string, interface_id?: string,
 *     seed_used?: ?string, seed_was_provided?: bool, warning?: ?string, error?: string
 * }
 */
function sc_run_slaac(string $prefix, string $seed): array
{
    if ($prefix === '') {
        return [];
    }
    $seed_arg = ($seed === '') ? null : $seed;
    try {
        $r = slaac_privacy_address($prefix, $seed_arg);
    } catch (\InvalidArgumentException $e) {
        return ['error' => $e->getMessage()];
    }
    return [
        'prefix'            => $r['prefix'],
        'address'           => $r['address'],
        'interface_id'      => $r['interface_id'],
        'seed_used'         => $r['seed_used'],
        'seed_was_provided' => $r['seed_was_provided'],
        // 'warning' reserved for future use (callers may surface "seed is a
        // documentation/example value" hints). Currently always null.
        'warning'           => null,
    ];
}

// ─── IPv6 reverse-DNS helper (shared by POST handler and GET shareable URL) ─

/**
 * Run ipv6_to_arpa() and return an associative array with keys:
 * address, prefix, arpa, error. Empty input returns []. (v3.4.0 Task 8)
 *
 * @return array{address?: string, prefix?: int, arpa?: string, error?: string}
 */
function sc_run_rdns6(string $address, string $prefix_input): array
{
    if ($address === '') {
        return [];
    }
    $prefix = null;
    if ($prefix_input !== '') {
        if (preg_match('/^-?\d+$/', $prefix_input) !== 1) {
            return ['error' => 'Prefix must be an integer 0..128 (multiple of 4).'];
        }
        $prefix = (int) $prefix_input;
    }
    try {
        $arpa = ipv6_to_arpa($address, $prefix);
    } catch (\InvalidArgumentException $e) {
        return ['error' => $e->getMessage()];
    }
    return [
        'address' => $address,
        'prefix'  => $prefix ?? 128,
        'arpa'    => $arpa,
    ];
}

// ─── IPv4-mapped / NAT64 helper (shared by POST handler and GET shareable URL) ─

/**
 * Run mapped6 conversions against any of the three supported input forms
 * (IPv4, IPv4-mapped IPv6, NAT64 IPv6 within the supplied /96 prefix) and
 * return all four representations. Empty input returns []. (v3.4.0 Task 9)
 *
 * @param array<string,mixed> $input
 *
 * @return array{
 *     input?: string,
 *     ipv4?: string,
 *     ipv4_mapped?: string,
 *     nat64?: string,
 *     nat64_prefix?: string,
 *     error?: string|null
 * }
 */
function sc_run_mapped6(array $input): array
{
    $raw    = trim((string)($input['input'] ?? ''));
    $prefix = trim((string)($input['nat64_prefix'] ?? ''));
    if ($prefix === '') {
        $prefix = NAT64_DEFAULT_PREFIX;
    }
    if ($raw === '') {
        return [];
    }
    try {
        if (filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $v4     = $raw;
            $mapped = ipv4_to_mapped($v4);
            $nat64  = ipv4_to_nat64($v4, $prefix);
        } elseif (is_ipv4_mapped($raw)) {
            $v4     = mapped_to_ipv4($raw);
            $mapped = $raw;
            $nat64  = ipv4_to_nat64($v4, $prefix);
        } elseif (is_nat64($raw, $prefix)) {
            $v4     = nat64_to_ipv4($raw, $prefix);
            $mapped = ipv4_to_mapped($v4);
            $nat64  = $raw;
        } else {
            return [
                'input'        => $raw,
                'nat64_prefix' => $prefix,
                'error'        => 'Input is not IPv4, IPv4-mapped IPv6, or NAT64 IPv6 within the supplied prefix.',
            ];
        }
    } catch (\InvalidArgumentException $e) {
        return [
            'input'        => $raw,
            'nat64_prefix' => $prefix,
            'error'        => $e->getMessage(),
        ];
    }
    return [
        'input'        => $raw,
        'ipv4'         => $v4,
        'ipv4_mapped'  => $mapped,
        'nat64'        => $nat64,
        'nat64_prefix' => $prefix,
        'error'        => null,
    ];
}

// ─── NAT64 / DNS64 helper (RFC 6052 / 6147), v3.5.0 Task 7 ──────────────────

/**
 * Run a NAT64 (RFC 6052) embed/extract or DNS64 (RFC 6147) synthesis.
 * Mirrors the request-shape of sc_run_6rd / sc_run_mapped6 so the same
 * helper handles both POST and GET shareable URLs.
 *
 * @return array{
 *     mode?: 'encode'|'decode'|'dns64',
 *     nat64_prefix?: string,
 *     prefix_length?: int,
 *     ipv4?: string,
 *     ipv6?: string,
 *     error?: string|null
 * }
 */
function sc_run_nat64(
    string $mode,
    string $nat64_prefix,
    string $prefix_length_str,
    string $ipv4_input,
    string $ipv6_input,
    string $a_record_input
): array {
    $mode = strtolower(trim($mode));
    if ($mode !== 'encode' && $mode !== 'decode' && $mode !== 'dns64') {
        $mode = 'encode';
    }

    $prefix = trim($nat64_prefix);
    if ($prefix === '') {
        $prefix = NAT64_WELL_KNOWN_PREFIX;
    }

    $plRaw = trim($prefix_length_str);
    $pl    = $plRaw === '' ? 96 : (int) $plRaw;

    // Empty inputs → no calculation (fresh form).
    if ($mode === 'encode' && trim($ipv4_input) === '') {
        return [];
    }
    if ($mode === 'decode' && trim($ipv6_input) === '') {
        return [];
    }
    if ($mode === 'dns64' && trim($a_record_input) === '') {
        return [];
    }

    try {
        if ($mode === 'encode') {
            $v4   = trim($ipv4_input);
            $ipv6 = nat64_embed($v4, $prefix, $pl);
            return [
                'mode'          => 'encode',
                'nat64_prefix'  => $prefix,
                'prefix_length' => $pl,
                'ipv4'          => $v4,
                'ipv6'          => $ipv6,
                'error'         => null,
            ];
        }
        if ($mode === 'decode') {
            $addr = trim($ipv6_input);
            $v4   = nat64_extract($addr, $prefix, $pl);
            return [
                'mode'          => 'decode',
                'nat64_prefix'  => $prefix,
                'prefix_length' => $pl,
                'ipv6'          => $addr,
                'ipv4'          => $v4,
                'error'         => null,
            ];
        }
        // dns64
        $v4   = trim($a_record_input);
        $ipv6 = dns64_synthesize($v4, $prefix, $pl);
        return [
            'mode'          => 'dns64',
            'nat64_prefix'  => $prefix,
            'prefix_length' => $pl,
            'ipv4'          => $v4,
            'ipv6'          => $ipv6,
            'error'         => null,
        ];
    } catch (\InvalidArgumentException $e) {
        return [
            'mode'          => $mode,
            'nat64_prefix'  => $prefix,
            'prefix_length' => $pl,
            'error'         => $e->getMessage(),
        ];
    }
}

// ─── Embedded-IPv4 detector helper (shared by POST handler and GET shareable URL) ─

/**
 * Run detect_embedded_v4() against the supplied IPv6 address and return an
 * associative array with keys: input, scheme, ipv4, deprecated, detail_route,
 * extra, error. Empty input early-returns []. (v3.5.0 Task 2)
 *
 * @return array{
 *     input?: string,
 *     scheme?: ?string,
 *     ipv4?: ?string,
 *     deprecated?: bool,
 *     detail_route?: ?string,
 *     extra?: array<string,mixed>,
 *     error?: string|null
 * }
 */
function sc_run_embedded_v4(string $address): array
{
    $raw = trim($address);
    if ($raw === '') {
        return [];
    }
    try {
        $detection = detect_embedded_v4($raw);
    } catch (\InvalidArgumentException $e) {
        return [
            'input' => $raw,
            'error' => $e->getMessage(),
        ];
    }
    return [
        'input'        => $raw,
        'scheme'       => $detection['scheme'],
        'ipv4'         => $detection['ipv4'],
        'deprecated'   => $detection['deprecated'],
        'detail_route' => $detection['detail_route'],
        'extra'        => $detection['extra'],
        'error'        => null,
    ];
}

// ─── Multicast scope decoder helper (shared by POST handler and GET shareable URL) ─

/**
 * Run decode_multicast() against the supplied IPv6 multicast address and
 * return an associative array suitable for direct rendering in the
 * multicast drawer. Empty input early-returns []. (v3.6.0 Task 2, #396)
 *
 * @return array{
 *     input?: string,
 *     address?: string,
 *     scope?: int,
 *     scope_name?: string,
 *     flags?: int,
 *     transient?: bool,
 *     prefix_based?: bool,
 *     embedded_rp?: bool,
 *     group_id?: string,
 *     scheme?: string,
 *     detail_route?: string|null,
 *     well_known?: array{name: string, rfc: string}|null,
 *     error?: string|null
 * }
 */
function sc_run_multicast6(string $address): array
{
    $raw = trim($address);
    if ($raw === '') {
        return [];
    }
    try {
        $r = decode_multicast($raw);
    } catch (\InvalidArgumentException $e) {
        return [
            'input' => $raw,
            'error' => $e->getMessage(),
        ];
    }
    return [
        'input'        => $raw,
        'address'      => $r['address'],
        'scope'        => $r['scope'],
        'scope_name'   => $r['scope_name'],
        'flags'        => $r['flags'],
        'transient'    => $r['transient'],
        'prefix_based' => $r['prefix_based'],
        'embedded_rp'  => $r['embedded_rp'],
        'group_id'     => $r['group_id'],
        'scheme'       => $r['scheme'],
        'detail_route' => $r['detail_route'],
        'well_known'   => $r['well_known'],
        'error'        => null,
    ];
}

// ─── SSM (RFC 3306) helper (shared by POST handler and GET shareable URL) ──

/**
 * Run the SSM / unicast-prefix-based multicast tool against the supplied
 * inputs. Mode is either 'encode' (unicast prefix + scope + group_id →
 * FF3x:: address) or 'decode' (FF3x:: address → unicast prefix + scope +
 * group_id). Empty input returns []. (v3.6.0 Task 3, #398)
 *
 * @return array{
 *     mode?: 'encode'|'decode',
 *     input?: string,
 *     address?: string,
 *     scope?: int,
 *     prefix_length?: int,
 *     unicast_prefix?: string,
 *     group_id?: int,
 *     unicast_prefix_input?: string,
 *     scope_input?: string,
 *     group_id_input?: string,
 *     error?: string|null
 * }
 */
function sc_run_ssm6(
    string $mode,
    string $unicast_prefix_input,
    string $scope_input,
    string $group_id_input,
    string $ipv6_input
): array {
    $mode = strtolower(trim($mode));
    if ($mode !== 'encode' && $mode !== 'decode') {
        $mode = 'encode';
    }

    if ($mode === 'encode') {
        $up = trim($unicast_prefix_input);
        $sc = trim($scope_input);
        $gi = trim($group_id_input);
        if ($up === '' && $sc === '' && $gi === '') {
            return [];
        }
        if (!ctype_digit($sc)) {
            return [
                'mode'                 => 'encode',
                'unicast_prefix_input' => $up,
                'scope_input'          => $sc,
                'group_id_input'       => $gi,
                'error'                => 'Scope must be an integer 1..15.',
            ];
        }
        // group_id may be supplied in decimal or 0x-hex form for human convenience.
        $gi_int = sc_ssm6_parse_group_id($gi);
        if ($gi_int === null) {
            return [
                'mode'                 => 'encode',
                'unicast_prefix_input' => $up,
                'scope_input'          => $sc,
                'group_id_input'       => $gi,
                'error'                => 'Group ID must be an integer 0..2^32-1 (decimal or 0xHEX).',
            ];
        }
        try {
            $r = build_ssm_group($up, (int)$sc, $gi_int);
            return [
                'mode'                 => 'encode',
                'unicast_prefix_input' => $up,
                'scope_input'          => $sc,
                'group_id_input'       => $gi,
                'address'              => $r['address'],
                'scope'                => $r['scope'],
                'prefix_length'        => $r['prefix_length'],
                'unicast_prefix'       => $r['unicast_prefix'],
                'group_id'             => $r['group_id'],
                'error'                => null,
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'mode'                 => 'encode',
                'unicast_prefix_input' => $up,
                'scope_input'          => $sc,
                'group_id_input'       => $gi,
                'error'                => $e->getMessage(),
            ];
        }
    }

    // decode
    $raw = trim($ipv6_input);
    if ($raw === '') {
        return [];
    }
    try {
        $r = decode_ssm_group($raw);
        return [
            'mode'           => 'decode',
            'input'          => $raw,
            'address'        => $r['address'],
            'scope'          => $r['scope'],
            'prefix_length'  => $r['prefix_length'],
            'unicast_prefix' => $r['unicast_prefix'],
            'group_id'       => $r['group_id'],
            'error'          => null,
        ];
    } catch (\InvalidArgumentException $e) {
        return [
            'mode'  => 'decode',
            'input' => $raw,
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * Run the embedded-RP multicast tool against the supplied input. Mode is
 * 'encode' (RP address + prefix length + RIID + scope + group ID →
 * FF7x:: embedded-RP group) or 'decode' (FF7x:: group → parts). Empty
 * input returns []. (v3.6.0 Task 4, RFC 3956, #397)
 *
 * @return array{
 *     mode?: 'encode'|'decode',
 *     input?: string,
 *     address?: string,
 *     scope?: int,
 *     rp_prefix?: string,
 *     rp_prefix_length?: int,
 *     rp_address?: string,
 *     riid?: int,
 *     group_id?: int,
 *     rp_address_input?: string,
 *     rp_prefix_length_input?: string,
 *     riid_input?: string,
 *     scope_input?: string,
 *     group_id_input?: string,
 *     error?: string|null
 * }
 */
function sc_run_embedded_rp6(
    string $mode,
    string $rp_address_input,
    string $rp_prefix_length_input,
    string $riid_input,
    string $scope_input,
    string $group_id_input,
    string $ipv6_input
): array {
    $mode = strtolower(trim($mode));
    if ($mode !== 'encode' && $mode !== 'decode') {
        $mode = 'encode';
    }

    if ($mode === 'encode') {
        $rp = trim($rp_address_input);
        $pl = trim($rp_prefix_length_input);
        $ri = trim($riid_input);
        $sc = trim($scope_input);
        $gi = trim($group_id_input);
        if ($rp === '' && $pl === '' && $ri === '' && $gi === '') {
            return [];
        }
        $base_echo = [
            'mode'                   => 'encode',
            'rp_address_input'       => $rp,
            'rp_prefix_length_input' => $pl,
            'riid_input'             => $ri,
            'scope_input'            => $sc,
            'group_id_input'         => $gi,
        ];
        if (!ctype_digit($pl)) {
            return $base_echo + ['error' => 'RP prefix length must be an integer 0..64.'];
        }
        if (!ctype_digit($ri)) {
            return $base_echo + ['error' => 'RIID must be an integer 0..15.'];
        }
        if (!ctype_digit($sc)) {
            return $base_echo + ['error' => 'Scope must be an integer 1..15.'];
        }
        $gi_int = sc_ssm6_parse_group_id($gi);
        if ($gi_int === null) {
            return $base_echo + ['error' => 'Group ID must be an integer 0..2^32-1 (decimal or 0xHEX).'];
        }
        try {
            $r = build_embedded_rp_group($rp, (int)$pl, (int)$ri, (int)$sc, $gi_int);
            return $base_echo + [
                'address'          => $r['address'],
                'scope'            => $r['scope'],
                'rp_prefix'        => $r['rp_prefix'],
                'rp_prefix_length' => $r['rp_prefix_length'],
                'rp_address'       => $r['rp_address'],
                'riid'             => $r['riid'],
                'group_id'         => $r['group_id'],
                'error'            => null,
            ];
        } catch (\InvalidArgumentException $e) {
            return $base_echo + ['error' => $e->getMessage()];
        }
    }

    // decode
    $raw = trim($ipv6_input);
    if ($raw === '') {
        return [];
    }
    try {
        $r = decode_embedded_rp_group($raw);
        return [
            'mode'             => 'decode',
            'input'            => $raw,
            'address'          => $r['address'],
            'scope'            => $r['scope'],
            'rp_prefix'        => $r['rp_prefix'],
            'rp_prefix_length' => $r['rp_prefix_length'],
            'rp_address'       => $r['rp_address'],
            'riid'             => $r['riid'],
            'group_id'         => $r['group_id'],
            'error'            => null,
        ];
    } catch (\InvalidArgumentException $e) {
        return [
            'mode'  => 'decode',
            'input' => $raw,
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * Run the IPv6 PMTU helper against the supplied inputs. Computes the
 * fragmentation breakdown for `payload_size` bytes over a path with the
 * given `path_mtu` and optional list of extension-header sizes.
 * Empty inputs return []. (v3.6.0 Task 5, RFC 8200, #399)
 *
 * @return array{
 *     path_mtu_input?: string,
 *     payload_size_input?: string,
 *     extension_headers_input?: string,
 *     result?: array{
 *         path_mtu: int,
 *         meets_minimum: bool,
 *         fixed_header: int,
 *         extension_overhead: int,
 *         total_overhead: int,
 *         effective_payload: int,
 *         payload_size: int,
 *         needs_fragmentation: bool,
 *         fragment_count: int,
 *         fragments: list<array{offset: int, m_bit: int, payload_bytes: int}>,
 *         notes: list<string>
 *     },
 *     error?: string|null
 * }
 */
function sc_run_pmtu6(
    string $path_mtu_input,
    string $payload_size_input,
    string $extension_headers_input
): array {
    $pm = trim($path_mtu_input);
    $ps = trim($payload_size_input);
    $eh = trim($extension_headers_input);
    if ($pm === '' && $ps === '' && $eh === '') {
        return [];
    }
    $base_echo = [
        'path_mtu_input'          => $pm,
        'payload_size_input'      => $ps,
        'extension_headers_input' => $eh,
    ];
    if (!ctype_digit($pm) || (int)$pm <= 0) {
        return $base_echo + ['error' => 'Path MTU must be a positive integer.'];
    }
    if (!ctype_digit($ps)) {
        return $base_echo + ['error' => 'Payload size must be a non-negative integer.'];
    }
    $ext_list = [];
    if ($eh !== '') {
        $parts = array_map('trim', explode(',', $eh));
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (!ctype_digit($part)) {
                return $base_echo + [
                    'error' => 'Each extension-header size must be a positive integer multiple of 8 bytes.',
                ];
            }
            $ext_list[] = (int)$part;
        }
    }
    try {
        $r = pmtu_compute((int)$pm, (int)$ps, $ext_list);
        return $base_echo + ['result' => $r, 'error' => null];
    } catch (\InvalidArgumentException $e) {
        return $base_echo + ['error' => $e->getMessage()];
    }
}

/**
 * Parse a user-supplied group_id. Accepts decimal or 0x-hex.
 * Returns null on parse failure or out-of-range value.
 *
 * @internal
 */
function sc_ssm6_parse_group_id(string $raw): ?int
{
    $s = strtolower(trim($raw));
    if ($s === '') {
        return null;
    }
    if (str_starts_with($s, '0x')) {
        $hex = substr($s, 2);
        if ($hex === '' || !ctype_xdigit($hex)) {
            return null;
        }
        if (strlen($hex) > 8) {
            return null;
        }
        return (int)hexdec($hex);
    }
    if (!ctype_digit($s)) {
        return null;
    }
    // PHP_INT_MAX on 64-bit is comfortably above 2^32, so int cast is safe here.
    $n = (int)$s;
    if ($n < 0 || $n > 0xFFFFFFFF) {
        return null;
    }
    return $n;
}

// ─── 6to4 helper (shared by POST handler and GET shareable URL) ─────────────

/**
 * Run the 6to4 tool against the supplied input. Mode is either 'encode'
 * (IPv4 → 2002:WWXX:YYZZ::/48) or 'decode' (6to4 IPv6 → embedded IPv4 +
 * subnet ID + interface ID). Empty input returns []. (v3.5.0 Task 3)
 *
 * @return array{
 *     mode?: 'encode'|'decode',
 *     input?: string,
 *     prefix?: string,
 *     ipv4?: string,
 *     subnet_id?: int,
 *     interface_id?: string,
 *     error?: string|null
 * }
 */
function sc_run_6to4(string $input, string $mode): array
{
    $raw  = trim($input);
    $mode = strtolower(trim($mode));
    if ($mode !== 'encode' && $mode !== 'decode') {
        $mode = 'encode';
    }
    if ($raw === '') {
        return [];
    }
    try {
        if ($mode === 'encode') {
            return [
                'mode'   => 'encode',
                'input'  => $raw,
                'prefix' => ipv4_to_6to4($raw),
                'error'  => null,
            ];
        }
        $decoded = decode_6to4($raw);
        return [
            'mode'         => 'decode',
            'input'        => $raw,
            'ipv4'         => $decoded['ipv4'],
            'subnet_id'    => $decoded['subnet_id'],
            'interface_id' => $decoded['interface_id'],
            'error'        => null,
        ];
    } catch (\InvalidArgumentException $e) {
        return [
            'mode'  => $mode,
            'input' => $raw,
            'error' => $e->getMessage(),
        ];
    }
}

// ─── Teredo helper (shared by POST handler and GET shareable URL) ──────────

/**
 * Run the Teredo tool against the supplied input. Mode is either 'decode'
 * (Teredo IPv6 → server v4 + client v4 + port + flags) or 'encode'
 * (server v4 + client v4 + port + flags → Teredo IPv6). Empty input
 * returns []. (v3.5.0 Task 4)
 *
 * @return array{
 *     mode?: 'encode'|'decode',
 *     input?: string,
 *     ipv6?: string,
 *     server_ipv4?: string,
 *     client_ipv4?: string,
 *     port?: int,
 *     flags?: int,
 *     cone?: bool,
 *     error?: string|null
 * }
 */
function sc_run_teredo(
    string $mode,
    string $ipv6_input,
    string $server_input,
    string $client_input,
    string $port_input,
    string $flags_input,
    bool $cone_flag
): array {
    $mode = strtolower(trim($mode));
    if ($mode !== 'encode' && $mode !== 'decode') {
        $mode = 'decode';
    }

    if ($mode === 'decode') {
        $raw = trim($ipv6_input);
        if ($raw === '') {
            return [];
        }
        try {
            $r = decode_teredo($raw);
            return [
                'mode'        => 'decode',
                'input'       => $raw,
                'ipv6'        => $raw,
                'server_ipv4' => $r['server_ipv4'],
                'client_ipv4' => $r['client_ipv4'],
                'port'        => $r['port'],
                'flags'       => $r['flags'],
                'cone'        => $r['cone'],
                'error'       => null,
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'mode'  => 'decode',
                'input' => $raw,
                'error' => $e->getMessage(),
            ];
        }
    }

    // Encode mode
    $server = trim($server_input);
    $client = trim($client_input);
    $portRaw  = trim($port_input);
    $flagsRaw = trim($flags_input);

    // Empty-state: no parts supplied yet.
    if ($server === '' && $client === '' && $portRaw === '') {
        return [];
    }

    if ($portRaw === '' || !ctype_digit($portRaw)) {
        return [
            'mode'  => 'encode',
            'error' => 'Port must be a non-negative integer between 0 and 65535.',
        ];
    }
    $port = (int)$portRaw;

    // Flags input optional. Accept decimal or 0xNNNN. Default to cone or
    // non-cone based on the boolean toggle.
    if ($flagsRaw === '') {
        $flags = $cone_flag ? 0x8000 : 0x0000;
    } else {
        if (preg_match('/^0x[0-9a-f]{1,4}$/i', $flagsRaw)) {
            $flags = (int)hexdec(substr($flagsRaw, 2));
        } elseif (ctype_digit($flagsRaw)) {
            $flags = (int)$flagsRaw;
        } else {
            return [
                'mode'  => 'encode',
                'error' => 'Flags must be a 16-bit integer (decimal or 0xNNNN).',
            ];
        }
        if ($flags < 0 || $flags > 0xFFFF) {
            return [
                'mode'  => 'encode',
                'error' => 'Flags must be in the 16-bit range (0..65535).',
            ];
        }
    }

    try {
        $addr = encode_teredo($server, $client, $port, $flags);
        return [
            'mode'        => 'encode',
            'server_ipv4' => $server,
            'client_ipv4' => $client,
            'port'        => $port,
            'flags'       => $flags,
            'cone'        => ($flags & 0x8000) !== 0,
            'ipv6'        => $addr,
            'error'       => null,
        ];
    } catch (\InvalidArgumentException $e) {
        return [
            'mode'        => 'encode',
            'server_ipv4' => $server,
            'client_ipv4' => $client,
            'port'        => $port,
            'flags'       => $flags,
            'error'       => $e->getMessage(),
        ];
    }
}

// ─── ISATAP helper (shared by POST handler and GET shareable URL) ─────────

/**
 * Run the ISATAP tool against the supplied input. Mode is either 'encode'
 * (IPv4 → ISATAP IID) or 'decode' (IID → IPv4). Empty input returns [].
 * (v3.5.0 Task 5)
 *
 * @return array{
 *     mode?: 'encode'|'decode',
 *     input?: string,
 *     ipv4?: string,
 *     iid?: string,
 *     globally_unique?: bool,
 *     error?: string|null
 * }
 */
function sc_run_isatap(
    string $mode,
    string $ipv4_input,
    string $iid_input,
    string $globally_unique_input
): array {
    $mode = strtolower(trim($mode));
    if ($mode !== 'encode' && $mode !== 'decode') {
        $mode = 'encode';
    }

    if ($mode === 'encode') {
        $raw = trim($ipv4_input);
        if ($raw === '') {
            return [];
        }
        $gu = null;
        $guRaw = strtolower(trim($globally_unique_input));
        if ($guRaw === 'true' || $guRaw === '1' || $guRaw === 'yes') {
            $gu = true;
        } elseif ($guRaw === 'false' || $guRaw === '0' || $guRaw === 'no') {
            $gu = false;
        }
        try {
            $iid = ipv4_to_isatap_iid($raw, $gu);
            $decoded = decode_isatap_iid($iid);
            return [
                'mode'            => 'encode',
                'input'           => $raw,
                'ipv4'            => $raw,
                'iid'             => $iid,
                'globally_unique' => $decoded['globally_unique'],
                'error'           => null,
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'mode'  => 'encode',
                'input' => $raw,
                'error' => $e->getMessage(),
            ];
        }
    }

    // decode mode
    $raw = trim($iid_input);
    if ($raw === '') {
        return [];
    }
    try {
        $r = decode_isatap_iid($raw);
        return [
            'mode'            => 'decode',
            'input'           => $raw,
            'iid'             => $raw,
            'ipv4'            => $r['ipv4'],
            'globally_unique' => $r['globally_unique'],
            'error'           => null,
        ];
    } catch (\InvalidArgumentException $e) {
        return [
            'mode'  => 'decode',
            'input' => $raw,
            'error' => $e->getMessage(),
        ];
    }
}

// ─── 6rd helper (shared by POST handler and GET shareable URL) ──────────────

/**
 * Run the 6rd tool against the supplied input. Mode is either 'encode'
 * (customer IPv4 + SP params → delegated IPv6 prefix) or 'decode' (6rd
 * IPv6 address + SP params → customer IPv4). Empty required input
 * returns []. (v3.5.0 Task 6)
 *
 * @return array{
 *     mode?: 'encode'|'decode',
 *     sp_ipv6_prefix?: string,
 *     sp_ipv4_mask_len?: int,
 *     ipv4?: string,
 *     ipv6?: string,
 *     prefix?: string,
 *     prefix_length?: int,
 *     error?: string|null
 * }
 */
function sc_run_6rd(
    string $mode,
    string $sp_prefix_input,
    string $mask_len_input,
    string $ipv4_input,
    string $ipv6_input
): array {
    $mode = strtolower(trim($mode));
    if ($mode !== 'encode' && $mode !== 'decode') {
        $mode = 'encode';
    }

    $sp_prefix = trim($sp_prefix_input);
    $mask_raw  = trim($mask_len_input);
    $mask_len  = 0;
    if ($mask_raw !== '') {
        if (!ctype_digit($mask_raw)) {
            return [
                'mode'             => $mode,
                'sp_ipv6_prefix'   => $sp_prefix,
                'sp_ipv4_mask_len' => 0,
                'error'            => 'SP IPv4 mask length must be an integer 0..32.',
            ];
        }
        $mask_len = (int) $mask_raw;
        if ($mask_len < 0 || $mask_len > 32) {
            return [
                'mode'             => $mode,
                'sp_ipv6_prefix'   => $sp_prefix,
                'sp_ipv4_mask_len' => $mask_len,
                'error'            => 'SP IPv4 mask length must be 0..32.',
            ];
        }
    }

    if ($mode === 'encode') {
        $v4 = trim($ipv4_input);
        if ($sp_prefix === '' || $v4 === '') {
            return [];
        }
        try {
            $r = compute_6rd_delegation($sp_prefix, $mask_len, $v4);
            return [
                'mode'             => 'encode',
                'sp_ipv6_prefix'   => $sp_prefix,
                'sp_ipv4_mask_len' => $mask_len,
                'ipv4'             => $v4,
                'prefix'           => $r['prefix'],
                'prefix_length'    => $r['prefix_length'],
                'error'            => null,
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'mode'             => 'encode',
                'sp_ipv6_prefix'   => $sp_prefix,
                'sp_ipv4_mask_len' => $mask_len,
                'ipv4'             => $v4,
                'error'            => $e->getMessage(),
            ];
        }
    }

    // decode mode
    $addr = trim($ipv6_input);
    if ($sp_prefix === '' || $addr === '') {
        return [];
    }
    try {
        $v4 = extract_6rd_ipv4($sp_prefix, $mask_len, $addr);
        return [
            'mode'             => 'decode',
            'sp_ipv6_prefix'   => $sp_prefix,
            'sp_ipv4_mask_len' => $mask_len,
            'ipv6'             => $addr,
            'ipv4'             => $v4,
            'error'            => null,
        ];
    } catch (\InvalidArgumentException $e) {
        return [
            'mode'             => 'decode',
            'sp_ipv6_prefix'   => $sp_prefix,
            'sp_ipv4_mask_len' => $mask_len,
            'ipv6'             => $addr,
            'error'            => $e->getMessage(),
        ];
    }
}

// ─── Prefix-delegation planner helper (v3.5.0 Task 8) ──────────────────────

/**
 * Plan an IPv6 prefix-delegation slice. Empty/blank inputs early-return [].
 * Shared by the POST handler and the GET shareable-URL hydration path.
 *
 * @return array{
 *     parent_prefix?: string,
 *     child_length_input?: string,
 *     count_input?: string,
 *     start_offset_input?: string,
 *     nibble_align?: bool,
 *     result?: array{
 *         parent: array{prefix: string, length: int, total_children_str: string},
 *         children: list<array{index: int, prefix: string, first: string, last: string, contains_64s: string}>,
 *         free: array{remaining_str: string},
 *         normalized_child_length: int,
 *     },
 *     error?: string|null
 * }
 */
function sc_run_prefix_plan6(
    string $parent_prefix_input,
    string $child_length_input,
    string $count_input,
    string $start_offset_input,
    bool $nibble_align
): array {
    $parent = trim($parent_prefix_input);
    $clen   = trim($child_length_input);
    $cnt    = trim($count_input);
    $off    = trim($start_offset_input);

    if ($parent === '' && $clen === '' && $cnt === '') {
        return [];
    }

    $base = [
        'parent_prefix'      => $parent,
        'child_length_input' => $clen,
        'count_input'        => $cnt,
        'start_offset_input' => $off,
        'nibble_align'       => $nibble_align,
    ];

    if ($parent === '' || $clen === '' || $cnt === '') {
        return $base + ['error' => 'Parent prefix, child length, and count are all required.'];
    }
    if (!ctype_digit($clen)) {
        return $base + ['error' => 'Child length must be an integer.'];
    }
    if (!ctype_digit($cnt)) {
        return $base + ['error' => 'Count must be a positive integer.'];
    }
    if ($off !== '' && !ctype_digit($off)) {
        return $base + ['error' => 'Start offset must be a non-negative integer.'];
    }
    $offset = $off === '' ? 0 : (int) $off;

    try {
        $r = plan_prefix_delegation(
            $parent,
            (int) $clen,
            (int) $cnt,
            $offset,
            $nibble_align
        );
        return $base + ['result' => $r, 'error' => null];
    } catch (\InvalidArgumentException $e) {
        return $base + ['error' => $e->getMessage()];
    }
}

// ─── Nibble6 helper (shared by POST handler and GET shareable URL) ──────────

/**
 * Run nibble_neighbours() against a single user-supplied prefix and return
 * an associative array mirroring sc_run_prefix_plan6(): empty on no input,
 * `error` on failure, `result` on success. Shared by the POST handler and
 * the GET shareable-URL hydration path. (v3.5.0 Task 9)
 *
 * @return array{
 *     prefix_input?: string,
 *     result?: array{
 *         input: array{prefix: string, length: int},
 *         above: array{length: int, prefix: string, contains_64s: string},
 *         below: array{length: int, prefix: string, contains_64s: string},
 *     },
 *     error?: string|null
 * }
 */
function sc_run_nibble6(string $prefix_input): array
{
    $prefix = trim($prefix_input);
    if ($prefix === '') {
        return [];
    }
    $base = ['prefix_input' => $prefix];
    try {
        $r = nibble_neighbours($prefix);
        return $base + ['result' => $r, 'error' => null];
    } catch (\InvalidArgumentException $e) {
        return $base + ['error' => $e->getMessage()];
    }
}

// ─── RFC 3531 helper (shared by POST handler and GET shareable URL) ─────────

/**
 * Run rfc3531_apply() against raw user inputs and return an associative
 * array mirroring sc_run_prefix_plan6(): empty on no input, `error` on
 * failure, `result` on success. Shared by the POST handler and the GET
 * shareable-URL hydration path. (v3.5.0 Task 10)
 *
 * @return array{
 *     parent_prefix?: string,
 *     reservation_bits_input?: string,
 *     strategy?: string,
 *     result?: array{
 *         parent: array{prefix: string, length: int},
 *         strategy: string,
 *         reservation_bits: int,
 *         allocation_order: list<int>,
 *         children: list<array{order: int, value: int, prefix: string}>
 *     },
 *     error?: string|null
 * }
 */
function sc_run_rfc3531(
    string $parent_prefix_input,
    string $reservation_bits_input,
    string $strategy_input
): array {
    $parent   = trim($parent_prefix_input);
    $bits     = trim($reservation_bits_input);
    $strategy = trim($strategy_input);
    if ($strategy === '') {
        $strategy = 'centermost';
    }

    if ($parent === '' && $bits === '') {
        return [];
    }

    $base = [
        'parent_prefix'          => $parent,
        'reservation_bits_input' => $bits,
        'strategy'               => $strategy,
    ];

    if ($parent === '' || $bits === '') {
        return $base + ['error' => 'Parent prefix and reservation bits are both required.'];
    }
    if (!ctype_digit($bits)) {
        return $base + ['error' => 'Reservation bits must be a positive integer.'];
    }

    try {
        $r = rfc3531_apply($parent, (int) $bits, $strategy);
        return $base + ['result' => $r, 'error' => null];
    } catch (\InvalidArgumentException $e) {
        return $base + ['error' => $e->getMessage()];
    }
}

// ─── Diff helper (shared by POST handler and GET shareable URL) ──────────────

/**
 * Run subnet_diff() against the given raw textarea inputs and return an
 * associative array with keys: result (with added/removed/unchanged/changed
 * lists), error. Empty inputs early-return with [] so empty-state defaults
 * apply. Shared by the POST handler and the GET shareable-URL hydration path.
 * (v3.4.0 — flat → array)
 *
 * @return array{
 *     result?: array{
 *         added: list<string>, removed: list<string>, unchanged: list<string>,
 *         changed: list<array{from: string, to: string, reason: string}>
 *     },
 *     error?: string
 * }
 */
function sc_run_diff(
    string $before_input,
    string $after_input,
    int $max_entries = 1000
): array {
    $before_lines = array_values(array_filter(array_map('trim', explode("\n", $before_input))));
    $after_lines  = array_values(array_filter(array_map('trim', explode("\n", $after_input))));
    if ($before_lines === [] && $after_lines === []) {
        return ['error' => 'At least one CIDR is required in either Before or After.'];
    }
    if (count($before_lines) > $max_entries) {
        return ['error' => 'Too many CIDRs in Before (max ' . $max_entries . ').'];
    }
    if (count($after_lines) > $max_entries) {
        return ['error' => 'Too many CIDRs in After (max ' . $max_entries . ').'];
    }
    try {
        return ['result' => subnet_diff($before_lines, $after_lines)];
    } catch (\InvalidArgumentException $e) {
        return ['error' => $e->getMessage()];
    }
}

// ─── Request handling ─────────────────────────────────────────────────────────

$get_tab    = $_GET['tab'] ?? $default_tab;
$active_tab = in_array($get_tab, ['ipv4', 'ipv6', 'vlsm', 'vlsm6'], true) ? $get_tab : 'ipv4';

// v3.4.0 (#382) — per-tool URL routes. Apache rewrites /ipv4/<tool> and
// /ipv6/<tool> to index.php?tab=…&tool=…; we accept ?tool= as a fallback
// signal to auto-open the matching tool drawer when no other GET param
// (e.g. derive_mac, supernet_action) implies a tool. Legacy
// ?tab=&tool= URLs work the same way as the rewritten path. Whitelist
// keeps any garbage out of the template's data-open-tool attribute.
$get_tool       = (string)($_GET['tool'] ?? '');
$requested_tool = preg_match('/^[a-z0-9-]{1,32}$/', $get_tool) === 1 ? $get_tool : '';

// v3.4.0 — when the URL is /ipv4/<tool> or /ipv6/<tool>, the browser sees
// the longer path and resolves all relative URLs (assets/app.js,
// assets/app.css, ?tab=ipv6 nav links, share-bar copy targets, …) against
// it, breaking everything. Compute the real app base path by stripping
// the /ipv[46](/<tool>)? suffix from REQUEST_URI; the template emits a
// <base href> so relative URLs resolve to the app root regardless of
// which canonical URL the user landed on.
$_req_path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$app_base_path = preg_replace(
    '#/(ipv4|ipv6)(/[a-z0-9-]+)?/?$#',
    '/',
    (string)$_req_path
);
unset($_req_path);

$result = $error = null;
$input_ip = $input_mask = '';

$result6 = $error6 = null;
$input_ipv6 = $input_prefix = '';

$input_split_prefix  = '';
$input_split_prefix6 = '';
/** @var array{result?: list<string>, error?: string} */
$splitter = [];
/** @var array{result?: list<string>, error?: string} */
$splitter6 = [];

$overlap_cidr_a = $overlap_cidr_b = '';
/** @var array{result?: string, error?: string} */
$overlap = [];

$multi_overlap_input = '';
/** @var array{result?: array<array{a: string, b: string, relation: string}>, error?: string} */
$multi_overlap = [];

$vlsm_network = $vlsm_cidr_input = '';
/** @var array<array{name: string, hosts: int}> $vlsm_requirements */
$vlsm_requirements = [];
/** @var array{result?: list<array{name: string, hosts_needed: int, subnet: string, usable: int}>, error?: string} */
$vlsm = [];

$vlsm6_network = $vlsm6_cidr_input = '';
/** @var array<array{name: string, hosts: int|string}> $vlsm6_requirements */
$vlsm6_requirements = [];
/** @var array{result?: list<array{name: string, hosts_needed: int|string, subnet: string, usable: int|string}>, error?: string} */
$vlsm6 = [];

$supernet_input  = '';
$supernet_action = '';
/** @var array{result?: array{supernet?: string, summaries?: string[]}, error?: string} */
$supernet = [];

// v3.3.0 — IPv6 supernet / summarise (supernet6)
$supernet6_input  = '';
$supernet6_action = '';
/** @var array{result?: array{supernet?: string, summaries?: string[]}, error?: string} */
$supernet6 = [];

// v3.3.0 — IPv6 zone-ID parser
$zoneid_input = '';
/** @var array{address?: string, zone_id?: ?string, is_link_local?: bool, warning?: ?string, error?: string} */
$zoneid = [];

// v3.3.0 — MAC → IPv6 derivation tool (derive)
$derive_input = '';
/** @var array{mac_canonical?: string, eui64?: string, ul_bit_flipped?: bool, link_local?: string, solicited_node?: string, warning?: ?string, error?: string} */
$derive = [];

// v3.3.0 — SLAAC privacy address generator (RFC 8981)
$slaac_prefix_input = '';
$slaac_seed_input   = '';
/** @var array{prefix?: string, address?: string, interface_id?: string, seed_used?: ?string, seed_was_provided?: bool, warning?: ?string, error?: string} */
$slaac = [];

// v3.4.0 Task 8 — IPv6 reverse-DNS (rdns6)
$rdns6_address_input = '';
$rdns6_prefix_input  = '';
/** @var array{address?: string, prefix?: int, arpa?: string, error?: string} */
$rdns6 = [];

// v3.4.0 Task 9 — IPv4-mapped / NAT64 (mapped6)
$mapped6_input        = '';
$mapped6_prefix_input = '';
/** @var array{input?: string, ipv4?: string, ipv4_mapped?: string, nat64?: string, nat64_prefix?: string, error?: string|null} */
$mapped6 = [];

// v3.5.0 Task 7 — NAT64 / DNS64 (RFC 6052 / 6147), shares mapped6 drawer
$nat64_mode             = 'encode';
$nat64_prefix_input     = '';
$nat64_pl_input         = '';
$nat64_ipv4_input       = '';
$nat64_ipv6_input       = '';
$nat64_a_record_input   = '';
/** @var array{mode?: 'encode'|'decode'|'dns64', nat64_prefix?: string, prefix_length?: int, ipv4?: string, ipv6?: string, error?: string|null} */
$nat64 = [];

// v3.5.0 Task 2 — IPv6 embedded-v4 detector (front door)
$embedded_v4_input = '';
/** @var array{input?: string, scheme?: ?string, ipv4?: ?string, deprecated?: bool, detail_route?: ?string, extra?: array<string,mixed>, error?: string|null} */
$embedded_v4 = [];

// v3.5.0 Task 3 — 6to4 address tool (RFC 3056)
$sixtofour_input = '';
$sixtofour_mode  = 'encode';
/** @var array{mode?: 'encode'|'decode', input?: string, prefix?: string, ipv4?: string, subnet_id?: int, interface_id?: string, error?: string|null} */
$sixtofour = [];

// v3.5.0 Task 4 — Teredo address decoder (RFC 4380)
$teredo_mode         = 'decode';
$teredo_input        = '';
$teredo_server_input = '';
$teredo_client_input = '';
$teredo_port_input   = '';
$teredo_flags_input  = '';
$teredo_cone_flag    = true;
/** @var array{mode?: 'encode'|'decode', input?: string, ipv6?: string, server_ipv4?: string, client_ipv4?: string, port?: int, flags?: int, cone?: bool, error?: string|null} */
$teredo = [];

// v3.5.0 Task 5 — ISATAP interface-ID helper (RFC 5214)
$isatap_mode             = 'encode';
$isatap_ipv4_input       = '';
$isatap_iid_input        = '';
$isatap_globally_unique  = '';
/** @var array{mode?: 'encode'|'decode', input?: string, ipv4?: string, iid?: string, globally_unique?: bool, error?: string|null} */
$isatap = [];

// v3.5.0 Task 6 — 6rd address tool (RFC 5969)
$sixrd_mode             = 'encode';
$sixrd_sp_prefix_input  = '';
$sixrd_mask_len_input   = '';
$sixrd_ipv4_input       = '';
$sixrd_ipv6_input       = '';
/** @var array{mode?: 'encode'|'decode', sp_ipv6_prefix?: string, sp_ipv4_mask_len?: int, ipv4?: string, ipv6?: string, prefix?: string, prefix_length?: int, error?: string|null} */
$sixrd = [];

// v3.5.0 Task 9 — IPv6 nibble-boundary helper
$nibble6_prefix_input = '';
/** @var array{prefix_input?: string, result?: array{input: array{prefix: string, length: int}, above: array{length: int, prefix: string, contains_64s: string}, below: array{length: int, prefix: string, contains_64s: string}}, error?: string|null} */
$nibble6 = [];

// v3.5.0 Task 8 — IPv6 prefix-delegation planner
$prefix_plan6_parent_input        = '';
$prefix_plan6_child_length_input  = '';
$prefix_plan6_count_input         = '';
$prefix_plan6_start_offset_input  = '';
$prefix_plan6_nibble_align        = true;
/** @var array{parent_prefix?: string, child_length_input?: string, count_input?: string, start_offset_input?: string, nibble_align?: bool, result?: array{parent: array{prefix: string, length: int, total_children_str: string}, children: list<array{index: int, prefix: string, first: string, last: string, contains_64s: string}>, free: array{remaining_str: string}, normalized_child_length: int}, error?: string|null} */
$prefix_plan6 = [];

// v3.6.0 Task 2 — IPv6 multicast scope decoder (front door for multicast tools)
$multicast6_input = '';
/** @var array{input?: string, address?: string, scope?: int, scope_name?: string, flags?: int, transient?: bool, prefix_based?: bool, embedded_rp?: bool, group_id?: string, scheme?: string, detail_route?: string|null, well_known?: array{name: string, rfc: string}|null, error?: string|null} */
$multicast6 = [];

// v3.6.0 Task 3 — SSM / unicast-prefix-based multicast (RFC 3306, #398)
$ssm6_mode                 = 'encode';
$ssm6_unicast_prefix_input = '';
$ssm6_scope_input          = '14'; // default 0xE (global)
$ssm6_group_id_input        = '';
$ssm6_ipv6_input           = '';
/** @var array{mode?: 'encode'|'decode', input?: string, address?: string, scope?: int, prefix_length?: int, unicast_prefix?: string, group_id?: int, unicast_prefix_input?: string, scope_input?: string, group_id_input?: string, error?: string|null} */
$ssm6 = [];

// v3.6.0 Task 4 — Embedded-RP multicast (RFC 3956, #397)
$embedded_rp6_mode                   = 'encode';
$embedded_rp6_rp_address_input       = '';
$embedded_rp6_rp_prefix_length_input = '';
$embedded_rp6_riid_input             = '';
$embedded_rp6_scope_input            = '14'; // default 0xE (global)
$embedded_rp6_group_id_input         = '';
$embedded_rp6_ipv6_input             = '';
/** @var array{mode?: 'encode'|'decode', input?: string, address?: string, scope?: int, rp_prefix?: string, rp_prefix_length?: int, rp_address?: string, riid?: int, group_id?: int, rp_address_input?: string, rp_prefix_length_input?: string, riid_input?: string, scope_input?: string, group_id_input?: string, error?: string|null} */
$embedded_rp6 = [];

// v3.6.0 Task 5 — IPv6 PMTU + fragmentation helper (RFC 8200, #399)
$pmtu6_path_mtu_input          = '';
$pmtu6_payload_size_input      = '';
$pmtu6_extension_headers_input = '';
/** @var array{path_mtu_input?: string, payload_size_input?: string, extension_headers_input?: string, result?: array{path_mtu: int, meets_minimum: bool, fixed_header: int, extension_overhead: int, total_overhead: int, effective_payload: int, payload_size: int, needs_fragmentation: bool, fragment_count: int, fragments: list<array{offset: int, m_bit: int, payload_bytes: int}>, notes: list<string>}, error?: string|null} */
$pmtu6 = [];

// v3.5.0 Task 10 — RFC 3531 sparse-allocation guidance
$rfc3531_parent_input           = '';
$rfc3531_reservation_bits_input = '';
$rfc3531_strategy_input         = 'centermost';
/** @var array{parent_prefix?: string, reservation_bits_input?: string, strategy?: string, result?: array{parent: array{prefix: string, length: int}, strategy: string, reservation_bits: int, allocation_order: list<int>, children: list<array{order: int, value: int, prefix: string}>}, error?: string|null} */
$rfc3531 = [];

$ula_global_id_input = '';
/** @var array{result?: array{prefix?: string, global_id?: string, example_64s?: string[], available_64s?: int}, error?: string} */
$ula = [];

$session_save_id  = '';
$session_load_id  = '';
$session_error    = null;

$range_start = '';
$range_end   = '';
/** @var array{result?: list<string>, error?: string} */
$range = [];

// v3.3.0 — IPv6 range → CIDR (range6)
$range6_start = '';
$range6_end   = '';
/** @var array{result?: list<string>, error?: string, warning?: string, count?: ?int, total?: int|string|null} */
$range6 = [];

$tree_parent   = '';
$tree_children = '';
/** @var array{result?: array<string, mixed>, error?: string} */
$tree = [];

$wildcard_input = '';
/** @var array{result?: array{cidr: string, wildcard: string}, error?: string} */
$wildcard = [];

$lookup_cidrs_input = '';
$lookup_ips_input   = '';
/** @var array{result?: list<array{ip: string, matches: list<string>, deepest: string|null}>, error?: string} */
$lookup = [];

$diff_before_input = '';
$diff_after_input  = '';
/** @var array{result?: array{added: list<string>, removed: list<string>, unchanged: list<string>, changed: list<array{from: string, to: string, reason: string}>}, error?: string} */
$diff = [];

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
    $is_supernet6     = isset($_POST['supernet6_action']);
    $is_ula           = isset($_POST['ula_generate']);
    $is_session_save  = isset($_POST['session_action']) && (string)($_POST['session_action'] ?? '') === 'save';
    $is_range         = isset($_POST['range_start']) || isset($_POST['range_end']);
    $is_range6        = isset($_POST['range6_start']) || isset($_POST['range6_end']);
    $is_tree          = isset($_POST['tree_parent']);
    $is_wildcard      = isset($_POST['wildcard_input']);
    $is_lookup        = isset($_POST['lookup_cidrs']) || isset($_POST['lookup_ips']);
    $is_diff          = isset($_POST['diff_before']) || isset($_POST['diff_after']);
    $is_zoneid        = isset($_POST['zoneid_input']);
    $is_derive        = isset($_POST['derive_mac']);
    $is_slaac         = isset($_POST['slaac_prefix']);
    $is_rdns6         = isset($_POST['rdns6_address']);
    $is_mapped6       = isset($_POST['mapped6_input']);
    $is_embedded_v4   = isset($_POST['embedded_v4_input']);
    $is_sixtofour     = isset($_POST['sixtofour_input']);
    $is_teredo        = isset($_POST['teredo_mode'])
        || isset($_POST['teredo_input'])
        || isset($_POST['teredo_server'])
        || isset($_POST['teredo_client'])
        || isset($_POST['teredo_port']);
    $is_isatap        = isset($_POST['isatap_mode'])
        || isset($_POST['isatap_ipv4'])
        || isset($_POST['isatap_iid']);
    $is_sixrd         = isset($_POST['sixrd_mode'])
        || isset($_POST['sixrd_sp_prefix'])
        || isset($_POST['sixrd_ipv4'])
        || isset($_POST['sixrd_ipv6']);
    $is_nat64         = isset($_POST['nat64_mode'])
        || isset($_POST['nat64_ipv4'])
        || isset($_POST['nat64_ipv6'])
        || isset($_POST['nat64_a_record']);
    $is_prefix_plan6  = isset($_POST['prefix_plan6_parent'])
        || isset($_POST['prefix_plan6_child_length'])
        || isset($_POST['prefix_plan6_count']);
    $is_nibble6       = isset($_POST['nibble6_prefix']);
    $is_rfc3531       = isset($_POST['rfc3531_parent'])
        || isset($_POST['rfc3531_reservation_bits']);
    $is_multicast6    = isset($_POST['multicast6_input']);
    $is_ssm6          = isset($_POST['ssm6_mode'])
        || isset($_POST['ssm6_unicast_prefix'])
        || isset($_POST['ssm6_scope'])
        || isset($_POST['ssm6_group_id'])
        || isset($_POST['ssm6_ipv6']);
    $is_embedded_rp6  = isset($_POST['embedded_rp6_mode'])
        || isset($_POST['embedded_rp6_rp_address'])
        || isset($_POST['embedded_rp6_rp_prefix_length'])
        || isset($_POST['embedded_rp6_riid'])
        || isset($_POST['embedded_rp6_scope'])
        || isset($_POST['embedded_rp6_group_id'])
        || isset($_POST['embedded_rp6_ipv6']);
    $is_pmtu6         = isset($_POST['pmtu6_path_mtu'])
        || isset($_POST['pmtu6_payload_size'])
        || isset($_POST['pmtu6_extension_headers']);

    // Tool drawers (splitter/overlap/vlsm/vlsm6/supernet/ula/session/range/tree/wildcard/lookup/diff)
    // bypass honeypot/CAPTCHA gates because they're follow-on actions in an already-loaded session,
    // not entry-point form posts. Only the main IPv4/IPv6 calculator forms are gated.
    $is_tool = $is_splitter || $is_overlap || $is_multi_overlap || $is_vlsm
        || $is_vlsm6 || $is_supernet || $is_supernet6 || $is_ula || $is_session_save || $is_range
        || $is_range6 || $is_tree || $is_wildcard || $is_lookup || $is_diff || $is_zoneid
        || $is_derive || $is_slaac || $is_rdns6 || $is_mapped6 || $is_embedded_v4
        || $is_sixtofour || $is_teredo || $is_isatap || $is_sixrd || $is_nat64
        || $is_prefix_plan6
        || $is_nibble6
        || $is_rfc3531
        || $is_multicast6
        || $is_ssm6
        || $is_embedded_rp6
        || $is_pmtu6;

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
                $splitter = ['error' => 'New prefix must be between 1 and 32.'];
            } else {
                $new_pfx      = (int)$sp;
                $current_cidr = (int)ltrim($result['netmask_cidr'], '/');
                $network_ip   = explode('/', $result['network_cidr'])[0];
                if ($new_pfx <= $current_cidr) {
                    $splitter = ['error' => 'New prefix must be larger than /' . $current_cidr . '.'];
                } else {
                    $splitter = ['result' => split_subnet($network_ip, $current_cidr, $new_pfx, $split_max_subnets)];
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
                $splitter6 = ['error' => 'New prefix must be between 1 and 128.'];
            } else {
                $new_pfx6     = (int)$sp6;
                $current_pfx6 = (int)ltrim($result6['prefix'], '/');
                $network_ipv6 = explode('/', $result6['network_cidr'])[0];
                if ($new_pfx6 <= $current_pfx6) {
                    $splitter6 = ['error' => 'New prefix must be larger than /' . $current_pfx6 . '.'];
                } else {
                    $splitter6 = [
                        'result' => split_subnet6($network_ipv6, $current_pfx6, $new_pfx6, $split_max_subnets),
                    ];
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
            $overlap = ['error' => 'Cannot compare IPv4 and IPv6 addresses.'];
        } elseif ($a_is_v6) {
            if (!extension_loaded('gmp')) {
                $overlap = ['error' => 'IPv6 overlap check requires the PHP GMP extension.'];
            } else {
                [$a_ip, $a_pfx] = array_pad(explode('/', $overlap_cidr_a, 2), 2, '');
                [$b_ip, $b_pfx] = array_pad(explode('/', $overlap_cidr_b, 2), 2, '');
                $a_ip  = trim($a_ip);
                $a_pfx = trim($a_pfx);
                $b_ip  = trim($b_ip);
                $b_pfx = trim($b_pfx);
                if (!is_valid_ipv6($a_ip) || !ctype_digit($a_pfx) || (int)$a_pfx > 128) {
                    $overlap = ['error' => 'First subnet: Invalid IPv6 CIDR.'];
                } elseif (!is_valid_ipv6($b_ip) || !ctype_digit($b_pfx) || (int)$b_pfx > 128) {
                    $overlap = ['error' => 'Second subnet: Invalid IPv6 CIDR.'];
                } else {
                    try {
                        $r6a = calculate_subnet6($a_ip, (int)$a_pfx);
                        $r6b = calculate_subnet6($b_ip, (int)$b_pfx);
                        $overlap = ['result' => cidrs_overlap6($r6a['network_cidr'], $r6b['network_cidr'])];
                    } catch (\Exception $e) {
                        error_log('sc IPv6 overlap error: ' . $e->getMessage());
                        $overlap = ['error' => 'An error occurred during calculation. Please check your input.'];
                    }
                }
            }
        } else {
            $ra = resolve_ipv4_input($overlap_cidr_a, '');
            $rb = resolve_ipv4_input($overlap_cidr_b, '');
            if (!$ra['result']) {
                $overlap = ['error' => 'First subnet: ' . ($ra['error'] ?? 'Invalid CIDR.')];
            } elseif (!$rb['result']) {
                $overlap = ['error' => 'Second subnet: ' . ($rb['error'] ?? 'Invalid CIDR.')];
            } else {
                $overlap = ['result' => cidrs_overlap($ra['result']['network_cidr'], $rb['result']['network_cidr'])];
            }
        }
    }

    if ($is_multi_overlap && !$form_blocked) {
        $multi_overlap_input = trim((string)($_POST['multi_overlap_input'] ?? ''));
        $raw_lines = array_filter(array_map('trim', explode("\n", $multi_overlap_input)));
        $lines = array_values($raw_lines);
        if (count($lines) < 2) {
            $multi_overlap = ['error' => 'Enter at least two CIDRs (one per line).'];
        } elseif (count($lines) > 50) {
            $multi_overlap = ['error' => 'Maximum 50 CIDRs per check.'];
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
                $multi_overlap = ['error' => $multi_err];
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
                    $multi_overlap = ['error' => 'Cannot mix IPv4 and IPv6 CIDRs.'];
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
                    $multi_overlap = ['result' => $conflicts];
                }
            }
        }
    }

    if ($is_vlsm && !$form_blocked) {
        $vlsm_network   = trim((string)($_POST['vlsm_network'] ?? ''));
        $vlsm_cidr_input = trim((string)($_POST['vlsm_cidr']   ?? ''));
        $rv = resolve_ipv4_input($vlsm_network, $vlsm_cidr_input);
        if (!$rv['result']) {
            $vlsm['error'] = 'Parent network: ' . ($rv['error'] ?? 'Invalid input.');
        } else {
            $names  = $_POST['vlsm_name']  ?? [];
            $hosts  = $_POST['vlsm_hosts'] ?? [];
            if (!is_array($names) || !is_array($hosts) || count($names) === 0) {
                $vlsm['error'] = 'Add at least one requirement.';
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
                    $vlsm['error'] = 'Add at least one valid requirement.';
                } else {
                    $vlsm_cidr_int   = (int)ltrim($rv['result']['netmask_cidr'], '/');
                    $vlsm_network_ip = explode('/', $rv['result']['network_cidr'])[0];
                    $vlsm_requirements = $reqs;
                    $vr = vlsm_allocate($vlsm_network_ip, $vlsm_cidr_int, $reqs);
                    if (isset($vr['error'])) {
                        $vlsm['error'] = $vr['error'];
                    } else {
                        $vlsm['result'] = $vr['allocations'] ?? [];
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
            $vlsm6['error'] = 'Parent network: ' . ($rv6['error6'] ?? 'Invalid input.');
        } else {
            $names6 = $_POST['vlsm6_name']  ?? [];
            $hosts6 = $_POST['vlsm6_hosts'] ?? [];
            if (!is_array($names6) || !is_array($hosts6) || count($names6) === 0) {
                $vlsm6['error'] = 'Add at least one requirement.';
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
                    $vlsm6['error'] = 'Each requirement name must be 100 characters or fewer.';
                } elseif ($reqs6 === []) {
                    $vlsm6['error'] = 'Add at least one valid requirement.';
                } else {
                    $vlsm6_cidr_int   = (int)ltrim($rv6['result6']['prefix'], '/');
                    $vlsm6_network_ip = explode('/', $rv6['result6']['network_cidr'])[0];
                    $vlsm6_requirements = $reqs6;
                    $vr6 = vlsm6_allocate($vlsm6_network_ip, $vlsm6_cidr_int, $reqs6);
                    if (isset($vr6['error'])) {
                        $vlsm6['error'] = $vr6['error'];
                    } else {
                        $vlsm6['result'] = $vr6['allocations'] ?? [];
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
            $supernet = ['error' => 'Enter at least one CIDR.'];
        } elseif (count($lines) > 50) {
            $supernet = ['error' => 'Maximum 50 CIDRs per check.'];
        } else {
            $sr = $supernet_action === 'find' ? supernet_find($lines) : summarise_cidrs($lines);
            if (isset($sr['error'])) {
                $supernet = ['error' => $sr['error']];
            } else {
                $supernet = ['result' => $sr];
            }
        }
    }

    if ($is_supernet6 && !$form_blocked) {
        $active_tab = 'ipv6';
        $supernet6_action = in_array((string)($_POST['supernet6_action'] ?? ''), ['find', 'summarise'], true)
            ? (string)$_POST['supernet6_action']
            : 'find';
        $supernet6_input  = trim((string)($_POST['supernet6_input'] ?? ''));
        $lines6 = array_values(array_filter(array_map('trim', explode("\n", $supernet6_input))));
        if (count($lines6) < 1) {
            $supernet6 = ['error' => 'Enter at least one CIDR.'];
        } elseif (count($lines6) > 50) {
            $supernet6 = ['error' => 'Maximum 50 CIDRs per check.'];
        } else {
            $sr6 = $supernet6_action === 'find' ? supernet6_find($lines6) : summarise6_cidrs($lines6);
            if (isset($sr6['error'])) {
                $supernet6 = ['error' => $sr6['error']];
            } else {
                $supernet6 = ['result' => $sr6];
            }
        }
    }

    if ($is_zoneid && !$form_blocked) {
        $active_tab = 'ipv6';
        $zoneid_input = trim((string)($_POST['zoneid_input'] ?? ''));
        $zoneid = sc_run_zoneid($zoneid_input);
    }

    if ($is_derive && !$form_blocked) {
        $active_tab = 'ipv6';
        $derive_input = trim((string)($_POST['derive_mac'] ?? ''));
        $derive = sc_run_derive($derive_input);
    }

    if ($is_slaac && !$form_blocked) {
        $active_tab = 'ipv6';
        $slaac_prefix_input = trim((string)($_POST['slaac_prefix'] ?? ''));
        $slaac_seed_input   = trim((string)($_POST['slaac_seed']   ?? ''));
        $slaac = sc_run_slaac($slaac_prefix_input, $slaac_seed_input);
    }

    if ($is_rdns6 && !$form_blocked) {
        $active_tab = 'ipv6';
        $rdns6_address_input = trim((string)($_POST['rdns6_address'] ?? ''));
        $rdns6_prefix_input  = trim((string)($_POST['rdns6_prefix']  ?? ''));
        $rdns6 = sc_run_rdns6($rdns6_address_input, $rdns6_prefix_input);
    }

    if ($is_mapped6 && !$form_blocked) {
        $active_tab = 'ipv6';
        $mapped6_input        = trim((string)($_POST['mapped6_input']        ?? ''));
        $mapped6_prefix_input = trim((string)($_POST['mapped6_nat64_prefix'] ?? ''));
        $mapped6 = sc_run_mapped6([
            'input'        => $mapped6_input,
            'nat64_prefix' => $mapped6_prefix_input,
        ]);
    }

    if ($is_embedded_v4 && !$form_blocked) {
        $active_tab = 'ipv6';
        $embedded_v4_input = trim((string)($_POST['embedded_v4_input'] ?? ''));
        $embedded_v4 = sc_run_embedded_v4($embedded_v4_input);
    }

    if ($is_sixtofour && !$form_blocked) {
        $active_tab = 'ipv6';
        $sixtofour_input = trim((string)($_POST['sixtofour_input'] ?? ''));
        $sixtofour_mode  = (string)($_POST['sixtofour_mode'] ?? 'encode');
        if ($sixtofour_mode !== 'encode' && $sixtofour_mode !== 'decode') {
            $sixtofour_mode = 'encode';
        }
        $sixtofour = sc_run_6to4($sixtofour_input, $sixtofour_mode);
    }

    if ($is_teredo && !$form_blocked) {
        $active_tab = 'ipv6';
        $teredo_mode         = (string)($_POST['teredo_mode'] ?? 'decode');
        if ($teredo_mode !== 'encode' && $teredo_mode !== 'decode') {
            $teredo_mode = 'decode';
        }
        $teredo_input        = trim((string)($_POST['teredo_input']  ?? ''));
        $teredo_server_input = trim((string)($_POST['teredo_server'] ?? ''));
        $teredo_client_input = trim((string)($_POST['teredo_client'] ?? ''));
        $teredo_port_input   = trim((string)($_POST['teredo_port']   ?? ''));
        $teredo_flags_input  = trim((string)($_POST['teredo_flags']  ?? ''));
        $teredo_cone_flag    = isset($_POST['teredo_cone']);
        $teredo = sc_run_teredo(
            $teredo_mode,
            $teredo_input,
            $teredo_server_input,
            $teredo_client_input,
            $teredo_port_input,
            $teredo_flags_input,
            $teredo_cone_flag
        );
    }

    if ($is_isatap && !$form_blocked) {
        $active_tab = 'ipv6';
        $isatap_mode = (string)($_POST['isatap_mode'] ?? 'encode');
        if ($isatap_mode !== 'encode' && $isatap_mode !== 'decode') {
            $isatap_mode = 'encode';
        }
        $isatap_ipv4_input      = trim((string)($_POST['isatap_ipv4'] ?? ''));
        $isatap_iid_input       = trim((string)($_POST['isatap_iid']  ?? ''));
        $isatap_globally_unique = (string)($_POST['isatap_globally_unique'] ?? '');
        $isatap = sc_run_isatap(
            $isatap_mode,
            $isatap_ipv4_input,
            $isatap_iid_input,
            $isatap_globally_unique
        );
    }

    if ($is_sixrd && !$form_blocked) {
        $active_tab = 'ipv6';
        $sixrd_mode = (string)($_POST['sixrd_mode'] ?? 'encode');
        if ($sixrd_mode !== 'encode' && $sixrd_mode !== 'decode') {
            $sixrd_mode = 'encode';
        }
        $sixrd_sp_prefix_input = trim((string)($_POST['sixrd_sp_prefix'] ?? ''));
        $sixrd_mask_len_input  = trim((string)($_POST['sixrd_mask_len']  ?? ''));
        $sixrd_ipv4_input      = trim((string)($_POST['sixrd_ipv4']      ?? ''));
        $sixrd_ipv6_input      = trim((string)($_POST['sixrd_ipv6']      ?? ''));
        $sixrd = sc_run_6rd(
            $sixrd_mode,
            $sixrd_sp_prefix_input,
            $sixrd_mask_len_input,
            $sixrd_ipv4_input,
            $sixrd_ipv6_input
        );
    }

    if ($is_nat64 && !$form_blocked) {
        $active_tab = 'ipv6';
        $nat64_mode = (string)($_POST['nat64_mode'] ?? 'encode');
        if ($nat64_mode !== 'encode' && $nat64_mode !== 'decode' && $nat64_mode !== 'dns64') {
            $nat64_mode = 'encode';
        }
        $nat64_prefix_input   = trim((string)($_POST['nat64_prefix']    ?? ''));
        $nat64_pl_input       = trim((string)($_POST['nat64_pl']        ?? ''));
        $nat64_ipv4_input     = trim((string)($_POST['nat64_ipv4']      ?? ''));
        $nat64_ipv6_input     = trim((string)($_POST['nat64_ipv6']      ?? ''));
        $nat64_a_record_input = trim((string)($_POST['nat64_a_record']  ?? ''));
        $nat64 = sc_run_nat64(
            $nat64_mode,
            $nat64_prefix_input,
            $nat64_pl_input,
            $nat64_ipv4_input,
            $nat64_ipv6_input,
            $nat64_a_record_input
        );
    }

    if ($is_nibble6 && !$form_blocked) {
        $active_tab = 'ipv6';
        $nibble6_prefix_input = trim((string)($_POST['nibble6_prefix'] ?? ''));
        $nibble6              = sc_run_nibble6($nibble6_prefix_input);
    }

    if ($is_rfc3531 && !$form_blocked) {
        $active_tab = 'ipv6';
        $rfc3531_parent_input           = trim((string)($_POST['rfc3531_parent']           ?? ''));
        $rfc3531_reservation_bits_input = trim((string)($_POST['rfc3531_reservation_bits'] ?? ''));
        $rfc3531_strategy_input         = trim((string)($_POST['rfc3531_strategy']         ?? 'centermost'));
        $rfc3531 = sc_run_rfc3531(
            $rfc3531_parent_input,
            $rfc3531_reservation_bits_input,
            $rfc3531_strategy_input
        );
    }

    if ($is_multicast6 && !$form_blocked) {
        $active_tab = 'ipv6';
        $multicast6_input = trim((string)($_POST['multicast6_input'] ?? ''));
        $multicast6 = sc_run_multicast6($multicast6_input);
    }

    if ($is_ssm6 && !$form_blocked) {
        $active_tab = 'ipv6';
        $ssm6_mode = (string)($_POST['ssm6_mode'] ?? 'encode');
        if ($ssm6_mode !== 'encode' && $ssm6_mode !== 'decode') {
            $ssm6_mode = 'encode';
        }
        $ssm6_unicast_prefix_input = trim((string)($_POST['ssm6_unicast_prefix'] ?? ''));
        $ssm6_scope_input          = trim((string)($_POST['ssm6_scope'] ?? '14'));
        $ssm6_group_id_input       = trim((string)($_POST['ssm6_group_id'] ?? ''));
        $ssm6_ipv6_input           = trim((string)($_POST['ssm6_ipv6'] ?? ''));
        $ssm6 = sc_run_ssm6(
            $ssm6_mode,
            $ssm6_unicast_prefix_input,
            $ssm6_scope_input,
            $ssm6_group_id_input,
            $ssm6_ipv6_input
        );
    }

    if ($is_embedded_rp6 && !$form_blocked) {
        $active_tab = 'ipv6';
        $embedded_rp6_mode = (string)($_POST['embedded_rp6_mode'] ?? 'encode');
        if ($embedded_rp6_mode !== 'encode' && $embedded_rp6_mode !== 'decode') {
            $embedded_rp6_mode = 'encode';
        }
        $embedded_rp6_rp_address_input       = trim((string)($_POST['embedded_rp6_rp_address']       ?? ''));
        $embedded_rp6_rp_prefix_length_input = trim((string)($_POST['embedded_rp6_rp_prefix_length'] ?? ''));
        $embedded_rp6_riid_input             = trim((string)($_POST['embedded_rp6_riid']             ?? ''));
        $embedded_rp6_scope_input            = trim((string)($_POST['embedded_rp6_scope']            ?? '14'));
        $embedded_rp6_group_id_input         = trim((string)($_POST['embedded_rp6_group_id']         ?? ''));
        $embedded_rp6_ipv6_input             = trim((string)($_POST['embedded_rp6_ipv6']             ?? ''));
        $embedded_rp6 = sc_run_embedded_rp6(
            $embedded_rp6_mode,
            $embedded_rp6_rp_address_input,
            $embedded_rp6_rp_prefix_length_input,
            $embedded_rp6_riid_input,
            $embedded_rp6_scope_input,
            $embedded_rp6_group_id_input,
            $embedded_rp6_ipv6_input
        );
    }

    if ($is_pmtu6 && !$form_blocked) {
        $active_tab = 'ipv6';
        $pmtu6_path_mtu_input          = trim((string)($_POST['pmtu6_path_mtu']          ?? ''));
        $pmtu6_payload_size_input      = trim((string)($_POST['pmtu6_payload_size']      ?? ''));
        $pmtu6_extension_headers_input = trim((string)($_POST['pmtu6_extension_headers'] ?? ''));
        $pmtu6 = sc_run_pmtu6(
            $pmtu6_path_mtu_input,
            $pmtu6_payload_size_input,
            $pmtu6_extension_headers_input
        );
    }

    if ($is_prefix_plan6 && !$form_blocked) {
        $active_tab = 'ipv6';
        $prefix_plan6_parent_input       = trim((string)($_POST['prefix_plan6_parent']       ?? ''));
        $prefix_plan6_child_length_input = trim((string)($_POST['prefix_plan6_child_length'] ?? ''));
        $prefix_plan6_count_input        = trim((string)($_POST['prefix_plan6_count']        ?? ''));
        $prefix_plan6_start_offset_input = trim((string)($_POST['prefix_plan6_start_offset'] ?? ''));
        $prefix_plan6_nibble_align       = isset($_POST['prefix_plan6_nibble_align']);
        $prefix_plan6 = sc_run_prefix_plan6(
            $prefix_plan6_parent_input,
            $prefix_plan6_child_length_input,
            $prefix_plan6_count_input,
            $prefix_plan6_start_offset_input,
            $prefix_plan6_nibble_align
        );
    }

    if ($is_ula && !$form_blocked) {
        $ula_global_id_input = trim((string)($_POST['ula_global_id'] ?? ''));
        $ur = generate_ula_prefix($ula_global_id_input);
        if (isset($ur['error'])) {
            $ula = ['error' => $ur['error']];
        } else {
            $ula = ['result' => $ur];
        }
    }

    if ($is_session_save && !$form_blocked && $session_enabled) {
        // v3.0.0 (#315) — POST distinguishes ipv4 vs ipv6 sessions via the
        // session_type hidden input on the IPv6 VLSM tab. Default 'ipv4' keeps
        // back-compat with the original single-tab Save Session button.
        $session_type_in = (string)($_POST['session_type'] ?? 'ipv4');
        if (!in_array($session_type_in, ['ipv4', 'ipv6'], true)) {
            $session_type_in = 'ipv4';
        }

        if ($session_type_in === 'ipv6') {
            $vlsm6_network    = trim((string)($_POST['vlsm6_network'] ?? ''));
            $vlsm6_cidr_input = trim((string)($_POST['vlsm6_cidr']   ?? ''));
            $rv6 = resolve_ipv6_input($vlsm6_network, $vlsm6_cidr_input);
            if (!$rv6['result6']) {
                $session_error = 'Parent network: ' . ($rv6['error6'] ?? 'Invalid input.');
            } else {
                $names6 = $_POST['vlsm6_name']  ?? [];
                $hosts6 = $_POST['vlsm6_hosts'] ?? [];
                $reqs6  = [];
                if (is_array($names6) && is_array($hosts6)) {
                    foreach ($names6 as $i => $name) {
                        $name = mb_substr(trim((string)$name), 0, 100);
                        $hval = trim((string)($hosts6[$i] ?? ''));
                        if ($name === '' || $hval === '') {
                            continue;
                        }
                        if (ctype_digit($hval) && (int)$hval >= 1) {
                            $reqs6[] = ['name' => $name, 'hosts' => (int)$hval];
                        } elseif (preg_match('/^2\^([0-9]|[1-9][0-9]|1[01][0-9]|12[0-8])$/', $hval)) {
                            // Preserve the "2^N" string form for sizings that overflow int64.
                            $reqs6[] = ['name' => $name, 'hosts' => $hval];
                        }
                    }
                }
                if ($reqs6 === []) {
                    $session_error = 'No valid IPv6 VLSM requirements to save.';
                } else {
                    $vlsm6_cidr_int   = (int)ltrim($rv6['result6']['prefix'], '/');
                    $vlsm6_network_ip = explode('/', $rv6['result6']['network_cidr'])[0];
                    $vlsm6_requirements = $reqs6;
                    $vr6 = vlsm6_allocate($vlsm6_network_ip, $vlsm6_cidr_int, $reqs6);
                    if (isset($vr6['error'])) {
                        $session_error = $vr6['error'];
                    } else {
                        $vlsm6['result'] = $vr6['allocations'] ?? [];
                        try {
                            $db_path = $session_db_path !== '' ? $session_db_path
                                : dirname(__DIR__) . '/data/sessions.sqlite';
                            $db_dir  = dirname($db_path);
                            if (!is_dir($db_dir)) {
                                mkdir($db_dir, 0755, true);
                            }
                            $sdb  = session_db_open($db_path);
                            $session_save_id = session_create($sdb, [
                                'type'         => 'ipv6',
                                'network'      => $vlsm6_network,
                                'cidr'         => ltrim($vlsm6_cidr_input, '/'),
                                'requirements' => $reqs6,
                            ], $session_ttl_days);
                            $sdb->close();
                        } catch (\Exception $e) {
                            error_log('sc session save (ipv6) error: ' . $e->getMessage());
                            $session_error = 'Failed to save session. Please try again.';
                        }
                    }
                }
            }
        } else {
            // IPv4 path — unchanged behaviour. The 'type' field is added
            // explicitly so newly-saved IPv4 sessions are tagged for the v2
            // schema; older payloads remain readable thanks to the default
            // in the load path.
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
                        $vlsm['result'] = $vr['allocations'] ?? [];
                        try {
                            $db_path = $session_db_path !== '' ? $session_db_path
                                : dirname(__DIR__) . '/data/sessions.sqlite';
                            $db_dir  = dirname($db_path);
                            if (!is_dir($db_dir)) {
                                mkdir($db_dir, 0755, true);
                            }
                            $sdb  = session_db_open($db_path);
                            $session_save_id = session_create($sdb, [
                                'type'         => 'ipv4',
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
    }
    if ($is_range && !$form_blocked) {
        $range_start = trim((string)($_POST['range_start'] ?? ''));
        $range_end   = trim((string)($_POST['range_end']   ?? ''));
        if ($range_start === '' || $range_end === '') {
            $range = ['error' => 'Both start and end IP addresses are required.'];
        } else {
            $rr = range_to_cidrs($range_start, $range_end);
            if (isset($rr['error'])) {
                $range = ['error' => $rr['error']];
            } else {
                $range = ['result' => $rr['cidrs'] ?? []];
            }
        }
    }

    if ($is_range6 && !$form_blocked) {
        $active_tab   = 'ipv6';
        $range6_start = trim((string)($_POST['range6_start'] ?? ''));
        $range6_end   = trim((string)($_POST['range6_end']   ?? ''));
        $range6 = sc_run_range6($range6_start, $range6_end);
    }

    if ($is_wildcard && !$form_blocked) {
        $wildcard_input = trim((string)($_POST['wildcard_input'] ?? ''));
        if ($wildcard_input === '') {
            $wildcard = ['error' => 'A CIDR prefix or wildcard mask is required.'];
        } else {
            try {
                if (str_contains($wildcard_input, '.')) {
                    $wildcard = ['result' => [
                        'cidr'     => wildcard_to_cidr($wildcard_input),
                        'wildcard' => $wildcard_input,
                    ]];
                } else {
                    $wildcard = ['result' => [
                        'cidr'     => '/' . ltrim($wildcard_input, '/'),
                        'wildcard' => cidr_to_wildcard($wildcard_input),
                    ]];
                }
            } catch (\InvalidArgumentException $e) {
                $wildcard = ['error' => $e->getMessage()];
            }
        }
    }

    if ($is_lookup && !$form_blocked) {
        $lookup_cidrs_input = (string)($_POST['lookup_cidrs'] ?? '');
        $lookup_ips_input   = (string)($_POST['lookup_ips']   ?? '');
        $lookup = sc_run_lookup(
            $lookup_cidrs_input,
            $lookup_ips_input,
            isset($lookup_max_cidrs) ? (int)$lookup_max_cidrs : 100,
            isset($lookup_max_ips)   ? (int)$lookup_max_ips   : 1000,
        );
    }

    if ($is_diff && !$form_blocked) {
        $diff_before_input = (string)($_POST['diff_before'] ?? '');
        $diff_after_input  = (string)($_POST['diff_after']  ?? '');
        $diff = sc_run_diff($diff_before_input, $diff_after_input);
    }

    if ($is_tree && !$form_blocked) {
        $tree_parent   = trim((string)($_POST['tree_parent']   ?? ''));
        $tree_children = trim((string)($_POST['tree_children'] ?? ''));
        $child_lines   = array_values(array_filter(array_map('trim', explode("\n", $tree_children))));
        if ($tree_parent === '') {
            $tree = ['error' => 'Parent CIDR is required.'];
        } elseif (count($child_lines) > 100) {
            $tree = ['error' => 'Maximum 100 child CIDRs per request.'];
        } else {
            $tr = build_subnet_tree($tree_parent, $child_lines);
            if (isset($tr['error'])) {
                $tree = ['error' => $tr['error']];
            } else {
                $tree = ['result' => $tr['tree'] ?? []];
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Session load: ?tab=vlsm&s=<id>  or  ?tab=vlsm6&s=<id>
    // v3.0.0 (#315) — load path now dispatches on the payload's `type` field
    // (default 'ipv4' for back-compat). The active tab determines which
    // template variables get populated, so an IPv6 session loaded on the
    // ipv4 tab is reported as a tab mismatch rather than silently mis-rendered.
    if ($session_enabled && in_array($active_tab, ['vlsm', 'vlsm6'], true) && isset($_GET['s'])) {
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
                        $payload_type = (string)($payload['type'] ?? 'ipv4');
                        $expected_type = $active_tab === 'vlsm6' ? 'ipv6' : 'ipv4';
                        if ($payload_type !== $expected_type) {
                            $session_error = 'Session is for the '
                                . ($payload_type === 'ipv6' ? 'IPv6' : 'IPv4')
                                . ' VLSM planner — switch tabs to load it.';
                        } elseif ($payload_type === 'ipv6') {
                            $vlsm6_network    = (string)($payload['network'] ?? '');
                            $vlsm6_cidr_input = (string)($payload['cidr']    ?? '');
                            $raw_reqs6        = $payload['requirements'] ?? [];
                            if (is_array($raw_reqs6)) {
                                foreach ($raw_reqs6 as $req) {
                                    if (!is_array($req) || !isset($req['name'], $req['hosts'])) {
                                        continue;
                                    }
                                    $hosts_in = $req['hosts'];
                                    if (is_int($hosts_in) && $hosts_in >= 1) {
                                        $vlsm6_requirements[] = ['name' => (string)$req['name'], 'hosts' => $hosts_in];
                                    } elseif (
                                        is_string($hosts_in)
                                        && preg_match('/^2\^([0-9]|[1-9][0-9]|1[01][0-9]|12[0-8])$/', $hosts_in)
                                    ) {
                                        $vlsm6_requirements[] = ['name' => (string)$req['name'], 'hosts' => $hosts_in];
                                    }
                                }
                            }
                            if ($vlsm6_requirements !== [] && $vlsm6_network !== '') {
                                $rv6 = resolve_ipv6_input($vlsm6_network, $vlsm6_cidr_input);
                                if ($rv6['result6']) {
                                    $vlsm6_cidr_int   = (int)ltrim($rv6['result6']['prefix'], '/');
                                    $vlsm6_network_ip = explode('/', $rv6['result6']['network_cidr'])[0];
                                    $vr6 = vlsm6_allocate($vlsm6_network_ip, $vlsm6_cidr_int, $vlsm6_requirements);
                                    if (isset($vr6['error'])) {
                                        $vlsm6['error'] = $vr6['error'];
                                    } else {
                                        $vlsm6['result'] = $vr6['allocations'] ?? [];
                                    }
                                }
                            }
                        } else {
                            // ipv4 (or pre-v3 untyped payload)
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
                                        $vlsm['error'] = $vr['error'];
                                    } else {
                                        $vlsm['result'] = $vr['allocations'] ?? [];
                                    }
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
        $lookup = sc_run_lookup(
            $lookup_cidrs_input,
            $lookup_ips_input,
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
        $diff = sc_run_diff($diff_before_input, $diff_after_input);
    }

    // IPv6 range → CIDR shareable GET URL (v3.3.0)
    if ($active_tab === 'ipv6' && (isset($_GET['range6_start']) || isset($_GET['range6_end']))) {
        $range6_start = trim((string)($_GET['range6_start'] ?? ''));
        $range6_end   = trim((string)($_GET['range6_end']   ?? ''));
        $range6 = sc_run_range6($range6_start, $range6_end);
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
                $supernet = ['error' => $sr['error']];
            } else {
                $supernet = ['result' => $sr];
            }
        }
    }

    // Zone-ID parser shareable GET URL (v3.3.0)
    if ($active_tab === 'ipv6' && isset($_GET['zoneid_input'])) {
        $zoneid_input = trim((string)($_GET['zoneid_input'] ?? ''));
        $zoneid = sc_run_zoneid($zoneid_input);
    }

    // MAC-derivation tool shareable GET URL (v3.3.0 Task 4)
    if ($active_tab === 'ipv6' && isset($_GET['derive_mac'])) {
        $derive_input = trim((string)($_GET['derive_mac'] ?? ''));
        $derive = sc_run_derive($derive_input);
    }

    // SLAAC privacy address shareable GET URL (v3.3.0 Task 5)
    if ($active_tab === 'ipv6' && isset($_GET['slaac_prefix'])) {
        $slaac_prefix_input = trim((string)($_GET['slaac_prefix'] ?? ''));
        $slaac_seed_input   = trim((string)($_GET['slaac_seed']   ?? ''));
        $slaac = sc_run_slaac($slaac_prefix_input, $slaac_seed_input);
    }

    // IPv6 reverse-DNS shareable GET URL (v3.4.0 Task 8)
    if ($active_tab === 'ipv6' && isset($_GET['rdns6_address'])) {
        $rdns6_address_input = trim((string)($_GET['rdns6_address'] ?? ''));
        $rdns6_prefix_input  = trim((string)($_GET['rdns6_prefix']  ?? ''));
        $rdns6 = sc_run_rdns6($rdns6_address_input, $rdns6_prefix_input);
    }

    // IPv4-mapped / NAT64 shareable GET URL (v3.4.0 Task 9)
    if ($active_tab === 'ipv6' && isset($_GET['mapped6_input'])) {
        $mapped6_input        = trim((string)($_GET['mapped6_input']        ?? ''));
        $mapped6_prefix_input = trim((string)($_GET['mapped6_nat64_prefix'] ?? ''));
        $mapped6 = sc_run_mapped6([
            'input'        => $mapped6_input,
            'nat64_prefix' => $mapped6_prefix_input,
        ]);
    }

    // Embedded-v4 detector shareable GET URL (v3.5.0 Task 2)
    if ($active_tab === 'ipv6' && isset($_GET['embedded_v4_input'])) {
        $embedded_v4_input = trim((string)($_GET['embedded_v4_input'] ?? ''));
        $embedded_v4 = sc_run_embedded_v4($embedded_v4_input);
    }

    // 6to4 shareable GET URL (v3.5.0 Task 3)
    if ($active_tab === 'ipv6' && isset($_GET['sixtofour_input'])) {
        $sixtofour_input = trim((string)($_GET['sixtofour_input'] ?? ''));
        $sixtofour_mode  = (string)($_GET['sixtofour_mode'] ?? 'encode');
        if ($sixtofour_mode !== 'encode' && $sixtofour_mode !== 'decode') {
            $sixtofour_mode = 'encode';
        }
        $sixtofour = sc_run_6to4($sixtofour_input, $sixtofour_mode);
    }

    // Teredo shareable GET URL (v3.5.0 Task 4)
    if (
        $active_tab === 'ipv6'
        && (isset($_GET['teredo_input']) || isset($_GET['teredo_server']))
    ) {
        $teredo_mode = (string)($_GET['teredo_mode'] ?? 'decode');
        if ($teredo_mode !== 'encode' && $teredo_mode !== 'decode') {
            $teredo_mode = 'decode';
        }
        $teredo_input        = trim((string)($_GET['teredo_input']  ?? ''));
        $teredo_server_input = trim((string)($_GET['teredo_server'] ?? ''));
        $teredo_client_input = trim((string)($_GET['teredo_client'] ?? ''));
        $teredo_port_input   = trim((string)($_GET['teredo_port']   ?? ''));
        $teredo_flags_input  = trim((string)($_GET['teredo_flags']  ?? ''));
        $teredo_cone_flag    = isset($_GET['teredo_cone']);
        $teredo = sc_run_teredo(
            $teredo_mode,
            $teredo_input,
            $teredo_server_input,
            $teredo_client_input,
            $teredo_port_input,
            $teredo_flags_input,
            $teredo_cone_flag
        );
    }

    // ISATAP shareable GET URL (v3.5.0 Task 5)
    if (
        $active_tab === 'ipv6'
        && (isset($_GET['isatap_ipv4']) || isset($_GET['isatap_iid']))
    ) {
        $isatap_mode = (string)($_GET['isatap_mode'] ?? 'encode');
        if ($isatap_mode !== 'encode' && $isatap_mode !== 'decode') {
            $isatap_mode = 'encode';
        }
        $isatap_ipv4_input      = trim((string)($_GET['isatap_ipv4'] ?? ''));
        $isatap_iid_input       = trim((string)($_GET['isatap_iid']  ?? ''));
        $isatap_globally_unique = (string)($_GET['isatap_globally_unique'] ?? '');
        $isatap = sc_run_isatap(
            $isatap_mode,
            $isatap_ipv4_input,
            $isatap_iid_input,
            $isatap_globally_unique
        );
    }

    // 6rd shareable GET URL (v3.5.0 Task 6)
    if (
        $active_tab === 'ipv6'
        && (isset($_GET['sixrd_ipv4']) || isset($_GET['sixrd_ipv6']) || isset($_GET['sixrd_sp_prefix']))
    ) {
        $sixrd_mode = (string)($_GET['sixrd_mode'] ?? 'encode');
        if ($sixrd_mode !== 'encode' && $sixrd_mode !== 'decode') {
            $sixrd_mode = 'encode';
        }
        $sixrd_sp_prefix_input = trim((string)($_GET['sixrd_sp_prefix'] ?? ''));
        $sixrd_mask_len_input  = trim((string)($_GET['sixrd_mask_len']  ?? ''));
        $sixrd_ipv4_input      = trim((string)($_GET['sixrd_ipv4']      ?? ''));
        $sixrd_ipv6_input      = trim((string)($_GET['sixrd_ipv6']      ?? ''));
        $sixrd = sc_run_6rd(
            $sixrd_mode,
            $sixrd_sp_prefix_input,
            $sixrd_mask_len_input,
            $sixrd_ipv4_input,
            $sixrd_ipv6_input
        );
    }

    // NAT64 / DNS64 shareable GET URL (v3.5.0 Task 7)
    if (
        $active_tab === 'ipv6'
        && (
            isset($_GET['nat64_mode'])
            || isset($_GET['nat64_ipv4'])
            || isset($_GET['nat64_ipv6'])
            || isset($_GET['nat64_a_record'])
        )
    ) {
        $nat64_mode = (string)($_GET['nat64_mode'] ?? 'encode');
        if ($nat64_mode !== 'encode' && $nat64_mode !== 'decode' && $nat64_mode !== 'dns64') {
            $nat64_mode = 'encode';
        }
        $nat64_prefix_input   = trim((string)($_GET['nat64_prefix']    ?? ''));
        $nat64_pl_input       = trim((string)($_GET['nat64_pl']        ?? ''));
        $nat64_ipv4_input     = trim((string)($_GET['nat64_ipv4']      ?? ''));
        $nat64_ipv6_input     = trim((string)($_GET['nat64_ipv6']      ?? ''));
        $nat64_a_record_input = trim((string)($_GET['nat64_a_record']  ?? ''));
        $nat64 = sc_run_nat64(
            $nat64_mode,
            $nat64_prefix_input,
            $nat64_pl_input,
            $nat64_ipv4_input,
            $nat64_ipv6_input,
            $nat64_a_record_input
        );
    }

    // Prefix-delegation planner shareable GET URL (v3.5.0 Task 8)
    if (
        $active_tab === 'ipv6'
        && (
            isset($_GET['prefix_plan6_parent'])
            || isset($_GET['prefix_plan6_child_length'])
            || isset($_GET['prefix_plan6_count'])
        )
    ) {
        $prefix_plan6_parent_input       = trim((string)($_GET['prefix_plan6_parent']       ?? ''));
        $prefix_plan6_child_length_input = trim((string)($_GET['prefix_plan6_child_length'] ?? ''));
        $prefix_plan6_count_input        = trim((string)($_GET['prefix_plan6_count']        ?? ''));
        $prefix_plan6_start_offset_input = trim((string)($_GET['prefix_plan6_start_offset'] ?? ''));
        // GET hydration accepts an explicit `=0` to mean "off"; presence with any
        // truthy value or no value defaults to on. This mirrors how shareable
        // URLs serialise checkbox state across the rest of the app.
        $prefix_plan6_nibble_align = !isset($_GET['prefix_plan6_nibble_align'])
            || (string)$_GET['prefix_plan6_nibble_align'] !== '0';
        $prefix_plan6 = sc_run_prefix_plan6(
            $prefix_plan6_parent_input,
            $prefix_plan6_child_length_input,
            $prefix_plan6_count_input,
            $prefix_plan6_start_offset_input,
            $prefix_plan6_nibble_align
        );
    }

    // Nibble-boundary helper shareable GET URL (v3.5.0 Task 9)
    if ($active_tab === 'ipv6' && isset($_GET['nibble6_prefix'])) {
        $nibble6_prefix_input = trim((string)($_GET['nibble6_prefix'] ?? ''));
        $nibble6              = sc_run_nibble6($nibble6_prefix_input);
    }

    // RFC 3531 sparse-allocation shareable GET URL (v3.5.0 Task 10)
    if (
        $active_tab === 'ipv6'
        && (
            isset($_GET['rfc3531_parent'])
            || isset($_GET['rfc3531_reservation_bits'])
        )
    ) {
        $rfc3531_parent_input           = trim((string)($_GET['rfc3531_parent']           ?? ''));
        $rfc3531_reservation_bits_input = trim((string)($_GET['rfc3531_reservation_bits'] ?? ''));
        $rfc3531_strategy_input         = trim((string)($_GET['rfc3531_strategy']         ?? 'centermost'));
        $rfc3531 = sc_run_rfc3531(
            $rfc3531_parent_input,
            $rfc3531_reservation_bits_input,
            $rfc3531_strategy_input
        );
    }

    // Multicast scope decoder shareable GET URL (v3.6.0 Task 2, #396)
    if ($active_tab === 'ipv6' && isset($_GET['multicast6_input'])) {
        $multicast6_input = trim((string)($_GET['multicast6_input'] ?? ''));
        $multicast6 = sc_run_multicast6($multicast6_input);
    }

    // SSM / unicast-prefix-based multicast shareable GET URL (v3.6.0 Task 3, #398)
    if (
        $active_tab === 'ipv6'
        && (
            isset($_GET['ssm6_mode'])
            || isset($_GET['ssm6_unicast_prefix'])
            || isset($_GET['ssm6_group_id'])
            || isset($_GET['ssm6_ipv6'])
        )
    ) {
        $ssm6_mode = (string)($_GET['ssm6_mode'] ?? 'encode');
        if ($ssm6_mode !== 'encode' && $ssm6_mode !== 'decode') {
            $ssm6_mode = 'encode';
        }
        $ssm6_unicast_prefix_input = trim((string)($_GET['ssm6_unicast_prefix'] ?? ''));
        $ssm6_scope_input          = trim((string)($_GET['ssm6_scope'] ?? '14'));
        $ssm6_group_id_input       = trim((string)($_GET['ssm6_group_id'] ?? ''));
        $ssm6_ipv6_input           = trim((string)($_GET['ssm6_ipv6'] ?? ''));
        $ssm6 = sc_run_ssm6(
            $ssm6_mode,
            $ssm6_unicast_prefix_input,
            $ssm6_scope_input,
            $ssm6_group_id_input,
            $ssm6_ipv6_input
        );
    }

    // Embedded-RP multicast shareable GET URL (v3.6.0 Task 4, RFC 3956, #397)
    if (
        $active_tab === 'ipv6'
        && (
            isset($_GET['embedded_rp6_mode'])
            || isset($_GET['embedded_rp6_rp_address'])
            || isset($_GET['embedded_rp6_rp_prefix_length'])
            || isset($_GET['embedded_rp6_riid'])
            || isset($_GET['embedded_rp6_group_id'])
            || isset($_GET['embedded_rp6_ipv6'])
        )
    ) {
        $embedded_rp6_mode = (string)($_GET['embedded_rp6_mode'] ?? 'encode');
        if ($embedded_rp6_mode !== 'encode' && $embedded_rp6_mode !== 'decode') {
            $embedded_rp6_mode = 'encode';
        }
        $embedded_rp6_rp_address_input       = trim((string)($_GET['embedded_rp6_rp_address']       ?? ''));
        $embedded_rp6_rp_prefix_length_input = trim((string)($_GET['embedded_rp6_rp_prefix_length'] ?? ''));
        $embedded_rp6_riid_input             = trim((string)($_GET['embedded_rp6_riid']             ?? ''));
        $embedded_rp6_scope_input            = trim((string)($_GET['embedded_rp6_scope']            ?? '14'));
        $embedded_rp6_group_id_input         = trim((string)($_GET['embedded_rp6_group_id']         ?? ''));
        $embedded_rp6_ipv6_input             = trim((string)($_GET['embedded_rp6_ipv6']             ?? ''));
        $embedded_rp6 = sc_run_embedded_rp6(
            $embedded_rp6_mode,
            $embedded_rp6_rp_address_input,
            $embedded_rp6_rp_prefix_length_input,
            $embedded_rp6_riid_input,
            $embedded_rp6_scope_input,
            $embedded_rp6_group_id_input,
            $embedded_rp6_ipv6_input
        );
    }

    // PMTU helper shareable GET URL (v3.6.0 Task 5, RFC 8200, #399)
    if (
        $active_tab === 'ipv6'
        && (
            isset($_GET['pmtu6_path_mtu'])
            || isset($_GET['pmtu6_payload_size'])
            || isset($_GET['pmtu6_extension_headers'])
        )
    ) {
        $pmtu6_path_mtu_input          = trim((string)($_GET['pmtu6_path_mtu']          ?? ''));
        $pmtu6_payload_size_input      = trim((string)($_GET['pmtu6_payload_size']      ?? ''));
        $pmtu6_extension_headers_input = trim((string)($_GET['pmtu6_extension_headers'] ?? ''));
        $pmtu6 = sc_run_pmtu6(
            $pmtu6_path_mtu_input,
            $pmtu6_payload_size_input,
            $pmtu6_extension_headers_input
        );
    }

    // Supernet6 / summarise6 shareable GET URL (v3.3.0)
    if ($active_tab === 'ipv6' && isset($_GET['supernet6_action'])) {
        $supernet6_action = in_array((string)($_GET['supernet6_action'] ?? ''), ['find', 'summarise'], true)
            ? (string)$_GET['supernet6_action']
            : 'find';
        $supernet6_input = trim((string)($_GET['supernet6_input'] ?? ''));
        $lines6 = array_values(array_filter(array_map('trim', explode("\n", $supernet6_input))));
        if (count($lines6) >= 1 && count($lines6) <= 50) {
            $sr6 = $supernet6_action === 'find' ? supernet6_find($lines6) : summarise6_cidrs($lines6);
            if (isset($sr6['error'])) {
                $supernet6 = ['error' => $sr6['error']];
            } else {
                $supernet6 = ['result' => $sr6];
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
                $vlsm6['error'] = 'Parent network: ' . ($rv6['error6'] ?? 'Invalid input.');
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
                        $vlsm6['error'] = 'Each requirement name must be 100 characters or fewer.';
                    } elseif ($reqs6 !== []) {
                        $vlsm6_requirements = $reqs6;
                        $vlsm6_cidr_int     = (int)ltrim((string)$rv6['result6']['prefix'], '/');
                        $vlsm6_network_ip   = explode('/', (string)$rv6['result6']['network_cidr'])[0];
                        $vr6 = vlsm6_allocate($vlsm6_network_ip, $vlsm6_cidr_int, $reqs6);
                        if (isset($vr6['error'])) {
                            $vlsm6['error'] = $vr6['error'];
                        } else {
                            $vlsm6['result'] = $vr6['allocations'] ?? [];
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
                $vlsm['error'] = 'Parent network: ' . ($rv['error'] ?? 'Invalid input.');
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
                            $vlsm['error'] = $vr['error'];
                        } else {
                            $vlsm['result'] = $vr['allocations'] ?? [];
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
                $splitter = ['result' => split_subnet($network_ip, $current_cidr, $new_pfx, $split_max_subnets)];
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
                $splitter6 = ['result' => split_subnet6($network_ipv6, $current_pfx6, $new_pfx6, $split_max_subnets)];
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
    $sp = isset($splitter['result']) ? ['split_prefix' => ltrim($input_split_prefix, '/')] : [];
    $share_url = '?' . http_build_query(
        ['tab' => 'ipv4', 'ip' => $input_ip, 'mask' => ltrim($result['netmask_cidr'], '/')] + $sp
    );
} elseif ($result6) {
    $sp6 = isset($splitter6['result']) ? ['split_prefix6' => ltrim($input_split_prefix6, '/')] : [];
    $share_url = '?' . http_build_query(
        ['tab' => 'ipv6', 'ipv6' => $input_ipv6, 'prefix' => ltrim($result6['prefix'], '/')] + $sp6
    );
} elseif (isset($vlsm['result']) && $vlsm_network !== '') {
    $vlsm_names = array_map(fn($r) => $r['name'], $vlsm_requirements);
    $vlsm_qhosts = array_map(fn($r) => $r['hosts'], $vlsm_requirements);
    $share_url = '?' . http_build_query([
        'tab'          => 'vlsm',
        'vlsm_network' => $vlsm_network,
        'vlsm_cidr'    => ltrim($vlsm_cidr_input, '/'),
        'vlsm_name'    => $vlsm_names,
        'vlsm_hosts'   => $vlsm_qhosts,
    ]);
} elseif (isset($vlsm6['result']) && $vlsm6_network !== '') {
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
// v3.0.0 (#315) — anchor the share-link tab to the saved session's planner.
// IPv6 saves go to ?tab=vlsm6, IPv4 stays on ?tab=vlsm. The active tab at
// save time is the source of truth (IPv6 saves only happen on vlsm6).
$session_save_tab = ($active_tab === 'vlsm6') ? 'vlsm6' : 'vlsm';
$session_save_url = $session_save_id !== ''
    ? '?tab=' . $session_save_tab . '&s=' . urlencode($session_save_id)
    : '';
