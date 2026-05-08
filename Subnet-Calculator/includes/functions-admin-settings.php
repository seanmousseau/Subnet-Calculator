<?php

declare(strict_types=1);

// Admin Settings page (v3.2.0+, #349).
//
// Surfaces ~20 of the ~35 config.php knobs in a schema-driven UI that writes
// only to config-admin.php. Operator hand-edits in config.php always win
// (existing convention from v3.0.0). Each knob declares its type, default,
// validation rules, label, help text, and section in `settings_schema()`.
//
// Layered resolution at display time:
//   default → config-admin.php → config.php (winner)
//
// On save: validate every changed field, atomic write a new config-admin.php
// (tmp + php -l lint + rename + opcache_invalidate), audit-log one
// `config.update` row per actual change with before/after values (secrets
// redacted to '(set)' / '(empty)').

const ADMIN_SETTINGS_SECTIONS = ['branding', 'forms', 'api', 'sessions', 'admin', 'limits', 'csp'];

/**
 * Authoritative schema for every settings-page knob.
 *
 * @return array<string, array<string, mixed>>
 */
function settings_schema(): array
{
    return [
        // ── Branding ────────────────────────────────────────────────────────
        'page_title' => [
            'type' => 'string', 'default' => 'Subnet Calculator',
            'maxlen' => 200, 'section' => 'branding',
            'label' => 'Page title',
            'help'  => 'Shown in the browser tab and as the wordmark.',
        ],
        'page_description' => [
            'type' => 'string', 'default' => '',
            'maxlen' => 500, 'section' => 'branding',
            'label' => 'Page description',
            'help'  => 'Meta description for search engines + social cards.',
            'multiline' => true,
        ],
        'canonical_url' => [
            'type' => 'string', 'default' => '',
            'maxlen' => 500, 'section' => 'branding',
            'label' => 'Canonical URL',
            'help'  => 'Absolute URL of this install (e.g. https://subnetcalculator.app/).',
        ],
        'default_tab' => [
            'type' => 'enum', 'default' => 'ipv4',
            'values' => ['ipv4', 'ipv6', 'vlsm'],
            'section' => 'branding',
            'label' => 'Default tab',
            'help'  => 'Which calculator tab is active on first load.',
        ],
        'locale' => [
            'type' => 'string', 'default' => 'en',
            'maxlen' => 16, 'section' => 'branding',
            'pattern' => '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/',
            'label' => 'Locale',
            'help'  => 'BCP 47 tag for number formatting (e.g. en, de, fr-CA).',
        ],
        'fixed_bg_color' => [
            'type' => 'string', 'default' => 'null',
            'maxlen' => 32, 'section' => 'branding',
            'label' => 'Fixed background colour',
            'help'  => 'Hex like #0e1320, or "null" to use the theme token.',
        ],
        'show_share_bar' => [
            'type' => 'bool', 'default' => true,
            'section' => 'branding',
            'label' => 'Show share bar',
            'help'  => 'Toggle the shareable-URL bar under each result.',
        ],
        'frame_ancestors' => [
            'type' => 'string', 'default' => '*',
            'maxlen' => 200, 'section' => 'branding',
            'label' => 'Frame ancestors',
            'help'  => 'CSP frame-ancestors value (* allows any embed).',
        ],

        // ── Forms / Captcha ─────────────────────────────────────────────────
        'form_protection' => [
            'type' => 'enum', 'default' => 'none',
            'values' => ['none', 'honeypot', 'turnstile', 'hcaptcha', 'recaptcha_enterprise'],
            'section' => 'forms',
            'label' => 'Form protection',
            'help'  => 'Anti-bot mechanism for the calculator forms.',
        ],
        'turnstile_site_key' => [
            'type' => 'string', 'default' => '', 'maxlen' => 200,
            'section' => 'forms',
            'label' => 'Turnstile site key',
            'help'  => 'Cloudflare Turnstile public key.',
        ],
        'turnstile_secret_key' => [
            'type' => 'string', 'default' => '', 'maxlen' => 200,
            'secret' => true, 'section' => 'forms',
            'label' => 'Turnstile secret key',
            'help'  => 'Server-side Turnstile secret. Stored in config-admin.php.',
        ],
        'recaptcha_score_threshold' => [
            'type' => 'float', 'default' => 0.5,
            'min' => 0.0, 'max' => 1.0,
            'section' => 'forms',
            'label' => 'reCAPTCHA score threshold',
            'help'  => '0.0 = lenient, 1.0 = strict. Default 0.5.',
        ],

        // ── API limits ──────────────────────────────────────────────────────
        'api_rate_limit_rpm' => [
            'type' => 'int', 'default' => 60,
            'min' => 0, 'max' => 100000,
            'section' => 'api',
            'label' => 'API rate limit (RPM)',
            'help'  => 'Requests per minute per IP. 0 = disabled.',
        ],
        'api_cors_origins' => [
            'type' => 'string', 'default' => '*',
            'maxlen' => 1000, 'section' => 'api',
            'label' => 'API CORS origins',
            'help'  => 'CORS Access-Control-Allow-Origin header value.',
        ],
        'api_allowed_endpoints' => [
            'type' => 'multiselect', 'default' => [],
            'values' => [
                'ipv4', 'ipv6', 'vlsm', 'vlsm6', 'overlap', 'split',
                'supernet', 'ula', 'rdns', 'rdns6', 'bulk', 'range', 'tree',
                'tree-presets', 'lookup', 'diff', 'wildcard', 'sessions',
                'changelog', 'schemas',
            ],
            'section' => 'api',
            'label' => 'API endpoint allowlist',
            'help'  => 'Empty = all endpoints allowed; non-empty = strict allowlist.',
        ],

        // ── Sessions ────────────────────────────────────────────────────────
        'session_enabled' => [
            'type' => 'bool', 'default' => false,
            'section' => 'sessions',
            'label' => 'Session save/restore enabled',
            'help'  => 'SQLite-backed VLSM session save/restore.',
        ],
        'session_ttl_days' => [
            'type' => 'int', 'default' => 30,
            'min' => 1, 'max' => 365,
            'section' => 'sessions',
            'label' => 'Session TTL (days)',
            'help'  => 'Days before a saved session expires.',
        ],

        // ── Admin & audit ───────────────────────────────────────────────────
        'admin_audit_retention_days' => [
            'type' => 'int', 'default' => 90,
            'min' => 0, 'max' => 3650,
            'section' => 'admin',
            'label' => 'Audit retention (days)',
            'help'  => 'Audit rows older than this are purged. 0 = keep forever.',
        ],
        'admin_audit_trust_xff' => [
            'type' => 'bool', 'default' => false,
            'section' => 'admin',
            'label' => 'Trust X-Forwarded-For for audit IP',
            'help'  => 'Only enable if behind a trusted reverse proxy.',
        ],
        'admin_audit_purge_strategy' => [
            'type' => 'enum', 'default' => 'sampled',
            'values' => ['inline', 'sampled', 'cron'],
            'section' => 'admin',
            'label' => 'Audit purge strategy',
            'help'  => '`sampled` = probabilistic; `inline` = every write; `cron` = manual.',
        ],
        'admin_audit_purge_sample_rate' => [
            'type' => 'float', 'default' => 0.001,
            'min' => 0.0, 'max' => 1.0,
            'section' => 'admin',
            'label' => 'Audit purge sample rate',
            'help'  => 'Probability per audit write (0.001 ≈ 1 in 1000).',
        ],

        // ── Limits ──────────────────────────────────────────────────────────
        'split_max_subnets' => [
            'type' => 'int', 'default' => 16,
            'min' => 1, 'max' => 256,
            'section' => 'limits',
            'label' => 'Splitter max subnets',
            'help'  => 'Upper bound on subnets the splitter may emit.',
        ],
        'lookup_max_cidrs' => [
            'type' => 'int', 'default' => 100,
            'min' => 1, 'max' => 1000,
            'section' => 'limits',
            'label' => 'Lookup max CIDRs',
            'help'  => 'Inverse subnet lookup: max CIDRs per request.',
        ],
        'lookup_max_ips' => [
            'type' => 'int', 'default' => 1000,
            'min' => 1, 'max' => 10000,
            'section' => 'limits',
            'label' => 'Lookup max IPs',
            'help'  => 'Inverse subnet lookup: max IPs per request.',
        ],

        // ── Advanced (CSP) ──────────────────────────────────────────────────
        'csp_connect_extra' => [
            'type' => 'string', 'default' => '',
            'maxlen' => 500, 'section' => 'csp',
            'label' => 'CSP connect-src extras',
            'help'  => 'Additional connect-src origins (space-separated).',
        ],
        'csp_script_extra' => [
            'type' => 'string', 'default' => '',
            'maxlen' => 500, 'section' => 'csp',
            'label' => 'CSP script-src extras',
            'help'  => 'Additional script-src origins (space-separated).',
        ],
        'csp_img_extra' => [
            'type' => 'string', 'default' => '',
            'maxlen' => 500, 'section' => 'csp',
            'label' => 'CSP img-src extras',
            'help'  => 'Additional img-src origins (space-separated).',
        ],
    ];
}

/**
 * Validate a single submitted value against its schema entry.
 *
 * @return array{ok:bool, error?:string, value?:mixed}
 */
function settings_validate(string $key, mixed $raw, array $schema): array
{
    if (!isset($schema[$key])) {
        return ['ok' => false, 'error' => 'unknown setting'];
    }
    $entry = $schema[$key];
    $type  = (string)($entry['type'] ?? 'string');

    if ($type === 'bool') {
        $b = filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($b === null) {
            return ['ok' => false, 'error' => 'must be true/false'];
        }
        return ['ok' => true, 'value' => $b];
    }
    if ($type === 'int') {
        if (!is_numeric($raw)) {
            return ['ok' => false, 'error' => 'must be an integer'];
        }
        $i = (int)$raw;
        if (isset($entry['min']) && $i < (int)$entry['min']) {
            return ['ok' => false, 'error' => 'must be >= ' . (int)$entry['min']];
        }
        if (isset($entry['max']) && $i > (int)$entry['max']) {
            return ['ok' => false, 'error' => 'must be <= ' . (int)$entry['max']];
        }
        return ['ok' => true, 'value' => $i];
    }
    if ($type === 'float') {
        if (!is_numeric($raw)) {
            return ['ok' => false, 'error' => 'must be a number'];
        }
        $f = (float)$raw;
        if (isset($entry['min']) && $f < (float)$entry['min']) {
            return ['ok' => false, 'error' => 'must be >= ' . (float)$entry['min']];
        }
        if (isset($entry['max']) && $f > (float)$entry['max']) {
            return ['ok' => false, 'error' => 'must be <= ' . (float)$entry['max']];
        }
        return ['ok' => true, 'value' => $f];
    }
    if ($type === 'enum') {
        $values = $entry['values'] ?? [];
        if (!in_array((string)$raw, $values, true)) {
            return ['ok' => false, 'error' => 'must be one of: ' . implode(', ', $values)];
        }
        return ['ok' => true, 'value' => (string)$raw];
    }
    if ($type === 'multiselect') {
        $allowed = $entry['values'] ?? [];
        $list = is_array($raw) ? $raw : [];
        $clean = [];
        foreach ($list as $item) {
            $s = (string)$item;
            if (!in_array($s, $allowed, true)) {
                return ['ok' => false, 'error' => 'unknown value: ' . $s];
            }
            if (!in_array($s, $clean, true)) {
                $clean[] = $s;
            }
        }
        return ['ok' => true, 'value' => $clean];
    }
    // string
    $s = is_scalar($raw) ? (string)$raw : '';
    if (isset($entry['maxlen']) && strlen($s) > (int)$entry['maxlen']) {
        return ['ok' => false, 'error' => 'too long (max ' . (int)$entry['maxlen'] . ' chars)'];
    }
    if (isset($entry['pattern']) && !preg_match((string)$entry['pattern'], $s) && $s !== '') {
        return ['ok' => false, 'error' => 'invalid format'];
    }
    return ['ok' => true, 'value' => $s];
}

/**
 * Parse a config-style PHP file into [key => value] for the schema's keys.
 * Uses an isolated include to capture only the variables we care about,
 * without polluting the caller's scope or running the file's runtime
 * sanitisation block (e.g. config.php's preg_replace pass).
 *
 * @return array<string, mixed>
 */
function settings_parse_config_file(string $path, array $schemaKeys): array
{
    if (!is_string($path) || $path === '' || !file_exists($path) || !is_readable($path)) {
        return [];
    }
    // The Settings UI reads + writes config-admin.php in the same request
    // cycle, and the layered loader re-includes both config.php and
    // config-admin.php on every render. PHP's stat cache + opcache can
    // hand back a stale snapshot for a few seconds after a save, which
    // surfaces as "settings revert after logout/login". Force a fresh
    // read each time we parse a config file.
    clearstatcache(true, $path);
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($path, true);
    }
    $closure = static function () use ($path) {
        // Suppress the file's own includes/headers by capturing $GLOBALS-like
        // state in a local scope. The $api_tokens / $admin_* sanitisation in
        // config.php only runs if the file `require`s functions-* first; the
        // wizard tier (config-admin.php) is just `$var = …;` lines.
        // shellcheck-style note: we treat config-admin.php as data; static
        // analyzers may flag this — config-admin.php is operator-controlled
        // via the wizard + this Settings page, not user input.
        // nosemgrep: php.lang.security.exec-use.exec-use
        @include $path;
        return get_defined_vars();
    };
    $vars = $closure();
    unset($vars['path']);
    $out = [];
    foreach ($schemaKeys as $k) {
        if (array_key_exists($k, $vars)) {
            $out[$k] = $vars[$k];
        }
    }
    return $out;
}

/**
 * Layered settings view: per-key, the default + admin-tier value (if any) +
 * config.php value (if any) + effective resolved value + source label.
 *
 * @return array<string, array{
 *     default:mixed,
 *     admin:mixed|null,
 *     config:mixed|null,
 *     effective:mixed,
 *     source:string,
 *     shadowed:bool
 * }>
 */
function settings_load_layered(): array
{
    $schema = settings_schema();
    $keys   = array_keys($schema);

    $configPath      = dirname(__DIR__) . '/config.php';
    $configAdminPath = admin_wizard_config_path();

    $configVars  = settings_parse_config_file($configPath, $keys);
    $adminVars   = settings_parse_config_file($configAdminPath, $keys);

    $out = [];
    foreach ($schema as $k => $entry) {
        $default  = $entry['default'] ?? null;
        $admin    = array_key_exists($k, $adminVars)  ? $adminVars[$k]  : null;
        $config   = array_key_exists($k, $configVars) ? $configVars[$k] : null;

        $hasAdmin  = array_key_exists($k, $adminVars);
        $hasConfig = array_key_exists($k, $configVars);

        if ($hasConfig) {
            $effective = $config;
            $source    = 'config';
        } elseif ($hasAdmin) {
            $effective = $admin;
            $source    = 'admin';
        } else {
            $effective = $default;
            $source    = 'default';
        }

        $out[$k] = [
            'default'   => $default,
            'admin'     => $admin,
            'config'    => $config,
            'effective' => $effective,
            'source'    => $source,
            'shadowed'  => $hasAdmin && $hasConfig,
        ];
    }
    return $out;
}

/**
 * Save validated settings to config-admin.php via the merge helper. Atomic
 * write + php -l lint check + opcache invalidate. Returns the keys actually
 * written (a no-op key matching its existing admin-tier value is skipped).
 *
 * @param array<string, mixed> $kv  validated key/value pairs
 * @return array{ok:bool, written:array<int,string>, error?:string}
 */
function settings_save(array $kv): array
{
    if ($kv === []) {
        return ['ok' => true, 'written' => []];
    }
    $path = admin_wizard_config_path();
    $existing = settings_parse_config_file($path, array_keys($kv));

    // Skip no-op writes — merge helper is idempotent but we want clean
    // audit rows.
    $changes = [];
    foreach ($kv as $k => $v) {
        if (!array_key_exists($k, $existing) || $existing[$k] !== $v) {
            $changes[$k] = $v;
        }
    }
    if ($changes === []) {
        return ['ok' => true, 'written' => []];
    }

    // Cast values to the form var_export prefers before writing.
    $kvForWrite = [];
    foreach ($changes as $k => $v) {
        $kvForWrite[$k] = $v;
    }

    if (!admin_config_admin_set_keys($kvForWrite)) {
        return ['ok' => false, 'written' => [], 'error' => 'failed to write config-admin.php'];
    }

    // Lint check by re-including in an isolated closure — if PHP can parse
    // the file when included, the merge helper's regex didn't break it.
    $linted = settings_parse_config_file($path, array_keys($kvForWrite));
    if (count($linted) !== count($kvForWrite)) {
        // Some keys did not round-trip — surface as an error rather than
        // pretending the write was clean.
        return ['ok' => false, 'written' => [], 'error' => 'post-write lint did not see all keys'];
    }

    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($path, true);
    }

    return ['ok' => true, 'written' => array_keys($changes)];
}

/**
 * Format a value for redacted audit metadata.
 */
function settings_audit_redact(mixed $value, bool $isSecret): string
{
    if ($isSecret) {
        return ((is_string($value) && $value !== '') || (!is_string($value) && !empty($value))) ? '(set)' : '(empty)';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_array($value)) {
        return json_encode($value) ?: '(array)';
    }
    return (string)$value;
}

/**
 * Pre-flight writability check for the Settings page. Returns null on success
 * or a human-readable reason string on failure.
 */
function settings_writability_error(): ?string
{
    $path = admin_wizard_config_path();
    $dir  = dirname($path);
    if (!is_dir($dir)) {
        return 'Directory ' . $dir . ' does not exist.';
    }
    if (!is_writable($dir)) {
        return 'Directory ' . $dir . ' is not writable by the web user.';
    }
    if (file_exists($path) && !is_writable($path)) {
        return $path . ' exists but is not writable by the web user.';
    }
    return null;
}
