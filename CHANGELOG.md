# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.9.1] - 2026-04-26

### Fixed
- **VLSM dark mode inputs** — `input[type="number"]` (VLSM host fields) now inherit the same dark-mode background, border, and text colour tokens as `input[type="text"]` — closes #276
- **Mobile theme toggle clipped** — theme toggle button is no longer cropped on 375 px viewports; `flex-shrink: 0` prevents it from being squeezed by the h1 — closes #277
- **Long IPv6 overflow** — expanded IPv6 addresses now wrap at character boundaries (`overflow-wrap: anywhere`) instead of overflowing the result card — closes #278
- **Logo transparency** — SC monogram `logo.png` / `logo.webp` had premultiplied-alpha white fringe at rounded corners; rebuilt with clean transparency
- **Favicon/asset cache-busting** — `?v=` query params added to favicon and apple-touch-icon `<link>` tags so browsers fetch updated assets after upgrade

## [2.9.0] - 2026-04-25

### Changed
- **Typography** — app now uses Space Grotesk (headings, labels, section headers), Plus Jakarta Sans (body copy), and Fira Code (all monospace: inputs, result values, share bar, binary, VLSM). JetBrains Mono removed.
- **Colour system** — accent changed from blue (`#3b82f6`) to teal (`#06d6a0`) across all interactive states (Calculate button, active tab, focus rings, tool triggers, result values). Dark mode surfaces aligned to GitHub-dark palette (`bg: #0d1117`, `surface: #161b22`, `border: #21262d`).
- **Calculate button** — teal background, dark text `#0a1a12`, border-radius `8px`, hover lift with teal shadow
- **Reset button** — ghost style (transparent bg, border), teal hover (border + text), lift on hover
- **Version badge** — teal Fira Code pill (border-radius `999px`, teal-dim background)
- **Tool trigger buttons** — Fira Code font, `border-radius: 8px`, teal active state with dark text
- **Light mode** — teal accent darkened to `#0a7a5c` for WCAG AA contrast compliance on white (5.2:1)
- **Body background** — subtle static teal grid (3% dark mode, 1.5% light mode) adds depth
- **Result row hover** — teal-tinted background `rgb(6 214 160 / 4%)` replaces grey

### Fixed
- **Mobile: header title wrapping** — "Subnet Calculator" no longer wraps to two lines on 375px viewports; h1 font-size clamped and logo shrunk at `≤480px`
- **Mobile: tool drawer ghost state** — drawer chrome no longer visible below card when drawer is closed; `overflow: clip` on `.card` contains the absolute-positioned bottom sheet
- **Mobile: share URL overflow** — share bar URL now truncates with ellipsis instead of wrapping mid-URL
- **Mobile: Reverse DNS Zone wrapping** — long reverse DNS values use `overflow-wrap: anywhere` at narrow viewports

### Tests
- New `test_v290_typography` Playwright test group verifies correct font families and teal color on key elements
- All visual regression baselines regenerated for the new teal color scheme

## [2.8.1] - 2026-04-23

### Fixed
- **Tooltip regression** — help-bubble tooltips no longer expand the moment a tool drawer opens; the initial focus on drawer open now skips help-bubble icons (`aria-label="Help"`), which remain reachable via Tab — closes #241

## [2.8.0] - 2026-04-23

### Added
- **Tool drawer** — IPv4, IPv6, and VLSM sub-tools (Split, Supernet, Range, Tree; Split6, ULA; Session, Overlap Checker, Multi-CIDR) are now tucked into a compact slide-in drawer accessed via a toolbar strip at the bottom of each tab panel. The main result area is always visible; sub-tools open on demand — closes #232 #233 #234 #235 #236
- **Drawer focus trap** — Tab / Shift-Tab cycles within the open drawer; Escape closes it and returns focus to the trigger button
- **Auto-reopen on form submit** — after submitting a sub-tool form (split, supernet, ULA, etc.) the page reloads and the drawer automatically reopens to the same tool with results visible; implemented via a PHP `data-open-tool` attribute on the toolbar wrapper
- **Bottom-sheet on narrow viewports** — at `≤ 480 px` the drawer slides up from the bottom as a sheet (60 vh, `max-height: 100%`) instead of from the right

### Changed
- **Reduced visual clutter** — all sub-tool panels are hidden by default and revealed only when selected; no-JS users see them stacked as before

### Tests
- 7 new Playwright test groups covering drawer mechanics: toolbar render, click-to-open, auto-reopen after submit, Escape close, × close, toggle, and tool switching (561 assertions total)

## [2.7.0] - 2026-04-21

### Added
- **Service Worker** — `sw.js` added at the docroot; caches the app shell (`/`) at install time and applies cache-first for static assets (`/assets/`) and network-first for HTML navigations. Only registered in non-iframe mode. Cache name `sc-v2.7.0` ensures old caches are evicted on upgrade — closes #192
- **PHP client library** — `clients/php/SubnetCalculatorClient.php`: zero-dependency, single-file PHP 7.4+ client with typed methods for all 16 API endpoints (`calcIpv4`, `calcIpv6`, `calcVlsm`, `checkOverlap`, `splitIpv4`, `splitIpv6`, `supernet`, `generateUla`, `createSession`, `loadSession`, `generateRdns`, `bulkCalculate`, `rangeToIPv4CIDRs`, `buildSubnetTree`, `getChangelog`, `meta`); falls back from cURL to `file_get_contents` stream transport; PHP 8.5 `http_get_last_response_headers()` compatibility — closes #189
- **Open Graph + Twitter card meta tags** — `og:image:width` (520), `og:image:height` (600), `og:image:type`, `og:site_name`, `twitter:title`, `twitter:description`, `twitter:image`, `twitter:image:alt`, `theme-color` (#0F172A), and `color-scheme` added to `templates/layout.php`; image dimensions now match the actual `logo.webp` asset — closes #222
- **`sw.js` cache headers** — `.htaccess` adds `Cache-Control: no-cache, no-store, must-revalidate` and `Service-Worker-Allowed: /` for `sw.js` so browsers always check for SW updates without the long-cache policy applied to other JS assets

### Tests
- 17 new PHPUnit unit tests in `testing/unit/ClientTest.php` covering `SubnetCalculatorClient` request routing, body construction, and `decode()` error handling (175 total; 271 assertions)
- `phpstan.neon` extended to include `clients/php/SubnetCalculatorClient.php`

## [2.6.0] - 2026-04-21

### Added
- **Docker + Playwright test environment** — `Dockerfile` and `docker-compose.yml` containerise the PHP app and Playwright test runner; `make test-docker` runs the full E2E suite without requiring the remote dev server — closes #226
- **GitHub CI Playwright workflow** — `.github/workflows/playwright.yml` runs the Playwright suite on every PR via Docker — closes #228
- **`GET /api/v1/changelog`** endpoint — returns `CHANGELOG.md` as a JSON string; registered in the meta endpoint list and documented in `api/openapi.yaml` — closes #186
- **`api_deprecation_headers()`** utility function in `api/v1/helpers.php` — sends `Sunset`, `Deprecation`, and `Link` headers for future endpoint deprecation workflows
- **`$api_request_log` config option** — when enabled, each API request is logged (endpoint, method, client IP, timestamp) to `data/api_requests.sqlite`; fails open on any DB error — closes #187
- **JetBrains Mono** — self-hosted WOFF2 (`assets/fonts/JetBrainsMono-Regular.woff2`, OFL 1.1) replaces `'Courier New'` in all monospace contexts (result values, binary rows, ULA codes, VLSM table cells) — closes #218
- **Makefile** — `make test-docker` target added as the canonical pre-PR local test gate — closes #227

### Changed
- **Wider card on large viewports** — card `max-width` increases to `800px` at `width >= 900px`, giving the VLSM table and other dense panels more breathing room — closes #219
- **Tablet responsive breakpoint** — new `@media (481px <= width <= 767px)` rule adjusts card padding for mid-range viewports — closes #220
- **Scroll affordance on narrow viewports** — `.vlsm-results` and `.split-list-scroll` show a right-edge gradient fade (using `--color-surface`) at `width <= 767px` to indicate horizontal scrollability — closes #221
- **CI cleanup** — removed `claude-code-review.yml` GitHub Actions workflow — closes #225

### Performance
- **Early TTFB flush** — `ob_flush(); flush()` call after `</head>` in `layout.php` allows the browser to start fetching `app.css` while PHP evaluates the page body — closes #194

### Tests
- `APP_URL` and `_APP_BASE` in `playwright_test.py` now read from the `APP_URL` environment variable (fallback to dev server URL)
- `SKIP_SNAPSHOTS=1` environment variable skips pixel-level snapshot comparisons in Docker CI
- Playwright test group and assertion counts updated (snapshot baselines regenerated for font and layout changes)

## [2.5.0] - 2026-04-20

### Added
- **Skip-to-content link** — visually hidden `<a href="#main-content">` becomes visible on keyboard focus; resolves WCAG 2.4.1 (Bypass Blocks) — closes #217
- **`<main>` landmark** — outermost card element promoted from `<div>` to `<main id="main-content">` so screen readers and the skip link have a named landmark — closes #216
- **Help bubble keyboard access** — `.help-bubble-icon` gains `role="button"` and `aria-label="Help"`; existing `:focus-within` CSS already reveals the tooltip on keyboard focus — closes #215

### Fixed
- **Input focus ring** — removed `outline: none` on text/number inputs; replaced with `outline: 2px solid var(--color-accent)` via `:focus-visible` so keyboard users see a visible ring while mouse clicks are unaffected — closes #211
- **Button focus-visible** — all `button` and `a.btn` elements now show a 2 px accent outline on keyboard focus — closes #223
- **Light mode color contrast** — `--color-text-faint` raised from `#94a3b8` (~2.9:1) to `#6b7280` (~4.9:1); `--color-text-muted` raised from `#64748b` to `#4b5563` (~5.9:1); both now exceed WCAG AA 4.5:1 — closes #214
- **`prefers-reduced-motion`** — global media query disables all CSS transitions and animations when the OS reduced-motion preference is set — closes #212
- **Help bubble touch target** — `.help-bubble-icon` enlarged from 14×14 px to 18×18 px with 3 px padding (24×24 px tap area), meeting WCAG 2.2 SC 2.5.8 — closes #215

### a11y
- **VLSM row keyboard Delete** — pressing `Delete` or `Backspace` on a focused remove button deletes the row and moves focus to the nearest remaining name input — closes #190

### Tests
- 6 new Playwright a11y test groups: landmarks, input focus ring, toast ARIA, help bubble keyboard, prefers-reduced-motion CSS, VLSM keyboard Delete (91 groups, 529 assertions)
- Visual regression baselines regenerated after CSS token changes

## [2.4.1] - 2026-04-13

### Added
- **`$locale` config variable** — locale-aware thousands separators in all displayed counts using PHP's `intl` extension (`NumberFormatter`); falls back to `number_format()` comma separators when `intl` is absent or locale is `'en'`; configurable via `$locale` in `config.php` — closes #191
- **ESLint and Stylelint** — `eslint.config.js` and `.stylelintrc.json` added; `npm run lint:js` / `npm run lint:css` / `npm run lint` available; three intentional empty catch blocks in `app.js` converted to optional catch binding syntax
- **OpenAPI example responses** — every `200` response in `api/openapi.yaml` now includes a concrete `example:` block with realistic test data; documentation-only change with no runtime impact — closes #188

### Fixed
- **CSP inline style violations** — 16 `style="..."` attributes across tree/range/ULA panels replaced with named CSS classes (`.tree-node`, `.tree-gap`, `.tree-free-label`, etc.) so the strict `style-src 'self' 'nonce-...'` CSP passes with zero browser violations — closes #206
- **Tooltip visual polish** — `text-transform: none` on `.help-bubble-text` prevents uppercase inheritance from label context; `max-width: min(260px, calc(100vw - 2rem))` prevents overflow; JS right-edge detection adds `.bubble-right-edge` modifier on resize/tab-switch so tooltips near the viewport edge flip left-aligned — closes #205
- **Print stylesheet** — VLSM results table and utilisation summary are correctly visible in `@media print`; export buttons, copy-all buttons, and ASCII export hidden; table headers repeat across page breaks — closes #193
- **Mobile horizontal overflow** — `.splitter-row` gains `flex-wrap: wrap` so the supernet action buttons stack correctly on 375 px viewports

### Tooling / CI
- PHPStan level 9 expanded to all `includes/functions-*.php` and all `api/v1/handlers/*.php`; real type errors corrected:
  - `functions-ipv4.php`: `ip2long()` and `long2ip()` false-return guarded in `cidr_to_mask()`, `mask_to_cidr()`, `is_valid_mask_octet()`, `cidr_to_wildcard()`
  - `functions-ipv6.php`: `inet_pton()` and `hex2bin()` false-return guarded in `gmp_to_ipv6()`; `ipv6_ptr_zone()` now throws `\InvalidArgumentException` on invalid input instead of silently returning a bad zone
  - `functions-util.php`: `unpack()` false/short-result path in `get_ipv6_type()` now returns `'Unknown'` immediately
  - `functions-ipv4.php`, `functions-ipv6.php`, `functions-split.php`: `@return array<string, mixed>` PHPDoc added to `calculate_subnet()`, `calculate_subnet6()`, `split_subnet()`, `split_subnet6()`
- `.coderabbit.yaml` review instructions added for `package.json`, `eslint.config.js`, `.stylelintrc.json`, `.semgrep.yml`
- `.semgrep.yml` added: PHP XSS (unsanitised `$_GET`/`$_POST` echo), PHP SQL injection (string-concatenated queries), JS unsafe DOM content rules

### Tests / CI
- PHPStan level 9: 0 errors (expanded path set)
- PHPCS PSR-12: 0 errors
- PHPUnit: 158 tests, 243 assertions (14 skipped on platforms without GMP)
- Playwright browser suite: 517/517 passed (85 test groups — added full visual inspection, all-tooltips direction, console error monitoring, light/dark theme testing)

## [2.3.0] - 2026-04-12

### Added
- **IP range → CIDR conversion** — new IPv4 panel accepts a start IP and end IP and returns the minimal set of covering CIDRs (greedy largest-aligned block algorithm); results are copyable per-row and via Copy All; shareable via the share bar; also available as `POST /api/v1/range/ipv4` REST endpoint — closes #182
- **Subnet allocation tree view** — new IPv4 panel accepts a parent CIDR and a list of allocated child CIDRs and renders them as a collapsible containment tree with gap detection (unallocated space shown as muted CIDR nodes); also available as `POST /api/v1/tree` REST endpoint — closes #183
- **VLSM planner: JSON and XLSX export** — the VLSM results toolbar now has CSV | JSON | XLSX export buttons; JSON export uses a `Blob` URL download; XLSX export uses SheetJS (vendored at `assets/vendor/xlsx/xlsx.full.min.js`, SRI-verified, `defer`-loaded) — closes #184
- **ASCII network diagram export** — "Export ASCII" button on IPv4/IPv6 splitter results and the VLSM results toolbar copies a Unicode box-drawing tree (`├─`, `└─`) of the parent CIDR and its allocated subnets to the clipboard — closes #185
- **Tooltips and help bubbles** — 20 `?` help-bubble icons added across the IPv4, IPv6, and VLSM panels (IP address input, subnet mask, wildcard mask, address type, binary panel, splitter prefix, supernet, summarise, multi-CIDR overlap, IPv6 expanded/compressed, ULA global ID, VLSM hosts/waste/utilisation headers, VLSM session panel, two-CIDR overlap, and range-to-CIDR start IP); pure CSS tooltip, keyboard-accessible (`tabindex="0"`, `role="tooltip"`, `aria-describedby`) — closes #198
- **Visual regression tests** — Pillow-based pixel-comparison snapshots for desktop (1280 px), tablet (768 px), and mobile (375 px) viewports; `UPDATE_SNAPSHOTS=1` env var writes new baselines; baselines committed to `testing/snapshots/` — closes #196
- **User documentation site** — MkDocs Material site covering all calculator features, the REST API, and operator config; deployed to GitHub Pages via `.github/workflows/docs.yml` on push to `main`; Docs link added to the app footer — closes #197

### Fixed
- **Save Session button spacing** — the Save and Restore session forms in the VLSM tab were only 8 px apart; wrapped in a `<div class="session-forms">` with `gap: 1rem` so they breathe correctly at all viewport widths — closes #199

### Tests / CI
- PHPStan level 9: 0 errors
- PHPCS PSR-12: 0 errors
- PHPUnit: 131 tests, 195 assertions (14 skipped on platforms without GMP)
- Playwright browser suite: 299/299 passed (74 test groups)

## [2.2.0] - 2026-04-12

### Added
- **Form protection: hCaptcha** — `$form_protection = 'hcaptcha'` adds hCaptcha as a third CAPTCHA option alongside honeypot and Turnstile; requires `$hcaptcha_site_key` and `$hcaptcha_secret_key`; server-side verify via `https://api.hcaptcha.com/siteverify`; CSP extended with hCaptcha domains — closes #177
- **Form protection: Google reCAPTCHA Enterprise** — `$form_protection = 'recaptcha_enterprise'` adds score-based bot protection; requires `$recaptcha_enterprise_site_key`, `$recaptcha_enterprise_api_key`, `$recaptcha_enterprise_project_id`; configurable score threshold via `$recaptcha_score_threshold` (default 0.5); server-side verify via reCAPTCHA Enterprise Assessments API — closes #175
- **API: per-token rate limit overrides** — `$api_rate_limit_tokens = ['token' => rpm]` allows setting a different RPM for individual Bearer tokens; rate-limit table is keyed by `tok:<sha256(token)>` instead of IP when a token-specific entry is present; `0` RPM = unlimited for that token — closes #178
- **API: `$api_allowed_endpoints` endpoint allowlist** — non-empty array restricts the API to listed endpoint names only; requests to unlisted endpoints return 404; the meta endpoint (`GET /`) is always available; useful for operators who want UI-only deployments — closes #179
- **IPv4 results: hex and decimal network address** — `calculate_subnet()` now returns `network_hex` (dotted-hex, e.g. `C0.A8.01.00`) and `network_decimal` (unsigned 32-bit integer) for the network address; both are displayed in the Binary Representation panel and returned by `POST /api/v1/ipv4` — closes #180
- **IPv6 results: expanded and compressed address forms** — `calculate_subnet6()` now returns `address_expanded` (8 colon-separated 4-hex groups, e.g. `2001:0db8:0000:…`) and `address_compressed` (RFC 5952 notation, e.g. `2001:db8::`) for the network address; both are displayed as copyable rows in the IPv6 results section and returned by `POST /api/v1/ipv6` — closes #181

### Tests / CI
- PHPStan level 9: 0 errors
- PHPCS PSR-12: 0 errors
- PHPUnit: 131 tests, 195 assertions (14 skipped on platforms without GMP)
- Playwright browser suite: 255/255 passed (60 test groups)

## [2.1.0] - 2026-04-11

### Added
- **API: reverse DNS zone file generator** — `POST /api/v1/rdns` accepts an IPv4 or IPv6 CIDR and returns a BIND-format PTR zone file; supports custom nameserver, hostmaster, TTL, SOA serial, and placeholder domain; uses existing `ipv4_ptr_zone()` / `ipv6_ptr_zone()` for zone name derivation (RFC 2317 for IPv4 /25–/31); IPv4 requires /16 or greater, IPv6 requires /112 or greater — closes #146
- **API: bulk CIDR calculation** — `POST /api/v1/bulk` accepts up to 50 CIDRs in a single request and returns a per-item result array; auto-detects IPv4/IPv6 per item; individual failures do not abort the request — closes #147

### Fixed
- **VLSM session save URL** — the "Copy" button on the session save confirmation was prepending `window.location.origin + pathname` to an already-absolute URL, producing a double-prefix (e.g. `https://…/app/https://…/app/?tab=vlsm&s=id`); fixed by storing only the relative query string in `data-copy`, consistent with all other share bars — closes #172
- **VLSM session save display** — session save block now carries the `share-bar` class so the JS display-rewrite (`_base + data-copy`) fires consistently behind a reverse proxy, keeping the displayed URL and copied URL in sync

### Changed
- **VLSM Save & Restore panel** — added a muted TTL notice ("Saved sessions expire after X days") below the panel title so users know the link lifetime upfront; value is read directly from `$session_ttl_days` and updates automatically with operator configuration — closes #173

## [2.0.1] - 2026-04-11

### Fixed
- **VLSM planner**: single-host requirements (`hosts_needed=1`) were allocated `/31` instead of `/32`; reordered `=== 1` check before `<= 2` so the dead branch is reached
- **API supernet handler**: all-whitespace CIDR entries produced an empty array after `array_filter` without error; added an explicit guard that returns HTTP 400
- **API router**: bare `GET /api/v1/sessions` (no ID) was routed to the sessions handler and returned 400 instead of the correct 404; removed the dead `case 'GET /sessions'` from the switch

## [2.0.0] - 2026-04-11

### Added
- **JSON REST API** (`/api/v1/`) — endpoints for IPv4, IPv6, VLSM, overlap, split, supernet, ULA, and session persistence; optional Bearer-token auth, SQLite-backed rate limiting (sliding window, configurable RPM), and CORS headers — closes #141
- **OpenAPI 3.1 specification** (`api/openapi.yaml`) — full schema definitions for all 11 API endpoints; CI step runs `spectral lint` on every push — closes #142
- **Supernet / route summarisation tool** — IPv4 "Find Supernet" finds the smallest enclosing prefix for a set of CIDRs; "Summarise Routes" removes contained prefixes and merges adjacent ones to a minimal covering set; UI panel in the IPv4 tab, plus API endpoint `POST /api/v1/supernet` — closes #144
- **IPv6 ULA prefix generator (RFC 4193)** — generates a random `/48` ULA prefix from a 40-bit global ID (random or operator-supplied); shows global ID, 5 example `/64` subnets, and total `/64` count; UI panel in the IPv6 tab, plus API endpoint `POST /api/v1/ula` — closes #145
- **Session persistence** (opt-in, off by default) — VLSM planner can save its state to SQLite and restore via a short 8-character ID in the URL; `POST /api/v1/sessions` creates a session, `GET /api/v1/sessions/{id}` retrieves it — closes #140
- **`includes/functions-resolve.php`** — `resolve_ipv4_input()` and `resolve_ipv6_input()` extracted from `request.php` into a standalone file shared by both the web stack and API handlers

### Changed
- **PHP 8.1 minimum** — dropped PHP 7.4 support; `composer.json` requires `>=8.1`; CI matrix updated; `config.php.example` notes PHP 8.1 minimum — closes #143
- **PHPStan** — `phpstan.neon` now sets `phpVersion: 80100`; new paths added (`functions-resolve.php`, `api/v1/helpers.php`)
- **PHPCS** — `.phpcs.xml` added; test files excluded from namespace/method-name PSR-12 rules; scope extended to cover `Subnet-Calculator/api/`

## [1.3.0] - 2026-04-10

### Added
- **VLSM shareable URL** — GET parameters (`vlsm_network`, `vlsm_cidr`, `vlsm_name[]`, `vlsm_hosts[]`) auto-populate and calculate the VLSM planner on page load; share bar appears below results — closes #138
- **VLSM CSV export** — Export CSV button downloads a CSV file of VLSM results (Name, Hosts Needed, Allocated Subnet, First Usable, Last Usable, Usable IPs, Waste) — closes #139
- **VLSM Reset button** — Reset link in the VLSM actions bar clears inputs and results — closes #153
- **IPv6 binary/hex representation** — collapsible section under IPv6 results showing 128-bit binary (network/host bit colour coding) and hex views at nibble boundary — closes #154
- **VLSM client-side validation + loading state** — inline error shown when hosts field is < 1; submit button disabled with "Calculating…" text during server round-trip — closes #155
- **Copy All buttons** — in IPv4/IPv6 subnet splitter lists and VLSM results table; copies all subnets as newline-separated text — closes #156
- **VLSM utilisation summary** — row below VLSM results showing Hosts Requested, Allocated addresses, Remaining, and Utilisation % — closes #157
- **VLSM sort order note** — small label above VLSM results indicating requirements were sorted largest-first — closes #158
- **IPv6 overlap checker** — the subnet overlap checker now accepts IPv6 CIDRs in addition to IPv4; mixed-family pairs return an error — closes #159
- **Multi-CIDR pairwise overlap** — new panel accepts up to 50 CIDRs (one per line) and reports all overlapping pairs — closes #160

### Fixed
- **Version badge hardcoded** — `<span class="version">` in `layout.php` now renders `$app_version` dynamically instead of a hardcoded `v1.2.0` — closes #149
- **Address Type row not copyable** — Address Type result rows now have `tabindex="0"`, `role="button"`, and `title="Click to copy"` to match other copyable rows — closes #150
- **`$default_tab` rejects `'vlsm'`** — `'vlsm'` is now a valid value for `$default_tab` in `config.php` — closes #151
- **iOS Safari zoom on input focus** — input `font-size` raised to `1rem` (≥ 16 px) to prevent iOS Safari from zooming in on focus — closes #152

### Changed
- Release checklist step 2 removed (version badge in `layout.php` is now driven by `$app_version` automatically)

## [1.2.0] - 2026-04-08

### Added
- **Reverse DNS zone** display for both IPv4 (RFC 2317 classless delegation for prefixes > /24) and IPv6 (nibble-based ip6.arpa) — closes #122
- **VLSM planner** — new third tab that allocates variable-length subnets from a parent network; sorts requirements largest-first, shows name, hosts needed, allocated subnet, usable, and waste — closes #128
- **Subnet overlap checker** — panel inside VLSM tab; compares two CIDRs and reports none / identical / a contains b / b contains a — closes #126
- **Binary representation** — collapsible `<details>` section under IPv4 results showing network/host bits with colour coding — closes #127
- **Per-row copy buttons** in subnet splitter results for both IPv4 and IPv6 — closes #124
- **Splitter URL sharing** — `split_prefix`/`split_prefix6` parameters included in shareable URLs and processed on GET, so split results auto-appear on page load — closes #135
- **`prefers-color-scheme`** — theme initialisation now falls back to the OS setting when no `localStorage` preference is stored (light preference activates light theme) — closes #130
- **ARIA live regions** — IPv4 and IPv6 result containers now have `aria-live="polite" aria-atomic="false"` for screen reader announcements — closes #134
- **Asset cache-busting** — `$app_version` variable added to `config.php`; CSS/JS links append `?v=1.2.0` — closes #133
- **CI GitHub Actions workflow** (`.github/workflows/php.yml`) — syntax check, PHPStan, and PHPUnit on push/PR — closes #131
- **PHPUnit test infrastructure** — `composer.json`, `phpunit.xml`, and 61 unit tests across `testing/unit/` (IPv4, IPv6, Split, Util, VLSM) — closes #132
- **Permissions-Policy header** — closes #136

### Changed
- IPv6 `calculate_subnet6()` now returns a numeric string for total addresses when `host_bits ≤ 20` (e.g. `/127` → `"2"`, `/108` → `"1,048,576"` after formatting); larger prefixes retain `2^N` notation — closes #123
- PHPStan analysis level raised from 5 to 9 — closes #125

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

## [0.7] - 2026-04-03

### Added
- iframe mode flag (`$iframe_mode`) with automatic `postMessage` height reporting via `ResizeObserver` — closes #37
- External config consolidation: moved `$default_tab` and `$split_max_subnets` into the configuration defaults section of `includes/config.php` so all operator-tunable values are in one place — closes #35

### Changed
- `$fixed_bg_color` default changed from `null` to the string `'null'` to prevent operator configuration errors like missing quotes around hex values; validation logic treats both `'null'` and empty string as no-op — closes #36

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
