# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
