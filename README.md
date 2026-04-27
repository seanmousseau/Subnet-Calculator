<h1 align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="logo/logo-dark.webp">
    <source media="(prefers-color-scheme: light)" srcset="logo/logo-light.webp">
    <img width="120" src="logo/logo-dark.webp" alt="Subnet Calculator">
  </picture>
  <br>
  Subnet Calculator
</h1>

<p align="center">
  A lightweight, web-based subnet calculator written in PHP supporting both IPv4 and IPv6.
</p>

<p align="center">
  <a href="https://subnetcalculator.app"><strong>Live app</strong></a> ·
  <a href="https://docs.subnetcalculator.app/"><strong>Documentation</strong></a> ·
  <a href="CHANGELOG.md"><strong>Changelog</strong></a>
</p>

## Features

**IPv4**
- Accepts netmask in **CIDR** (`/24`, `24`) or **dotted-decimal** (`255.255.255.0`) notation
- **Supernet / route summarisation** — find the smallest enclosing prefix for a set of CIDRs; reduce a list to its minimal covering set
- Outputs: Subnet CIDR, Netmask (CIDR & Octet), Wildcard Mask, First/Last Usable IP, Broadcast IP, Usable IPs, Address Type badge, Reverse DNS Zone
- Handles edge cases: `/0`, `/31` (point-to-point), `/32` (host route)
- Paste a full CIDR string (e.g. `192.168.1.0/24`) into the IP field — it auto-splits on blur
- Binary representation — collapsible section showing network and host bits with colour coding; also displays the network address in **hex** (e.g. `C0.A8.01.00`) and **decimal** (e.g. `3232235776`)
- Subnet splitter — split into equal smaller subnets; per-row copy buttons; split prefix included in shareable URL; ASCII diagram export
- **IP range → CIDR conversion** — enter a start and end IP to get the minimal covering CIDR list

**IPv6**
- CIDR prefix input (`/64`, `64`)
- Outputs: Network CIDR, Prefix Length, First IP, Last IP, Total Addresses, Reverse DNS Zone, **expanded address** (all 8 groups, zero-padded), **compressed address** (RFC 5952 notation)
- Total Addresses shown as a number for small prefixes (≤ /108) and as `2^N` for larger ones
- Uses PHP GMP extension for 128-bit arithmetic
- Subnet splitter with per-row copy buttons; Copy All button
- Binary/hex representation — collapsible 128-bit view with network/host bit colour coding at nibble boundary
- **ULA prefix generator (RFC 4193)** — generate a random `/48` ULA prefix from a 40-bit global ID; shows example `/64` subnets

**VLSM**
- Allocate variable-length subnets from a parent network to meet named host requirements
- Requirements sorted largest-first automatically; subnets allocated contiguously with block-boundary alignment
- Results table shows: Name, Hosts Needed, Allocated Subnet (click to copy), Usable IPs, Waste
- Utilisation summary: Hosts Requested, Allocated, Remaining, Utilisation %
- Add or remove requirement rows dynamically; supports any number of subnets
- Shareable URL — GET parameters auto-populate and run the planner on page load
- Export CSV / JSON / XLSX — download full results in any of three formats
- **ASCII diagram export** — copy a Unicode box-drawing tree of the allocated subnets
- Client-side validation with inline errors; loading state on submit
- **Session save/restore** (opt-in) — save VLSM state to SQLite and restore via a short shareable URL

**Subnet Overlap Checker**
- Compare any two IPv4 or IPv6 CIDRs and report their relationship: no overlap, identical, or containment (A contains B / B contains A)
- Multi-CIDR pairwise overlap — paste up to 50 CIDRs and get a list of all conflicting pairs

**General**
- IPv4 / IPv6 / VLSM tab switcher
- Reset button to clear inputs and results
- Click any result row or subnet to copy to clipboard (with `execCommand` fallback for cross-origin iframes)
- Light/dark mode toggle with `localStorage` persistence; defaults to OS `prefers-color-scheme`
- Shareable URL bar — copy a link that auto-populates and calculates on load; splitter state included
- Form protection: honeypot, Cloudflare Turnstile, hCaptcha, or Google reCAPTCHA Enterprise (configurable)
- All colours configured via CSS custom properties; optional `$fixed_bg_color` override
- iframe-friendly mode with automatic height reporting via `postMessage`
- External CSS (`assets/app.css`) and JS (`assets/app.js`); modular PHP structure (`includes/`, `templates/`); external config via `config.php`
- **Offline mode** — a Service Worker caches the app shell so it continues to work without a network connection after the first visit
- **Tool Drawer** (v2.8.0) — a slide-in toolbar panel groups sub-tools (subnet splitter, supernet/summarisation, IP range → CIDR, subnet allocation tree, overlap checker, ULA generator) so the main interface stays uncluttered

## Requirements

- PHP 8.1+
- PHP GMP extension (for IPv6 — `php-gmp`)
- PHP SQLite3 extension (for session persistence — `php-sqlite3`; optional)

## Usage

The application lives in the `Subnet-Calculator/` subfolder. Serve that directory with any PHP-capable web server:

```bash
# Built-in PHP server
php -S localhost:8080 -t Subnet-Calculator/

# Or point Apache/Nginx/Caddy docroot at Subnet-Calculator/
```

Then open `http://localhost:8080` in your browser.

### Docker

A `docker-compose.yml` is included for containerised deployments:

```bash
docker compose up --build
```

The app is served on port `8080` by default. Mount a `config.php` into the container to override configuration.

## Configuration

Copy `Subnet-Calculator/config.php.example` to `Subnet-Calculator/config.php` and edit as needed. `config.php` is excluded from git and is never overwritten by upgrades.

All tuneable values with their defaults:

| Variable | Default | Description |
|----------|---------|-------------|
| `$locale` | `'en'` | BCP 47 locale tag for thousands-separator formatting of host counts (e.g. `'de'`, `'fr'`). Uses PHP `intl` `NumberFormatter`; falls back to comma separators when `intl` is unavailable or locale is `'en'`. |
| `$fixed_bg_color` | `'null'` | Pin the page background to a hex colour (e.g. `'#1a1a2e'`) regardless of light/dark mode. Leave as `'null'` to use the theme default. |
| `$default_tab` | `'ipv4'` | Active tab on page load: `'ipv4'`, `'ipv6'`, or `'vlsm'`. |
| `$split_max_subnets` | `16` | Maximum number of subnets shown in the subnet splitter results list (1–256). |
| `$form_protection` | `'none'` | Form protection mode: `'none'`, `'honeypot'`, `'turnstile'`, `'hcaptcha'`, or `'recaptcha_enterprise'`. |
| `$turnstile_site_key` | `''` | Cloudflare Turnstile site key (required when `$form_protection = 'turnstile'`). |
| `$turnstile_secret_key` | `''` | Cloudflare Turnstile secret key — **never exposed in HTML**. |
| `$hcaptcha_site_key` | `''` | hCaptcha site key (required when `$form_protection = 'hcaptcha'`). |
| `$hcaptcha_secret_key` | `''` | hCaptcha secret key — **never exposed in HTML**. |
| `$recaptcha_enterprise_site_key` | `''` | reCAPTCHA Enterprise site key (required when `$form_protection = 'recaptcha_enterprise'`). |
| `$recaptcha_enterprise_api_key` | `''` | reCAPTCHA Enterprise server API key — **never exposed in HTML**. |
| `$recaptcha_enterprise_project_id` | `''` | GCP project ID for reCAPTCHA Enterprise. |
| `$recaptcha_score_threshold` | `0.5` | Minimum reCAPTCHA Enterprise score to allow submission (`0.0`–`1.0`). |
| `$page_title` | `'Subnet Calculator'` | Page title shown in the browser tab and `<h1>` heading. |
| `$page_description` | `'Free online…'` | Used in `<meta name="description">` and `og:description` for share previews. |
| `$show_share_bar` | `true` | Show or hide the shareable URL bar below results. Set to `false` when embedding in an iframe. |
| `$frame_ancestors` | `'*'` | Origins permitted to embed the page in an iframe (`frame-ancestors` CSP directive). Use `"'none'"` to block all embedding, or a space-separated list of origins. |
| `$api_tokens` | `[]` | Bearer tokens that authorise REST API requests. Empty array = open API (no auth required). |
| `$api_rate_limit_rpm` | `60` | Maximum API requests per IP per minute (sliding window). `0` = disabled. |
| `$api_rate_limit_tokens` | `[]` | Per-token RPM overrides: `['token' => rpm]`. `0` = unlimited for that token. |
| `$api_allowed_endpoints` | `[]` | Endpoint allowlist. Empty = all endpoints available. Non-empty = only listed endpoints are accessible; unlisted endpoints return 404. The meta endpoint (`GET /api/v1/`) is always reachable regardless of this setting. |
| `$api_request_log` | `false` | Log API requests to SQLite for analytics/abuse review. |
| `$api_request_log_db_path` | `'data/api_requests.db'` | Path to the API request log SQLite database (relative to docroot). |
| `$api_cors_origins` | `'*'` | `Access-Control-Allow-Origin` header value for API responses. |
| `$session_enabled` | `false` | Enable SQLite-backed VLSM session save/restore. Requires `php-sqlite3`. |
| `$session_db_path` | `''` | Absolute path to the SQLite database file. Leave empty to auto-place at `<docroot>/../data/sessions.sqlite`. |
| `$session_ttl_days` | `30` | Days before a saved session expires and is purged. |

## REST API

The REST API is available at `/api/v1/`. A `GET /api/v1/` request returns the endpoint list.

All POST endpoints accept and return JSON. Responses are enveloped:

```json
{"ok": true, "data": {...}}
{"ok": false, "error": "..."}
```

**Endpoints:**

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/v1/` | Returns API version and list of available endpoints |
| `POST` | `/api/v1/ipv4` | IPv4 subnet calculation |
| `POST` | `/api/v1/ipv6` | IPv6 subnet calculation |
| `POST` | `/api/v1/vlsm` | VLSM allocation |
| `POST` | `/api/v1/overlap` | Overlap / containment check (IPv4 or IPv6) |
| `POST` | `/api/v1/split/ipv4` | Split an IPv4 subnet |
| `POST` | `/api/v1/split/ipv6` | Split an IPv6 subnet |
| `POST` | `/api/v1/supernet` | Find supernet or summarise routes |
| `POST` | `/api/v1/ula` | Generate IPv6 ULA prefix |
| `POST` | `/api/v1/sessions` | Save VLSM session (requires `$session_enabled = true`) |
| `GET` | `/api/v1/sessions/{id}` | Restore VLSM session |
| `POST` | `/api/v1/range/ipv4` | Convert an IP range to minimal CIDR list |
| `POST` | `/api/v1/tree` | Build subnet allocation tree from parent + child CIDRs |
| `GET` | `/api/v1/changelog` | Returns the application CHANGELOG as plain text |
| `POST` | `/api/v1/bulk` | Run up to 50 operations in a single request |

See `api/openapi.yaml` for the full OpenAPI 3.1 specification.

### PHP Client Library

A lightweight PHP wrapper is bundled at `clients/php/SubnetCalculatorClient.php`. Copy it into your project — no Composer required:

```php
require 'SubnetCalculatorClient.php';
use SubnetCalculator\SubnetCalculatorClient;

$client = new SubnetCalculatorClient('https://example.com/subnet-calculator/');
$result = $client->calcIpv4('192.168.1.0/24');
echo $result['data']['network_cidr'];
```

## Downloads

Pre-built release archives are available in `releases/`:

| Version | File | Description |
|---------|------|-------------|
| 2.9.2 | [subnet-calculator-2.9.2.tar.gz](releases/subnet-calculator-2.9.2.tar.gz) | Light + dark logo variants; adaptive favicon (prefers-color-scheme); apple-touch-icon WebP fix; refreshed README header |
| 2.9.1 | [subnet-calculator-2.9.1.tar.gz](releases/subnet-calculator-2.9.1.tar.gz) | Bug fixes: VLSM dark inputs, mobile toggle clip, IPv6 overflow, logo transparency, favicon cache-bust |
| 2.9.0 | [subnet-calculator-2.9.0.tar.gz](releases/subnet-calculator-2.9.0.tar.gz) | UI/UX overhaul: teal accent, Space Grotesk/Plus Jakarta Sans/Fira Code fonts, mobile fixes |
| 2.8.1 | `releases/subnet-calculator-2.8.1.tar.gz` | |
| 2.8.0 | `releases/subnet-calculator-2.8.0.tar.gz` | |
| 2.7.0 | `releases/subnet-calculator-2.7.0.tar.gz` | |
| 2.6.0 | `releases/subnet-calculator-2.6.0.tar.gz` | |
| 2.5.0 | `releases/subnet-calculator-2.5.0.tar.gz` | |
| 2.4.1 | `releases/subnet-calculator-2.4.1.tar.gz` | |
| 2.4.0 | `releases/subnet-calculator-2.4.0.tar.gz` | |
| 2.3.0 | `releases/subnet-calculator-2.3.0.tar.gz` | |
| 2.2.0 | `releases/subnet-calculator-2.2.0.tar.gz` | |
| 2.1.0 | `releases/subnet-calculator-2.1.0.tar.gz` | |
| 2.0.1 | `releases/subnet-calculator-2.0.1.tar.gz` | |
| 2.0.0 | `releases/subnet-calculator-2.0.0.tar.gz` | |
| 1.3.0 | `releases/subnet-calculator-1.3.0.tar.gz` | |
| 1.2.0 | `releases/subnet-calculator-1.2.0.tar.gz` | |
| 1.1.1 | `releases/subnet-calculator-1.1.1.tar.gz` | |
| 1.1.0 | `releases/subnet-calculator-1.1.0.tar.gz` | |
| 1.0.1 | `releases/subnet-calculator-1.0.1.tar.gz` | |
| 1.0.0 | `releases/subnet-calculator-1.0.0.tar.gz` | |
| 0.12.0 | `releases/subnet-calculator-0.12.0.tar.gz` | |
| 0.11.0 | `releases/subnet-calculator-0.11.0.tar.gz` | |
| 0.10.0 | `releases/subnet-calculator-0.10.0.tar.gz` | |
| 0.9.0 | `releases/subnet-calculator-0.9.0.tar.gz` | |
| 0.8.0 | `releases/subnet-calculator-0.8.0.tar.gz` | |

Each archive contains the app files at the root level. Extract directly into your webroot to install or upgrade in place:

```bash
tar -xzf subnet-calculator-2.9.0.tar.gz -C /var/www/html/subnet-calculator/
```

## Embedding

The calculator automatically detects when it is running inside an iframe — no configuration is required. When embedded it removes body margins and padding, and reports its height to the parent page via `postMessage` so the iframe can resize to fit its content without scrollbars.

### Basic embed (auto-resize only)

```html
<div style="width:100%; max-width:1200px; margin:0 auto;">
  <iframe
    id="scFrame"
    src="https://your-domain.com/sc/index.php"
    width="100%"
    scrolling="no"
    allow="clipboard-write"
    style="border:none; display:block; height:0;"
    loading="lazy">
  </iframe>
</div>
<script>
window.addEventListener('message', function (e) {
  if (e.data && e.data.type === 'sc-resize') {
    document.getElementById('scFrame').style.height = e.data.height + 'px';
  }
});
</script>
```

- Start the iframe at `height:0` — the calculator sends its real height as soon as it loads and on every content change (form submit, tab switch, results shown/cleared).
- `allow="clipboard-write"` grants clipboard access inside the iframe. An `execCommand` fallback is included for browsers that block this.
- The resize listener handles all subsequent height changes automatically — no polling or manual measurement needed.

### Embed with custom background colour

The parent page can set the calculator's background colour at runtime by sending a `sc-set-bg` postMessage **after** the iframe has finished loading. Sending the message before the iframe loads means the calculator's listener isn't running yet and the message is silently dropped — use the `load` event to guarantee timing.

```html
<div style="width:100%; max-width:1200px; margin:0 auto;">
  <iframe
    id="scFrame"
    src="https://your-domain.com/sc/index.php"
    width="100%"
    scrolling="no"
    allow="clipboard-write"
    style="border:none; display:block; height:0;"
    loading="lazy">
  </iframe>
</div>
<script>
var scFrame = document.getElementById('scFrame');

// Auto-resize: update iframe height whenever the calculator reports a change
window.addEventListener('message', function (e) {
  if (e.data && e.data.type === 'sc-resize') {
    scFrame.style.height = e.data.height + 'px';
  }
});

// Set background colour AFTER the iframe has fully loaded.
// Do not call postMessage here — the iframe's listener isn't running yet.
scFrame.addEventListener('load', function () {
  scFrame.contentWindow.postMessage({
    type: 'sc-set-bg',
    color: '#ffffff'  // any 3, 4, 6, or 8-digit CSS hex colour
  }, '*');
});
</script>
```

To revert to the calculator's default theme background (dark or light depending on the visitor's preference), pass `null` as the colour:

```javascript
scFrame.contentWindow.postMessage({ type: 'sc-set-bg', color: null }, '*');
```

Only valid CSS hex colours (`#rgb`, `#rgba`, `#rrggbb`, `#rrggbbaa`) are accepted. Invalid values are silently ignored. This works independently of the server-side `$fixed_bg_color` config option.

## Example

| Input | Value |
|-------|-------|
| IP Address | `192.168.1.50` |
| Netmask | `255.255.255.0` or `/24` |

**IPv4**

| Output | Value |
|--------|-------|
| Subnet (CIDR) | `192.168.1.0/24` |
| Netmask (CIDR) | `/24` |
| Netmask (Octet) | `255.255.255.0` |
| Wildcard Mask | `0.0.0.255` |
| First Usable IP | `192.168.1.1` |
| Last Usable IP | `192.168.1.254` |
| Broadcast IP | `192.168.1.255` |
| Usable IPs | `254` |

**IPv6**

| Input | Value |
|-------|-------|
| IPv6 Address | `2001:db8::1` |
| Prefix | `/64` |

| Output | Value |
|--------|-------|
| Network (CIDR) | `2001:db8::/64` |
| Prefix Length | `/64` |
| First IP | `2001:db8::` |
| Last IP | `2001:db8::ffff:ffff:ffff:ffff` |
| Total Addresses | `2^64` |

## Versioning

This project uses [Semantic Versioning](https://semver.org/).

| Version | Notes |
|---------|-------|
| 2.5.0 | Accessibility & UX hardening: skip link, `<main>` landmark, input focus rings, button focus-visible, light mode color contrast, prefers-reduced-motion, help bubble touch target + keyboard access, VLSM keyboard Delete; 6 new a11y test groups (529 assertions) |
| 2.4.1 | Patch: supernet tooltip fix (moved out of button elements), PHP type correctness fixes (`ip2long`/`inet_pton`/`unpack` false-return handling), PHPStan expanded to all handler and function files, Semgrep rules, 4 new Playwright test groups (517 assertions) |
| 2.4.0 | `$locale` config for locale-aware number formatting, ESLint + Stylelint tooling, OpenAPI example responses, CSP inline-style fix, tooltip visual polish, VLSM print stylesheet, mobile overflow fix |
| 2.3.0 | IP range → CIDR conversion, subnet allocation tree view, VLSM JSON/XLSX export, ASCII diagram export, help bubble tooltips, visual regression tests, GitHub Pages documentation site |
| 2.2.0 | hCaptcha and reCAPTCHA Enterprise form protection, per-token API rate limit overrides, `$api_allowed_endpoints` allowlist, IPv4 hex/decimal network address, IPv6 expanded/compressed address forms |
| 2.1.0 | API: reverse DNS zone file generator (`POST /api/v1/rdns`), bulk CIDR calculation (`POST /api/v1/bulk`), VLSM session URL fix, session TTL notice |
| 2.0.1 | Fixes: VLSM `/31` vs `/32` single-host allocation, API supernet whitespace guard, API router bare `GET /sessions` 404 |
| 2.0.0 | JSON REST API (`/api/v1/`), OpenAPI 3.1 spec, supernet / route summarisation, IPv6 ULA prefix generator, VLSM session persistence, PHP 8.1 minimum |
| 1.3.0 | Multi-CIDR pairwise overlap checker, VLSM CSV export, Copy All buttons, VLSM utilisation summary, Playwright E2E test suite |
| 1.2.0 | VLSM planner (variable-length subnet allocation), shareable VLSM URL, dynamic row management |
| 1.1 | WebP logo/favicon, modular file structure (`includes/`, `templates/`, `assets/`), PHP type declarations, `.htaccess` hardening (Apache + OLS), cache headers, `robots.txt`, CSP `unsafe-inline` removed |
| 1.0 | Full ARIA tab pattern, accessible toast/errors/focus, NAT64 detection, canonical URL, share URL no-JS fallback, postMessage origin scoping |
| 0.12 | Print stylesheet, IPv6 badge types, `$frame_ancestors` validation, `X-Frame-Options` header, keyboard accessibility |
| 0.8–0.11 | IPv6 splitter, form protection (Turnstile/honeypot), iframe mode, nonce-based CSP, config.php, security headers |
| 0.1–0.7 | Initial release through subnet splitter, light/dark mode, shareable URLs, IPv6 support |

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

See [SECURITY.md](SECURITY.md).

## License

[AGPL-3.0](LICENSE)
