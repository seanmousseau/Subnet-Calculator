# Configuration Reference

**Audience:** Operator (Sean) + future-agent setting up or tuning a deployment.
**Source:** `Subnet-Calculator/includes/config.php` (defaults) overridden by `Subnet-Calculator/config.php` if present (operator-only overrides; git-ignored).
**Last updated:** 2026-05-13 (app v3.7.0)

Every operator-tunable variable shipped by the app is enumerated here. If you find a `$variable` in code that isn't documented here, the docs are stale — add it.

---

## How configuration works

1. `includes/config.php` (shipped, tracked in git) defines defaults for every variable.
2. `config.php` at the docroot (operator-only, **not** in git, **not** in releases — anchored-rsync-excluded per invariant I8) is `require`d if present and may override any value.
3. Validators near the bottom of `includes/config.php` clamp/normalise some values (e.g., `$split_max_subnets = max(1, min(256, ...))`).

To override a value: copy `config.php.example` → `config.php` and edit. Never edit `includes/config.php` directly; deploys will overwrite it.

---

## Core display

| Variable | Default | Purpose |
|---|---|---|
| `$app_version` | `'3.7.1'` | Read-only at runtime. Bumped via `/release` skill. Do not override. |
| `$locale` | `'en'` | BCP 47 locale tag for number formatting (e.g., `'de'`, `'fr'`). |
| `$fixed_bg_color` | `'null'` | Optional hard-coded background colour (overrides theme `--color-bg`). String `'null'` = no override. |
| `$default_tab` | `'ipv4'` | Initial tab on page load. Valid: `'ipv4'`, `'ipv6'`, `'vlsm'`. |
| `$page_title` | `'Subnet Calculator'` | `<title>` tag + `og:title`. |
| `$page_description` | (long string) | `<meta description>` + `og:description`. |
| `$show_share_bar` | `true` | Toggle the "Share" UI on the calculator output panel. |
| `$canonical_url` | `''` | If set, emits `<link rel="canonical">`. Use when serving the same app behind multiple hostnames. |

---

## Calculator limits (DoS caps)

| Variable | Default | Clamped to | Purpose |
|---|---|---|---|
| `$split_max_subnets` | `16` | `[1, 256]` | Maximum subnets the splitter will emit per request. Hard cap protects against `?ip=10.0.0.0/8&new_prefix=32` style explosions. See threat T7. |
| `$lookup_max_cidrs` | `100` | — | Inverse subnet lookup: max CIDRs accepted per request. |
| `$lookup_max_ips` | `1000` | — | Inverse subnet lookup: max IPs accepted per request. |
| `$range_max_cidrs` | `256` | — | IP-range→CIDR converter: max output CIDRs per request (v3.3.0). |

Hard caps that aren't operator-tunable (in the function, not config):
- PMTU `payload_size ≤ 65535`, `fragment_count ≤ 4096` (invariant I19).
- Session ID is always 16 hex chars (invariant I20).

---

## Form protection

| Variable | Default | Purpose |
|---|---|---|
| `$form_protection` | `'none'` | Mode for calculator forms. Valid: `'none'`, `'honeypot'`, `'turnstile'`, `'hcaptcha'`, `'recaptcha'`. |
| `$turnstile_site_key` | `''` | Cloudflare Turnstile site key (required when `$form_protection = 'turnstile'`). |
| `$turnstile_secret_key` | `''` | Cloudflare Turnstile secret. |
| `$hcaptcha_site_key` | `''` | hCaptcha site key. |
| `$hcaptcha_secret_key` | `''` | hCaptcha secret. |
| `$recaptcha_enterprise_site_key` | `''` | Google reCAPTCHA Enterprise site key. |
| `$recaptcha_enterprise_api_key` | `''` | Google reCAPTCHA Enterprise API key. |
| `$recaptcha_enterprise_project_id` | `''` | Google reCAPTCHA Enterprise project ID. |
| `$recaptcha_score_threshold` | `0.5` | Minimum reCAPTCHA score to accept. Higher = stricter. |

Only one mode is active at a time. Honeypot is recommended for low-traffic deployments; switch to a CAPTCHA only if you're seeing real abuse.

---

## CSP / headers

| Variable | Default | Purpose |
|---|---|---|
| `$frame_ancestors` | `'*'` | CSP `frame-ancestors`. Set to a specific origin or `'none'` to lock down iframe embedding. |
| `$csp_connect_extra` | `''` | Appended to CSP `connect-src` (e.g., to allow a custom analytics endpoint). |
| `$csp_script_extra` | `''` | Appended to CSP `script-src`. |
| `$csp_img_extra` | `''` | Appended to CSP `img-src`. |
| `$hsts_max_age` | `31536000` | HSTS max-age in seconds (default 1 year; max 2 years = `63072000`). |
| `$hsts_preload` | `false` | Operator opt-in for HSTS `preload` directive. Only enable after confirming you can keep HTTPS indefinitely — see [hstspreload.org](https://hstspreload.org/) before flipping. |

---

## REST API auth + rate limiting

| Variable | Default | Purpose |
|---|---|---|
| `$api_tokens` | `[]` | Static legacy API tokens. `[]` = open (no auth); `['secret1', 'secret2']` = require `Authorization: Bearer <one of these>`. **For new deployments use API keys via the admin console, not static tokens.** |
| `$api_rate_limit_rpm` | `60` | Per-IP requests/minute. `0` = disabled. |
| `$api_rate_limit_tokens` | `[]` | Per-token RPM overrides: `['token1' => 600]` raises the limit for that specific token. |
| `$api_allowed_endpoints` | `[]` | Endpoint allowlist. `[]` = all enabled; non-empty restricts to listed slugs (e.g., `['ipv4', 'ipv6', 'vlsm']`). |
| `$api_cors_origins` | `['*']` | CORS allowlist for the API. `['*']` = any origin (default for public deployments); restrict to specific origins for private deployments. |
| `$api_trusted_proxies` | `[]` | CIDRs whose `X-Forwarded-For` headers should be honoured for rate-limiting (e.g., your Cloudflare ranges). Empty = trust only the immediate peer. |

---

## Sessions (shareable URLs)

| Variable | Default | Purpose |
|---|---|---|
| `$session_enabled` | `false` | Master switch for the SQLite-backed VLSM session save/restore feature. |
| `$session_db_path` | `''` | Absolute path to the sessions SQLite file. Empty = auto (`data/sessions.sqlite` under the docroot). See [`data-dictionary.md`](data-dictionary.md). |
| `$session_ttl_days` | `30` | Days before a saved session expires. Lazy-purged on each new session creation. |

---

## API request logging

| Variable | Default | Purpose |
|---|---|---|
| `$api_request_log` | `false` | Enable SQLite-backed API request logging. |
| `$api_request_log_db_path` | `''` | Absolute path to the log DB. Empty = auto. |

Disabled by default. Enable only if you need operational forensics; the log table grows linearly with traffic.

---

## Admin console + API keys

| Variable | Default | Purpose |
|---|---|---|
| `$admin_ui_enabled` | `false` | Master switch for `/admin/` routes and the admin-keys API. Off by default — admin UI does not exist for the operator until this is flipped. |
| `$admin_user` | `''` | Admin username (HTTP Basic Auth fallback path before TOTP gates further actions). |
| `$admin_pass_hash` | `''` | `PASSWORD_BCRYPT` hash of the admin password. Generate via `php -r "echo password_hash('your-password', PASSWORD_BCRYPT);"`. |
| `$apikey_db_path` | `''` | SQLite file for `api_keys` + admin tables. Empty = auto. Setting this to a path distinct from `$session_db_path` separates admin state from session state (different files, different backup cadence possible). |
| `$admin_totp_secret` | `''` | Base32 RFC 6238 secret for admin TOTP. Empty = TOTP disabled. The setup wizard writes this; do not set by hand unless restoring from backup. |

---

## Admin audit

| Variable | Default | Purpose |
|---|---|---|
| `$admin_audit_retention_days` | `90` | Rows older than this are removed on each audit write. `0` = never purge (keep forever). |
| `$admin_audit_trust_xff` | `false` | Trust the `X-Forwarded-For` header when recording the source IP of an admin action. Enable only when behind a trusted reverse proxy (Cloudflare) — otherwise spoofable. Filtered via `FILTER_VALIDATE_BOOLEAN`. |
| `$admin_audit_purge_strategy` | `'sampled'` | When to run the retention sweep. Valid: `'always'` (every write — strongest GC but costlier) or `'sampled'` (random sample, controlled by next variable). |
| `$admin_audit_purge_sample_rate` | `0.001` | When `$admin_audit_purge_strategy = 'sampled'`, the probability per write that the purge runs. `0.001` = ~1 in 1000 writes triggers a sweep. |

---

## Tree presets

| Variable | Default | Purpose |
|---|---|---|
| `$tree_presets_dir` | `''` | Directory containing operator-defined tree-preset JSON files. Empty = built-in presets only. |

---

## Override patterns

### Minimal production override (`config.php`)

```php
<?php
// Cloudflare-fronted, public deployment with anonymous + per-key auth.
$canonical_url       = 'https://subnetcalculator.app/';
$form_protection     = 'turnstile';
$turnstile_site_key  = '...';
$turnstile_secret_key = '...';
$api_rate_limit_rpm  = 60;
$api_trusted_proxies = ['173.245.48.0/20', '103.21.244.0/22', ...]; // Cloudflare ranges
$admin_ui_enabled    = true;
$apikey_db_path      = '/var/lib/subnet-calculator/admin.sqlite';
$session_enabled     = true;
$hsts_preload        = true;
```

### Internal / iframe-only deployment

```php
<?php
$frame_ancestors      = "'self' https://intranet.example.com";
$api_tokens           = ['s3cret-token-here'];
$api_cors_origins     = ['https://intranet.example.com'];
$form_protection      = 'none';
$show_share_bar       = false;
$admin_ui_enabled     = false;
```

---

## Update protocol

Any PR that:
- Adds, removes, or renames a config variable in `includes/config.php`.
- Changes a default value.
- Changes a validator (clamping range, allowed values).
- Adds a new config-driven feature.

The PR must:
1. Update the relevant section here.
2. Update `config.php.example` if the variable is operator-relevant.
3. Add a CHANGELOG entry.
4. Bump the "Last updated" header.

If a config variable appears in code but not here, the doc is stale — fix it in the same PR.

---

## Cross-references

- Persistence and what each `*_db_path` points to: [`data-dictionary.md`](data-dictionary.md).
- Threat model around CORS / rate-limit / form protection: [`security-model.md`](security-model.md).
- Release-time `config.php` preservation rule (anchored rsync): [`design-document.md`](design-document.md) §5 invariant I8.
