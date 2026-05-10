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
- **IPv6 VLSM Planner** (v2.11.0) — full parity with the IPv4 planner on the IPv6 tab; GMP-backed allocator supports `2^N` host counts; CSV / JSON / ASCII exports (XLSX intentionally omitted)

**Inverse Subnet Lookup** (v2.11.0)
- Paste a list of CIDRs and a list of IPs — the tool returns every CIDR that contains each IP plus the deepest (longest-prefix) match
- Supports mixed IPv4 / IPv6 input; shareable GET URLs
- Available on both the IPv4 and IPv6 tabs via the tool drawer

**Subnet Aggregation Diff** (v2.11.0)
- Compare a "before" and "after" CIDR list and see what was added, removed, kept, or had its prefix length changed
- Each input is canonicalised (host bits zeroed; IPv6 lowercased + compressed) before comparison
- Colour-coded result groups; shareable GET URLs

**Copy-as-Markdown / Copy-as-Cisco** (v2.11.0)
- IPv4, IPv6, VLSM, and splitter result panels gain `Copy as Markdown` and `Copy as Cisco` buttons
- Cisco output is generic IOS-style — adapt to your platform as needed

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

- PHP 8.2, 8.3, or 8.4 (PHP 8.1 still runs the app but dev tooling — PHPUnit 11, PHPStan 2 — requires 8.2+)
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
| `$admin_ui_enabled` | `false` | Enable the `/admin/` web UI and `/api/v1/admin/*` JSON endpoints for API key management (v2.12.0). |
| `$admin_user` | `''` | Admin username for HTTP Basic Auth on `/admin/`. |
| `$admin_pass_hash` | `''` | Bcrypt hash of the admin password. Generate with `php -r "echo password_hash('pwd', PASSWORD_BCRYPT);"`. |
| `$apikey_db_path` | `''` | Optional SQLite path for the `api_keys` table; defaults to the sessions DB. |

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
| `POST` | `/api/v1/vlsm6` | IPv6 VLSM allocation (v2.11.0) |
| `POST` | `/api/v1/lookup` | Inverse subnet lookup — find which CIDRs contain each IP (v2.11.0) |
| `POST` | `/api/v1/diff` | Subnet aggregation diff — added / removed / changed / unchanged (v2.11.0) |
| `POST` | `/api/v1/wildcard` | Bidirectional wildcard ↔ CIDR converter |

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
| --- | --- | --- |
| 3.6.4 | [subnet-calculator-3.6.4.tar.gz](releases/subnet-calculator-3.6.4.tar.gz) | **Cleanup patch (2026-05-09).** Defense-in-depth: `cidrs_overlap()` validates inputs explicitly (throws `InvalidArgumentException` on missing slash / invalid IPv4 / out-of-range prefix). Shareable VLSM session IDs widened from 32-bit (8 hex) to 64-bit (16 hex) keyspace. **BREAKING:** pre-v3.6.4 shareable URLs return 404. (#423 #428) `CACHE_NAME` → `sc-v3.6.4` |
| 3.6.3 | [subnet-calculator-3.6.3.tar.gz](releases/subnet-calculator-3.6.3.tar.gz) | **Security patch (2026-05-09).** PMTU `payload_size` capped at 65535 (was unbounded — memory-amplification DoS); HSTS header on HTTPS responses; CORS allowlist via `$api_cors_origins`; bulk endpoint rate-limit charged per item; rate-limit fail-closed on SQLite errors; XFF only honored from `$api_trusted_proxies`; SSM/embedded-RP decoders reject scope=0. (#424 #425 #426 #427) `CACHE_NAME` → `sc-v3.6.3` |
| 3.6.2 | [subnet-calculator-3.6.2.tar.gz](releases/subnet-calculator-3.6.2.tar.gz) | **Patch.** Fixes a P1 functional bug in the IPv4 overlap checker: `cidrs_overlap()` used `max()` where `min()` was correct, causing `/api/v1/overlap` to return `"none"` for legitimate containment whenever the contained network had non-zero host bits within the prefix-difference range (e.g. `10.0.0.0/24` vs `10.0.0.128/25`). Bug shipped since v1.2.0; existing tests passed because every pair shared the `10.0.0.0` base. (#421) `CACHE_NAME` → `sc-v3.6.2` |
| 3.6.1 | [subnet-calculator-3.6.1.tar.gz](releases/subnet-calculator-3.6.1.tar.gz) | **Patch.** Code-quality cleanup carried over from v3.6.0 review: SSM tool drops unreachable negative-`prefix_length` check, PMTU extension-header errors split into distinct messages, `ssm6` / `embedded-rp6` bulk adapters enforce field ranges (scope 1..15, riid 0..15, rp_prefix_length 0..64, group_id 0..2^32-1) at the API boundary. `CACHE_NAME` → `sc-v3.6.1` |
| 3.6.0 | [subnet-calculator-3.6.0.tar.gz](releases/subnet-calculator-3.6.0.tar.gz) | **IPv6 Multicast.** Fourth and final release in the IPv6-themed minor roadmap. Four new IPv6-tab drawer entries with REST endpoints, shareable URLs, Playwright coverage, and a11y assertions: multicast scope decoder (front door), SSM / unicast-prefix-based (RFC 3306), embedded-RP (RFC 3956), and PMTU helper (RFC 8200). Plus a11y audit and bulk endpoint expansion. `CACHE_NAME` → `sc-v3.6.0` |
| 3.5.1 | [subnet-calculator-3.5.1.tar.gz](releases/subnet-calculator-3.5.1.tar.gz) | **Patch.** Code-quality cleanup carried over from v3.5.0 review: ISATAP auto-classify now rejects multicast/class-E/CGN/TEST-NET/benchmarking ranges, NAT64 handler enforces the `{32,40,48,56,64,96}` prefix-length set, `plan_prefix_delegation()` `child_length` lower bound matches the handler (1..128), plus docblock corrections on `ipv6_in_prefix()` and `rfc3531_allocation_order()`. `CACHE_NAME` → `sc-v3.5.1` |
| 3.5.0 | [subnet-calculator-3.5.0.tar.gz](releases/subnet-calculator-3.5.0.tar.gz) | **IPv6 Prefix Planning + Transition.** Nine new IPv6-tab drawer entries with REST endpoints, shareable URLs, Playwright coverage, and a11y assertions: embedded-v4 detector, 6to4 (RFC 3056), Teredo decoder (RFC 4380), ISATAP IID helper (RFC 5214), 6rd (RFC 5969), NAT64/DNS64 (RFC 6052/6146/6147), IPv6 prefix-delegation planner, nibble-boundary helper, and RFC 3531 sparse-allocation guidance. Plus a11y audit across all 9 new drawers and bulk endpoint expansion. `CACHE_NAME` → `sc-v3.5.0` |
| 3.4.1 | [subnet-calculator-3.4.1.tar.gz](releases/subnet-calculator-3.4.1.tar.gz) | **Patch.** Removes stale duplicate / by-reference docblocks left over from v3.4.0's `request.php` per-tool array refactor (CodeRabbit cleanup). No code-behaviour change. `CACHE_NAME` → `sc-v3.4.1` |
| 3.4.0 | [subnet-calculator-3.4.0.tar.gz](releases/subnet-calculator-3.4.0.tar.gz) | **IPv6 Polish, Architecture, Parity.** 12-PR release closing v3.3.0 carry-forward debt, adding 4 architecture items (a11y audit across 5 IPv6 drawers, bulk endpoint expansion to v3.3.0/v3.4.0 IPv6 tools, IPv6 type-detection extracted into `functions-type6.php`, per-tool URL routes `/ipv4/<tool>` and `/ipv6/<tool>`), and shipping 2 new IPv6 tools: `rdns6` (IPv6 reverse-DNS PTR zone, `POST /api/v1/rdns6`) and `mapped6` (IPv4-mapped IPv6 + NAT64 synthesis per RFC 6052, `POST /api/v1/mapped6`). `request.php` flat per-tool globals collapsed into per-tool arrays across 14 tools; `copy_button()` template helper extracted alongside `help_bubble()`; `range_to_cidrs()` accepts cap as optional parameter (proper DI). `CACHE_NAME` → `sc-v3.4.0` |
| 3.3.0 | [subnet-calculator-3.3.0.tar.gz](releases/subnet-calculator-3.3.0.tar.gz) | IPv6 Foundations. Five new IPv6-native tools land on the IPv6 tab: range → CIDR converter, supernet finder + summariser, zone-ID parser, combined MAC derivation (EUI-64 + link-local + solicited-node, RFC 4291 §2.5.1), and SLAAC privacy address generator (RFC 8981). All five ship with REST API endpoints, shareable URLs, full Playwright coverage, and dedicated docs pages. Also backports a configurable output cap (`$range_max_cidrs`, default 256) to the IPv4 `range_to_cidrs` helper. `CACHE_NAME` → `sc-v3.3.0` |
| 3.2.4 | [subnet-calculator-3.2.4.tar.gz](releases/subnet-calculator-3.2.4.tar.gz) | Tab-bar polish. Adds `white-space: nowrap` to `.tab-btn` so the "VLSM IPv6" tab label stops wrapping to two lines on every viewport. Latent CSS gap surfaced during a v3.3.0 mobile-IA audit; cheap to fix, future-compatible with multi-word tab labels. `CACHE_NAME` → `sc-v3.2.4` |
| 3.2.3 | [subnet-calculator-3.2.3.tar.gz](releases/subnet-calculator-3.2.3.tar.gz) | Admin service-worker hotfix. Earlier `app.js` registered the SW on every page; on `/admin/*` that resolved to a 404'ing `/admin/sw.js`, parking the registration in "trying to install" purgatory that DevTools could not unregister. v3.2.3 skips registration on `/admin/*`, ships a tombstone `/admin/sw.js` that self-unregisters on activate (clears existing stuck installs), and makes the root SW's fetch handler bypass `/admin/*` so calculator caching never touches the admin surface. `CACHE_NAME` → `sc-v3.2.3` |
| 3.2.2 | [subnet-calculator-3.2.2.tar.gz](releases/subnet-calculator-3.2.2.tar.gz) | Service-worker hotfix. Bumps `sw.js` `CACHE_NAME` from the long-stale `sc-v2.7.0` to `sc-v3.2.2` so browsers that registered the SW months ago actually pick up the current shell instead of serving a cached snapshot from v2.7.0. Also adds the `CACHE_NAME` bump to the release checklist in `CLAUDE.md` |
| 3.2.1 | [subnet-calculator-3.2.1.tar.gz](releases/subnet-calculator-3.2.1.tar.gz) | Admin polish on top of v3.2.0: narrow centred sign-in / TOTP card; sidebar suppressed during the TOTP step; `clearstatcache` + `opcache_invalidate` in the layered settings loader fix the "settings revert after re-login" symptom; `config.php`-pinned settings rows are now disabled in the UI with a clearer "locked" badge + top-of-page help card explaining the precedence chain; `.htaccess` blocks direct web access to `config-admin.php` |
| 3.2.0 | [subnet-calculator-3.2.0.tar.gz](releases/subnet-calculator-3.2.0.tar.gz) | **Admin UX overhaul.** Eight PRs (#342–#349) bring `/admin/` under one design language: shared chrome (#345), cookie-session login form (#342), logout button (#343), left sidebar with mobile drawer (#344), audit-log polish — tablist filters, colour badges, sticky thead, accessible pagination (#346); TOTP enrol/disable + recovery-code usage tracking (#347); API-keys post-mint Copy + helpful empty state (#348); new Settings page surfacing 23 config knobs across six sections with schema validators, source-of-truth badges, shadow detection, atomic write, and audit logging (#349) |
| 3.1.3 | [subnet-calculator-3.1.3.tar.gz](releases/subnet-calculator-3.1.3.tar.gz) | Tiny follow-up to v3.1.2: Tree Editor session load now hits `GET /api/v1/sessions/{id}` (path segment) instead of `?session_id=…` so Save Session URLs and the *Load saved session* form actually hydrate the tree |
| 3.1.2 | [subnet-calculator-3.1.2.tar.gz](releases/subnet-calculator-3.1.2.tar.gz) | Tiny follow-up to v3.1.1: forces `display: none` on `.tree-editor-init[hidden]` so the init forms actually disappear once the editor opens (CSS specificity was beating the browser's default `[hidden]` rule) |
| 3.1.1 | [subnet-calculator-3.1.1.tar.gz](releases/subnet-calculator-3.1.1.tar.gz) | Hotfix on top of v3.1.0. Fixes Tree Editor share-URL restore + adds session-ID load form; adds CSP extension hooks (`$csp_connect_extra` / `$csp_script_extra` / `$csp_img_extra`) so operators can allowlist CDN-injected analytics without forking; emits explicit `connect-src`; fixes mobile heading truncation at ≤480px |
| 3.1.0 | [subnet-calculator-3.1.0.tar.gz](releases/subnet-calculator-3.1.0.tar.gz) | **Polish + deferred-from-v3.0.0.** Multi-tree diff for the v3.0.0 tree editor (#322); subnet preset library with 6 starter templates (#323); IPv6 VLSM save-session drawer parity (#321); audit purge strategy (`inline`/`sampled`/`cron`) + `bin/sc-audit-purge.php` CLI (#325); test-rig drains admin state via gated `_test-drain.php` (#324); PHPCS template-aware ruleset for `admin/` (#326); history pane captures tool-drawer outcomes via `data-history-source` (#327); keyboard `1`–`4` digits now focus first input on destination panel (#328) |
| 3.0.0 | [subnet-calculator-3.0.0.tar.gz](releases/subnet-calculator-3.0.0.tar.gz) | **Major release.** Interactive subnet tree editor with click-split, drag-merge, undo/redo, autosave, exports, share URLs (#302); admin first-run wizard (#311); TOTP/2FA + recovery codes for `/admin/` (#313); admin audit log + `/admin/audit.php` (#306); per-key rate-limit overrides (#312); IPv6 VLSM session persistence + schema v2 with `type` discriminator (#315); keyboard shortcut overlay (#300); recent-calculations history pane (#301); expanded `/admin/keys.php` Playwright matrix (#307) |
| 2.12.1 | [subnet-calculator-2.12.1.tar.gz](releases/subnet-calculator-2.12.1.tar.gz) | Hotfix: meta endpoint null-coalesce on `$admin_ui_enabled` to survive opcache-stale window after a v2.12.0 deploy |
| 2.12.0 | [subnet-calculator-2.12.0.tar.gz](releases/subnet-calculator-2.12.0.tar.gz) | API admin UI for self-hosters (`/admin/keys.php`, `/api/v1/admin/keys`) — bcrypt-hashed SQLite-backed API keys; `X-RateLimit-*` response headers exposed in HTTP and OpenAPI; published JSON-Schema for VLSM session payloads (`GET /api/v1/schemas/vlsm-session`) |
| 2.11.0 | [subnet-calculator-2.11.0.tar.gz](releases/subnet-calculator-2.11.0.tar.gz) | IPv6 VLSM planner (UI + `POST /api/v1/vlsm6`); inverse subnet lookup (`POST /api/v1/lookup`); subnet aggregation diff (`POST /api/v1/diff`); copy-as-Markdown and copy-as-Cisco exports |
| 2.10.0 | [subnet-calculator-2.10.0.tar.gz](releases/subnet-calculator-2.10.0.tar.gz) | Wildcard ↔ CIDR converter; sitemap.xml; PHP 8.2–8.4 CI matrix; dark-mode print stylesheet; docs URL → docs.subnetcalculator.app |
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
tar -xzf subnet-calculator-2.12.1.tar.gz -C /var/www/html/subnet-calculator/
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
| 2.12.0 | API admin UI + JSON CRUD for self-hosters (`/admin/keys.php` + `/api/v1/admin/keys`) backed by a bcrypt-hashed SQLite `api_keys` table; rate-limit response headers (`X-RateLimit-Limit`, `-Remaining`, `-Reset`) emitted on every response when rate limiting is active and documented in OpenAPI; published `GET /api/v1/schemas/vlsm-session` JSON-Schema export (Draft 2020-12); 245 PHPUnit tests (was 226) |
| 2.11.0 | IPv6 VLSM planner (UI + `POST /api/v1/vlsm6`, GMP-backed, supports `2^N` host counts); inverse subnet lookup (`POST /api/v1/lookup`) with shareable URLs; subnet aggregation diff (`POST /api/v1/diff`) with canonical-form normalisation; copy-as-Markdown and copy-as-Cisco exports across IPv4/IPv6/VLSM/splitter result panels; footer GitHub link trimmed to "GitHub" |
| 2.10.0 | Wildcard ↔ CIDR converter (`POST /api/v1/wildcard`); sitemap.xml; PHP 8.2–8.4 CI matrix; dark-mode print stylesheet; docs URL → docs.subnetcalculator.app |
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
