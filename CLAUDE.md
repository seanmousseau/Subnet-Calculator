# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development

```bash
# Run the app locally (serve the Subnet-Calculator/ subfolder)
php -S localhost:8080 -t Subnet-Calculator/

# Syntax check all PHP files
php -l Subnet-Calculator/index.php
for f in Subnet-Calculator/includes/*.php Subnet-Calculator/templates/layout.php; do php -l "$f"; done
```

There are no build steps, test suites, or package managers.

## Repository layout

```
Subnet-Calculator/      ← docroot (serve this directory)
  index.php             ← entry point (bootstrap only)
  includes/             ← PHP includes (blocked from direct web access)
    config.php          ← config defaults + optional config.php override
    functions-ipv4.php  ← IPv4 utility functions
    functions-ipv6.php  ← IPv6 utility functions
    functions-split.php ← subnet splitter functions
    functions-util.php  ← address type detection + badge helpers
    request.php         ← input resolvers, Turnstile verify, GET/POST handling
  templates/            ← HTML template (blocked from direct web access)
    layout.php
  assets/               ← compiled CSS + JS (publicly served, long-cached)
    app.css
    app.js
  logo/                 ← optimized logo assets
    logo.webp
    logo.png
    favicon-32.webp
    favicon-32.png
  .htaccess             ← blocks config files, tarballs, subdirs; cache headers
  robots.txt
  config.php.example    ← copy to config.php to override defaults
  config.php            ← local overrides (git-ignored)
releases/               ← versioned release tarballs
README.md
CHANGELOG.md
CONTRIBUTING.md
SECURITY.md
LICENSE
.github/
.claude/
```

## Architecture

PHP application with a slim entry point (`index.php`) that bootstraps includes and renders a template. Execution order:

1. **`index.php`** — requires all includes in order, sends security headers (CSP nonce, `X-Content-Type-Options`, etc.), then requires the template.
2. **`includes/config.php`** — all operator-tunable `$variables` (`$fixed_bg_color`, `$default_tab`, `$split_max_subnets`, `$form_protection`, `$turnstile_site_key`, `$turnstile_secret_key`, `$canonical_url`, etc.). If `config.php` exists in the docroot it is `require`d here to allow overrides without touching source files.
3. **`includes/functions-*.php`** — pure utility functions, all typed with `declare(strict_types=1)`: IPv4 (`cidr_to_mask`, `calculate_subnet`, etc.), IPv6 (`ipv6_to_gmp`, `calculate_subnet6`, etc.), splitters (`split_subnet`, `split_subnet6`), type detection (`get_ipv4_type`, `get_ipv6_type`), input resolvers (`resolve_ipv4_input`, `resolve_ipv6_input`).
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

## Session start hook

`.claude/hooks/session-start.sh` runs automatically in remote Claude Code sessions. It installs `php-gmp` if the GMP extension is not loaded (required for IPv6 calculation).
