# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.1] - 2026-04-08

### Fixed
- IPv6 subnet splitter: new-prefix validation now enforces a minimum of `/1` (was incorrectly allowing `/0`, unlike the IPv4 path) — closes #116
- GET requests with CIDR notation in the IP/address field (e.g. `?ip=192.168.1.0/24`) now trigger CIDR auto-detection correctly; previously a missing `mask` or `prefix` parameter silently produced a blank form — closes #117
- IPv6 split results: `$more_label6` now wrapped in `htmlspecialchars()` for defensive output consistency — closes #118

### Security
- Fallback canonical URL: `HTTP_HOST` header is now validated against `[a-zA-Z0-9.\-]+(:\d+)?` before use, preventing host-header injection into `<link rel="canonical">` and Open Graph meta tags — closes #119

### Changed
- PHP static-analysis review (PHPStan L9, PHPCS, PHPMD, PHP Depend): `curl_close()` added after Turnstile verification, unreachable `< 0` guard removed from IPv6 prefix validation, `$canonical_url` annotated as pre-encoded for template authors — closes #120
- Logo served via `<picture>` element with `assets/logo.png` PNG fallback for Safari <14 (`assets/logo.png` added) — closes #111
- Favicon PNG fallback added (`assets/favicon-32.png`, `<link rel="icon" type="image/png">`) for browsers without WebP favicon support — closes #106

## [1.1.0] - 2026-04-07

### Added
- Optimized WebP logo (`logo/logo.webp`) replaces the embedded-PNG SVG (`logo.svg`), significantly reducing page weight; served via `<picture>` element with `logo/logo.png` fallback for Safari <14 — closes #104, #111
- Dedicated favicon: `logo/favicon-32.webp` (primary) + `logo/favicon-32.png` fallback — closes #106
- `og:image` and `twitter:card` meta tags complete Open Graph support — closes #109
- Cache-control headers for static assets (WebP, PNG, CSS, JS) via `.htaccess` `mod_expires` + `mod_headers`; `max-age=31536000, immutable` — closes #110
- `robots.txt` disallowing `includes/`, `templates/`, `assets/`, and config files from crawlers — closes #113
- `assets/app.css` and `assets/app.js` extracted from inline template; CSP `style-src` and `script-src` updated to `'self' 'nonce-...'`, removing `unsafe-inline` — closes #114

### Changed
- Application split from a single `index.php` into `includes/` (config, functions, request handling) and `templates/layout.php`; all include files use `declare(strict_types=1)` and full parameter/return type declarations — closes #107, #112
- `.htaccess` hardened: blocks `config.php.example` and deployment tarballs (`.tar.gz`, `.zip`, etc.); subdirectory `.htaccess` files added to `includes/`, `templates/`; Apache 2.4+ and OpenLiteSpeed compatibility ensured via dual `<FilesMatch>`/`RewriteRule [F]` approach — closes #108

## [1.0.1] - 2026-04-07

### Fixed
- iframe auto-sizing: `postMessage` target origin derived from `document.referrer` was corrupted after same-origin form navigation inside the iframe, causing a `DOMException` and breaking height reporting. Fix uses `window.location.ancestorOrigins` (Chrome/Edge) and a `sessionStorage` fallback (Firefox) to reliably track the parent frame's origin across navigations — closes #102

## [1.0.0] - 2026-04-07

### Added
- Full ARIA tab semantics: `role="tablist"`, `role="tab"`, `role="tabpanel"`, `aria-selected`, `aria-controls`, `aria-labelledby`; Left/Right arrow key navigation between tabs — closes #86
- `aria-label` on theme toggle button (dynamic: describes next action); updates on each toggle — closes #88
- `role="status"`, `aria-live="polite"`, `aria-atomic="true"` on the copy-confirmation toast so screen readers announce copy actions — closes #87
- `:focus-visible` outline ring on result rows and split items for keyboard navigation — closes #89
- `aria-invalid="true"` and `aria-describedby` on form inputs when validation fails; error `<div>` elements given unique `id`s — closes #90
- NAT64 well-known prefix (`64:ff9b::/96`) and local-use NAT64 (`64:ff9b:1::/48`) now identified as distinct IPv6 address types with blue badge — closes #93
- `<link rel="canonical">` and `og:url` meta tag auto-detected from `$_SERVER`; overridable via new `$canonical_url` config variable — closes #94
- Server-side absolute share URL fallback for no-JS users; JS continues to override with `window.location` for reverse-proxy accuracy — closes #91
- Turnstile cURL availability check: when `$form_protection = 'turnstile'` but `curl_init` is not available, an HTML comment warning is emitted; `config.php.example` documents the requirement — closes #95

### Changed
- iframe `postMessage` now targets `document.referrer`'s origin instead of `'*'`; `sc-set-bg` message listener validates sender origin — closes #85
- IPv4 split "more" counter now uses `$split_result['showing']` instead of `$split_max_subnets` for semantic correctness and consistency with IPv6 splitter; formatting fixed to `+&nbsp;` — closes #92

## [0.12] - 2026-04-05

### Added
- `X-Frame-Options` fallback header: emits `DENY` or `SAMEORIGIN` when `$frame_ancestors` is set to `'none'` or `'self'` respectively, providing iframe embedding protection for browsers that don't support CSP `frame-ancestors` (e.g. IE11) — closes #83
- Keyboard accessibility for copy targets: result rows and split items now have `tabindex="0"`, `role="button"`, and respond to Enter/Space keydown events, making copy-to-clipboard available to keyboard-only and assistive technology users — closes #81

### Changed
- IPv6 panel HTML consolidated into a single `if ($result6)` block, matching the structure of the IPv4 panel — closes #82

### Fixed
- Print stylesheet: `.tab-row` corrected to `.tabs`; `.panel` changed to `.panel.active` so only the active panel prints; `body { min-height: 0 }` added to prevent excess whitespace — closes #77
- IPv6 transition mechanism badge types (`IPv4-mapped`, `Teredo`, `6to4`) now correctly map to the blue `doc` badge class instead of falling through to generic grey — closes #78

### Security
- `$frame_ancestors` config value is now validated against a whitelist pattern (origins, `*`, `'none'`, `'self'`); invalid values are rejected and reset to `*` with an error_log warning, preventing CSP header injection — closes #79
- `$form_protection` and `$default_tab` config values are now validated; invalid values are reset to safe defaults with an error_log warning — closes #80

## [0.11] - 2026-04-04

### Added
- `$frame_ancestors` config variable: control which origins may embed the page in an iframe via the `frame-ancestors` CSP directive (default `*`); useful to lock down embedding to specific domains — closes #73
- `$page_description` config variable: sets `<meta name="description">`, `og:description`, and `og:title` Open Graph tags for richer share previews — closes #75
- Print stylesheet: hides navigation, buttons, share bar, and splitter form; forces white background and black text; preserves badge colours via `print-color-adjust: exact`; splits subnet list into 2 columns — closes #74

### Changed
- Logo size increased from 32 × 32 px to 48 × 48 px for better visibility alongside the app title — closes #72

### Fixed
- iframe height now correctly shrinks after the Reset button is pressed: `postHeight()` is now called via `requestAnimationFrame` (after first paint) and again on `window.load` (after all resources), ensuring the measurement reflects the settled post-reset layout — closes #71

## [0.10] - 2026-04-04

### Added
- `$page_title` config variable: set the browser tab title and `<h1>` heading via `config.php` without touching `index.php` — closes #67
- `$show_share_bar` config variable: set to `false` to hide the shareable URL bar below results; useful when embedding in an iframe — closes #68
- IPv4 address type `Benchmarking` for `198.18.0.0/15` (RFC 2544) — closes #66
- IPv4 address type `IETF Reserved` for `192.0.0.0/24` (RFC 6890) — closes #66
- Explicit `type_badge_class()` entries for `Reserved`, `Broadcast`, `Unspecified`, `This Network`, `Benchmarking`, and `IETF Reserved` — previously these all fell through to the generic grey `other` badge

### Security
- CSP `script-src` and `style-src` now use a per-request cryptographic nonce instead of `'unsafe-inline'`; browsers that support nonces enforce nonce-only execution, preventing injected scripts and styles from running — closes #65

## [0.9] - 2026-04-04

### Added
- Iframe background colour: parent page can now send `{ type: 'sc-set-bg', color: '#rrggbb' }` via `postMessage` to change the calculator background at runtime without a server-side config change — closes #59

### Changed
- Turnstile server-side verification now uses `curl` instead of `file_get_contents()`, removing the silent breakage when `allow_url_fopen = Off`; if `curl` is also unavailable a warning is logged and verification is skipped (fail-open) — closes #62
- iframe height polling timer (300 ms × 20) now only starts in browsers without `ResizeObserver` support; modern browsers rely solely on the `ResizeObserver` already in place — closes #63
- `postMessage` target origin changed to `'*'` for `sc-resize` (height-only payload, no sensitive data); was incorrectly scoped to `document.referrer` in v0.8, silently dropping all messages on sites with CDN or redirect layers — closes #53

### Fixed
- When `$default_tab = 'ipv6'`, submitting the IPv4 form now correctly shows IPv4 results; the previous ternary fell back to `$default_tab` instead of a hard `'ipv4'` — closes #60
- IPv4 shareable URL now includes `tab=ipv4`; previously omitting the parameter caused the link to open the IPv6 tab when `$default_tab = 'ipv6'` — closes #61

### Security
- `Content-Security-Policy` now includes `base-uri 'self'` to prevent `<base>` tag injection attacks — closes #64

## [0.8] - 2026-04-04

### Added
- IPv6 subnet splitter — enter a larger prefix to split the current IPv6 subnet into equal subnets (same UI as IPv4 splitter, handles large splits gracefully with `2^N` notation) — closes #57
- Form protection system: `'none'` (default), `'honeypot'` (hidden URL field), or `'turnstile'` (Cloudflare Turnstile with server-side verification) — closes #45
- CGNAT address type detection in IPv4 results (`100.64.0.0/10`, RFC 6598) — closes #50
- External config file: copy `config.php.example` to `config.php` to override defaults without touching `index.php`; defaults loaded automatically if `config.php` is absent — closes #46
- `.htaccess` blocks direct HTTP access to `config.php` via Apache `<Files>` directive and a `mod_rewrite` `[F]` fallback for LiteSpeed — closes #46
- HTTP security headers: `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Content-Security-Policy` with `frame-ancestors *` and conditional Turnstile script allowlist — closes #52
- Release bundle `releases/subnet-calculator-0.8.0.tar.gz` containing all app files from `Subnet-Calculator/` — closes #48

### Changed
- App files (`index.php`, `logo.svg`) moved into `Subnet-Calculator/` subfolder; repo root now contains only documentation and tooling — closes #47
- `navigator.clipboard.writeText()` now falls back to `document.execCommand('copy')` via a hidden textarea, enabling copy in cross-origin iframes — closes #43
- Share URL now wraps on long URLs (replaced `white-space:nowrap`/`overflow:hidden` with `word-break:break-all`/`overflow-wrap:anywhere`) — closes #44
- `postMessage` height reporter now scopes `targetOrigin` to `document.referrer`'s origin instead of `'*'` — closes #53
- `$split_max_subnets` is now clamped to 1–256 after config load, preventing misconfiguration — closes #55
- GET/POST calculation logic deduplicated into `resolve_ipv4_input()` and `resolve_ipv6_input()` helper functions — closes #54
- Footer GitHub link updated to include `rel="noreferrer"` — closes #56

### Fixed
- `$fixed_bg_color` hex validation regex now accepts only valid CSS hex lengths (3, 4, 6, or 8 hex digits); previously accepted 3–8 which allowed invalid values — closes #49
- IPv6 exception messages no longer expose internal PHP error text; errors are logged via `error_log()` and a safe generic message is shown — closes #51

## [0.6] - 2026-04-03

### Added
- Subnet splitter in IPv4 panel — enter a larger prefix to split the current subnet into equal subnets (up to 16 shown, each copyable)
- Fixed background colour override: set `$fixed_bg_color` at the top of `index.php` to pin the page background regardless of light/dark mode
- Share bar now displays the full absolute URL (scheme + host + path + query string)

### Changed
- Light mode page background (`--color-bg`) changed from `#f1f5f9` to `#ffffff`

### Fixed
- Entering CIDR notation (e.g. `192.168.1.0/24`) in the IP field and pressing Enter now works correctly — the split is handled server-side in PHP before validation, so it no longer depends on the JS `blur` event

## [0.5] - 2026-04-03

### Added
- Light/dark mode toggle button in the title row with `localStorage` persistence; dark mode is the default
- Address type badge in IPv4 results: Private (RFC 1918), Loopback, Link-local, Multicast, Documentation, Public, and more
- Address type badge in IPv6 results: Global Unicast, Link-local, Unique Local (ULA), Multicast, Loopback, Documentation, Teredo, 6to4
- `html[data-theme="light"]` CSS overrides for all custom properties — full light mode palette

## [0.4] - 2026-04-03

### Added
- `--color-input-bg` CSS variable — input backgrounds are now separately themeable from the page background (`--color-bg`)
- Wildcard mask output row in IPv4 results (e.g. `0.0.0.255` for `/24`)
- Click-to-copy on all result rows with toast notification feedback
- Input auto-detection: pasting a full CIDR string (e.g. `192.168.1.0/24`) into the IP field auto-splits it into IP and mask fields on blur
- Auto-focus on the first empty input of the active panel on page load

### Fixed
- POST array injection no longer causes `TypeError` on PHP 8 (all `$_POST` values cast to `string` before `trim()`) — closes #12
- `ipv6_to_gmp()` now throws `InvalidArgumentException` if `inet_pton()` returns `false`, preventing silent wrong-subnet calculation — closes #13
- `gmp_to_ipv6()` now throws on 128-bit overflow and explicitly checks `inet_ntop()` return value — closes #14

## [0.3] - 2026-04-03

### Added
- IPv6 subnet calculation using PHP GMP extension for 128-bit arithmetic
- IPv4 / IPv6 tab switcher UI
- IPv6 outputs: Network CIDR, Prefix Length, First IP, Last IP, Total Addresses (as 2^n)
- Session-start hook now installs `php-gmp` if not present

## [0.2] - 2026-04-02

### Added
- Reset button to clear inputs and results

### Removed
- Total Hosts output field (superseded by Usable IPs)

## [0.1] - 2026-04-02

### Added
- IPv4 subnet calculator (`index.php`) — single file, zero dependencies
- Accepts netmask in CIDR (`/24`, `24`) or dotted-decimal (`255.255.255.0`) notation
- Outputs: Subnet CIDR, Netmask (CIDR & Octet), First Usable IP, Last Usable IP, Broadcast IP, Total Hosts, Usable IPs
- Handles edge cases: `/0` (default route), `/31` (point-to-point links), `/32` (host routes)
- Dark-themed responsive UI using plain HTML/CSS
- Claude Code session-start hook for remote sessions
- Claude Review GitHub Actions workflow
