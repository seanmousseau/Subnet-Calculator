# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development

```bash
# Run the app locally (serve the Subnet-Calculator/ subfolder)
php -S localhost:8080 -t Subnet-Calculator/

# Syntax check all PHP files
php -l Subnet-Calculator/index.php
for f in Subnet-Calculator/includes/*.php Subnet-Calculator/templates/layout.php \
         Subnet-Calculator/api/v1/*.php Subnet-Calculator/api/v1/handlers/*.php; do php -l "$f"; done

# Static analysis (PHPStan level 9, configured in phpstan.neon)
phpstan analyse --no-progress

# Unit tests (PHPUnit 11, requires: composer install)
composer install --no-interaction --prefer-dist
vendor/bin/phpunit

# PHPCS (PSR-12) — use .phpcs.xml for config
vendor/bin/phpcs --standard=PSR12 Subnet-Calculator/includes/ Subnet-Calculator/api/

# JS/CSS linting (requires: npm install)
npm run lint:js   # ESLint on app.js
npm run lint:css  # Stylelint on app.css
npm run lint      # both

# Deploy to dev server, then run the end-to-end browser test suite (~517 assertions)
# Requires: dev server running at root@192.168.80.15
rsync -a --delete Subnet-Calculator/ root@192.168.80.15:/opt/container_data/dev.seanmousseau.com/html/claude/subnet-calculator/
scp testing/fixtures/iframe-test.html root@192.168.80.15:/opt/container_data/dev.seanmousseau.com/html/claude/subnet-calculator/
bash -c 'set -a; source ~/.claude/dev-secrets.env; set +a; python3 testing/scripts/playwright_test.py'

# Build a release tarball (files at root level — untar directly in webroot to install/upgrade)
# Also bump $app_version in Subnet-Calculator/includes/config.php before building
tar -czf releases/subnet-calculator-X.Y.Z.tar.gz -C Subnet-Calculator .
```

**Release checklist:**
1. Bump `$app_version` in `Subnet-Calculator/includes/config.php`
2. Update `CHANGELOG.md` with new release section
3. Add row to `README.md` downloads table
4. Build tarball (see Development block above)
5. Commit, push, verify on GitHub
6. Create PR `dev → main`

(Or run `/release` to automate steps 1–7.)

PHP unit tests: `testing/unit/` (158 tests, 243 assertions; 14 skipped on platforms without GMP). Playwright browser tests: `testing/scripts/playwright_test.py` (85 test groups, 517 assertions) covers page load, security headers, Permissions-Policy, CSP nonce integrity, IPv4/IPv6 calculation, reverse DNS zones, edge cases, address type badges, subnet splitters, copy buttons, splitter shareable URLs, binary representation, VLSM planner, overlap checker, shareable GET URLs, iframe integration, UI interactions, VLSM shareable URL, VLSM CSV/JSON/XLSX export, VLSM reset/validation, Copy All buttons, VLSM utilisation summary, IPv6 overlap, multi-CIDR overlap, IPv6 binary/hex, v1.3.0 regression tests, supernet/summarise UI, ULA generator UI, VLSM session TTL notice, REST API endpoints (meta, IPv4, IPv6, VLSM, overlap, split, supernet, ULA, rdns, bulk, OpenAPI spec, range/ipv4, tree), IPv4 binary hex/decimal rows, IPv6 address expanded/compressed forms, API v2.2.0 new fields, ASCII export, tooltips/help bubbles, visual regression, docs footer link, IP range→CIDR UI, tree view UI, API v2.3.0 endpoints, tooltip visual polish (#205), tooltip accessibility, CSP inline-style violations (#206), print stylesheet (#193), locale number format (#191), ESLint/Stylelint clean, full visual inspection, all-tooltips direction, console error monitoring, light/dark theme testing.

## Repository layout

```
Subnet-Calculator/      ← docroot (serve this directory)
  index.php             ← entry point (bootstrap only)
  includes/             ← PHP includes (blocked from direct web access)
    config.php          ← config defaults + optional config.php override
    functions-ipv4.php  ← IPv4 utility functions
    functions-ipv6.php  ← IPv6 utility functions
    functions-ipv6.php  ← IPv6 utility functions
    functions-split.php ← subnet splitter functions
    functions-util.php  ← address type detection + badge helpers + help_bubble()
    functions-vlsm.php  ← VLSM planner function
    functions-supernet.php ← supernet_find(), summarise_cidrs()
    functions-ula.php   ← generate_ula_prefix() (RFC 4193)
    functions-session.php ← SQLite session CRUD
    functions-resolve.php ← resolve_ipv4_input(), resolve_ipv6_input() (shared by web + API)
    functions-range.php ← range_to_cidrs() — IP range → minimal CIDR list
    functions-tree.php  ← build_subnet_tree() — allocation tree with gap detection
    request.php         ← Turnstile verify, GET/POST handling; requires functions-resolve.php
  templates/            ← HTML template (blocked from direct web access)
    layout.php
  assets/               ← CSS, JS, and image assets (publicly served, long-cached)
    app.css
    app.js
    logo.webp
    logo.png            ← PNG fallback for Safari <14
    favicon-32.webp
    favicon-32.png      ← PNG fallback for browsers without WebP favicon support
    vendor/
      xlsx/
        xlsx.full.min.js ← SheetJS 0.20.3, SRI-verified, defer-loaded
  api/
    openapi.yaml        ← OpenAPI 3.1 specification for all REST endpoints
    v1/
      index.php         ← API router + bootstrap
      helpers.php       ← json_ok/json_err, auth, rate limit, CORS (blocked from web)
      .htaccess         ← front-controller rewrite; blocks helpers.php
      handlers/         ← one file per endpoint (blocked from direct web access)
        ipv4.php, ipv6.php, vlsm.php, overlap.php, split.php,
        supernet.php, ula.php, sessions.php, range.php, tree.php
  data/                 ← SQLite DB location (blocked from web; git-ignored)
  .htaccess             ← blocks config files, tarballs, subdirs; cache headers
  robots.txt
  config.php.example    ← copy to config.php to override defaults
  config.php            ← local overrides (git-ignored)
docs/                   ← MkDocs Material source (deployed to GitHub Pages)
  mkdocs.yml
  requirements.txt
  index.md, ipv4.md, ipv6.md, vlsm.md, splitter.md, overlap.md,
  supernet.md, ula.md, binary.md, rdns.md, sessions.md, sharing.md,
  tree.md, api.md, config.md
releases/               ← versioned release tarballs
testing/
  snapshots/            ← Pillow visual regression baselines (committed PNGs)
  scripts/
    playwright_test.py  ← 85 test groups, 517 assertions
    snapshot_utils.py   ← capture_snapshot / compare_snapshot helpers
README.md
CHANGELOG.md
CONTRIBUTING.md
SECURITY.md
LICENSE
.phpcs.xml             ← PHPCS PSR-12 config (excludes vendor/; test-file naming)
.github/
  workflows/
    docs.yml            ← MkDocs GitHub Pages deploy on push to main
.claude/
```

## Architecture

PHP application with a slim entry point (`index.php`) that bootstraps includes and renders a template. Execution order:

1. **`index.php`** — requires all includes in order, sends security headers (CSP nonce, `X-Content-Type-Options`, etc.), then requires the template.
2. **`includes/config.php`** — all operator-tunable `$variables` (`$fixed_bg_color`, `$default_tab`, `$split_max_subnets`, `$form_protection`, `$turnstile_site_key`, `$turnstile_secret_key`, `$canonical_url`, etc.). If `config.php` exists in the docroot it is `require`d here to allow overrides without touching source files.
3. **`includes/functions-*.php`** — pure utility functions, all typed with `declare(strict_types=1)`: IPv4 (`cidr_to_mask`, `calculate_subnet`, etc.), IPv6 (`ipv6_to_gmp`, `calculate_subnet6`, etc.), splitters (`split_subnet`, `split_subnet6`), type detection (`get_ipv4_type`, `get_ipv6_type`), supernet (`supernet_find`, `summarise_cidrs`), ULA (`generate_ula_prefix`), session (`session_db_open`, `session_create`, `session_load`, `session_purge`), input resolvers (`resolve_ipv4_input`, `resolve_ipv6_input` — in `functions-resolve.php`, shared by web and API).
4. **`includes/request.php`** — reads `$_GET`/`$_POST`, populates `$result`/`$result6`/`$error`/`$split_result`/`$split_result6`; GET triggers auto-calculation for shareable URLs. Form protection (honeypot / Turnstile) is checked before calculation. Also computes `$bg_override_style`, `$share_url`, `$canonical_url`.
5. **`templates/layout.php`** — full HTML output; references `assets/app.css` (external stylesheet) and `assets/app.js` (external script); theme-init `<script>` stays inline (prevents FOUC); conditional `bg_override_style` `<style>` stays inline with nonce.

### Key implementation details

- **IPv4 arithmetic**: always `& 0xFFFFFFFF` after bitwise ops — PHP's `ip2long()` returns a signed integer on 64-bit systems
- **IPv6 arithmetic**: uses PHP GMP extension (`gmp_init`, `gmp_and`, etc.) with `inet_pton()`/`inet_ntop()` and a hex string intermediary
- **IPv6 splitter large counts**: when the prefix difference is ≥ 63, `1 << diff` overflows; total is represented as the string `'2^N'` consistent with `calculate_subnet6()`
- **CIDR paste auto-detection**: handled both server-side (in all GET/POST handlers via `strpos($input, '/')`) and client-side (JS `blur` event) to cover the case where a user types CIDR notation and presses Enter without blurring
- **Shareable URLs**: GET parameters auto-trigger calculation; JS prepends `window.location.origin + pathname` to the relative query string for display/copy
- **iframe auto-sizing**: `window.self !== window.top` detection adds `in-iframe` to `<html>`, activating CSS overrides (`min-height:0`, `align-items:flex-start`) and a `postMessage` height reporter via `ResizeObserver`; target origin uses `window.location.ancestorOrigins[0]` (Chrome/Edge) with a `sessionStorage` fallback (Firefox) — **not** `document.referrer`, which breaks after same-origin form-submit navigations inside the iframe
- **Clipboard**: `navigator.clipboard.writeText()` with `document.execCommand('copy')` fallback via hidden textarea for cross-origin iframes
- **Theme**: `html[data-theme="light"]` CSS overrides; dark is default; `localStorage` persistence via inline `<script>` in `<head>` (runs before render to avoid flash)

## Branching

- Active development branch: `dev`
- All PRs target `main`
- Issues are labelled by release milestone (`v0.4`, `v0.5`, etc.)

## Git and GitHub

The local git remote (`origin`) points to a session-scoped proxy at `127.0.0.1`. **`git push` output is not reliable confirmation that commits reached GitHub** — the proxy accepts the push locally but may not forward it depending on the session. Always verify that commits actually landed on GitHub using the MCP GitHub tools (e.g. `mcp__github__list_commits`) before declaring a push successful.

To write files to GitHub from a Claude Code session, prefer `mcp__github__push_files` for small files (works up to ~20 KB per file). For larger files (e.g. `index.php` at ~64 KB, release tarballs at ~600 KB), instruct the user to push from their own machine.

## Claude automations

**Skills** (invoke with `/skill-name`):
- `/deploy-test` — rsync to dev server + run CDP browser suite
- `/release` — full release checklist (bump version, changelog, tarball, PR)

**Hooks** (PostToolUse, auto-run on every PHP edit):
- `php -l` — syntax check (~50ms)
- `phpstan analyse` — level 9 static analysis (~2s)

**Session start hook**: `.claude/hooks/session-start.sh` — installs `php-gmp` in remote sessions if missing.
