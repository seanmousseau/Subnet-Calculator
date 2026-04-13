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
| `$fixed_bg_color` | `''` | Optional fixed background colour (CSS value) |
| `$default_tab` | `'ipv4'` | Which tab is active on first load (`ipv4`, `ipv6`, `vlsm`) |
| `$split_max_subnets` | `256` | Maximum subnets returned by the splitter |
| `$show_share_bar` | `true` | Show the Share bar below results |
| `$canonical_url` | `''` | Base URL used to build shareable links (e.g. `https://example.com/subnet-calculator/`) |
| `$frame_ancestors` | `"'self'"` | `frame-ancestors` CSP directive. Set to `'*'` to allow any origin to embed |
| `$session_enabled` | `false` | Enable VLSM session save/restore (requires SQLite) |
| `$session_ttl_days` | `30` | Days before saved sessions expire |
| `$form_protection` | `'honeypot'` | Spam protection: `'honeypot'`, `'turnstile'`, `'hcaptcha'`, or `'recaptcha_enterprise'` |
| `$turnstile_site_key` | `''` | Cloudflare Turnstile site key |
| `$turnstile_secret_key` | `''` | Cloudflare Turnstile secret key |
| `$hcaptcha_site_key` | `''` | hCaptcha site key |
| `$hcaptcha_secret_key` | `''` | hCaptcha secret key |
| `$recaptcha_enterprise_site_key` | `''` | reCAPTCHA Enterprise site key |
| `$recaptcha_enterprise_api_key` | `''` | reCAPTCHA Enterprise API key |
| `$recaptcha_enterprise_project_id` | `''` | Google Cloud project ID |
| `$api_enabled` | `true` | Enable the `/api/v1/` REST API |
| `$api_rate_limit` | `60` | Requests per minute per IP (0 = disabled) |
| `$api_keys` | `[]` | Optional array of API keys; if non-empty, requests must include `X-Api-Key` |
| `$api_key_rate_limits` | `[]` | Per-key rate limit overrides (`['key' => rpm]`) |
| `$api_endpoint_allowlist` | `[]` | Restrict which endpoints are accessible (empty = all allowed) |

## Example

```php
<?php
$canonical_url    = 'https://subnet.example.com/';
$frame_ancestors  = "'self' https://intranet.example.com";
$session_enabled  = true;
$session_ttl_days = 90;
$default_tab      = 'vlsm';
$split_max_subnets = 512;
$form_protection  = 'turnstile';
$turnstile_site_key    = 'your-site-key';
$turnstile_secret_key  = 'your-secret-key';
```
