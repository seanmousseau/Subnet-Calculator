# Operator Configuration

Copy `config.php.example` to `config.php` in the app docroot and edit as needed.

```bash
cp config.php.example config.php
```

All variables have safe defaults and the app works without a `config.php`.

## Variables

| Variable | Default | Description |
|---|---|---|
| `$app_version` | set internally | Displayed in the UI and API meta response |
| `$fixed_bg_color` | `'null'` | Optional fixed background colour (CSS value). `'null'` means no override |
| `$default_tab` | `'ipv4'` | Which tab is active on first load (`'ipv4'`, `'ipv6'`, `'vlsm'`) |
| `$split_max_subnets` | `16` | Maximum subnets returned by the splitter (1–256) |
| `$show_share_bar` | `true` | Show the Share bar below results |
| `$canonical_url` | `''` | Base URL used to build shareable links. Auto-derived from the request if empty |
| `$frame_ancestors` | `'*'` | `frame-ancestors` CSP directive. `'*'` allows any origin to embed. Set to `"'self'"` or a space-separated list of origins to restrict |
| `$session_enabled` | `false` | Enable VLSM session save/restore (requires SQLite) |
| `$session_ttl_days` | `30` | Days before saved sessions expire |
| `$session_db_path` | `''` | Absolute path to the sessions SQLite file. Auto-derived from `data/` if empty |
| `$form_protection` | `'none'` | Spam protection: `'none'`, `'honeypot'`, `'turnstile'`, `'hcaptcha'`, or `'recaptcha_enterprise'` |
| `$turnstile_site_key` | `''` | Cloudflare Turnstile site key |
| `$turnstile_secret_key` | `''` | Cloudflare Turnstile secret key |
| `$hcaptcha_site_key` | `''` | hCaptcha site key |
| `$hcaptcha_secret_key` | `''` | hCaptcha secret key |
| `$recaptcha_enterprise_site_key` | `''` | reCAPTCHA Enterprise site key |
| `$recaptcha_enterprise_api_key` | `''` | reCAPTCHA Enterprise API key |
| `$recaptcha_enterprise_project_id` | `''` | Google Cloud project ID |
| `$recaptcha_score_threshold` | `0.5` | Minimum reCAPTCHA Enterprise score to accept a submission |
| `$page_title` | `'Subnet Calculator'` | `<title>` and Open Graph title |
| `$page_description` | (built-in) | Meta description |
| `$locale` | `'en'` | BCP 47 locale tag for locale-aware number formatting (e.g. `'de'`, `'fr'`). Uses PHP `intl` extension when available; falls back to comma separators |
| `$api_tokens` | `[]` | Bearer tokens that authorise REST API requests. Empty = open API. Non-empty: requests must include `Authorization: Bearer <token>` |
| `$api_rate_limit_rpm` | `60` | Requests per minute per IP (0 = disabled) |
| `$api_rate_limit_tokens` | `[]` | Per-token RPM overrides (`['token' => rpm]`). `0` = unlimited for that token |
| `$api_allowed_endpoints` | `[]` | Endpoint allowlist. Empty = all endpoints available. Non-empty = only listed endpoints accessible; unlisted return 404 |
| `$api_cors_origins` | `'*'` | `Access-Control-Allow-Origin` header value for API responses |
| `$api_request_log` | `false` | Enable SQLite-backed API request logging (records endpoint, method, client IP, and timestamp) |
| `$api_request_log_db_path` | `''` | Absolute path to the request log SQLite file. Auto-derived from `data/` if empty |

## Example

```php
<?php
$canonical_url    = 'https://subnet.example.com/';
$frame_ancestors  = "'self' https://intranet.example.com";
$session_enabled  = true;
$session_ttl_days = 90;
$default_tab      = 'vlsm';
$split_max_subnets = 64;
$form_protection  = 'turnstile';
$turnstile_site_key    = 'your-site-key';
$turnstile_secret_key  = 'your-secret-key';
$api_request_log  = true;
```

## Notes

- **`$split_max_subnets`** — clamped to the range 1–256 on load regardless of what is set in `config.php`.
- **`$frame_ancestors`** — validated on load; an invalid value is reset to `'*'` and a warning is written to the PHP error log.
- **`$form_protection`** — validated on load; an invalid value is reset to `'none'`.
- **`$api_request_log`** — when enabled, a new SQLite database `data/api_requests.sqlite` is created automatically (or the path specified in `$api_request_log_db_path`). The `data/` directory is blocked from web access by `.htaccess`.
