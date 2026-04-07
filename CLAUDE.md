# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development

```bash
# Run the app locally (serve the Subnet-Calculator/ subfolder)
php -S localhost:8080 -t Subnet-Calculator/

# Syntax check
php -l Subnet-Calculator/index.php
```

The entire application is `Subnet-Calculator/index.php`.

## Repository layout

```
Subnet-Calculator/      ← docroot (serve this directory)
  index.php             ← single-file PHP app
  logo.svg
  .htaccess             ← blocks direct access to config.php
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

Single-file PHP application (`Subnet-Calculator/index.php`). The file is structured top-to-bottom in this order:

1. **Configuration defaults** — all operator-tunable `$variables` (`$fixed_bg_color`, `$default_tab`, `$split_max_subnets`, `$form_protection`, `$turnstile_site_key`, `$turnstile_secret_key`). If `config.php` exists in the same directory it is `require`d here to allow overrides without touching `index.php`.
2. **Security headers** — sent before any output; CSP is conditionally extended for Cloudflare Turnstile.
3. **PHP functions** — pure utility functions: IPv4 (`cidr_to_mask`, `calculate_subnet`, etc.), IPv6 (`ipv6_to_gmp`, `calculate_subnet6`, etc.), address type detection (`get_ipv4_type`, `get_ipv6_type`), subnet splitters (`split_subnet`, `split_subnet6`), input resolvers (`resolve_ipv4_input`, `resolve_ipv6_input`).
4. **Request handling** — reads `$_GET`/`$_POST`, populates `$result`/`$result6`/`$error`/`$split_result`/`$split_result6`; GET triggers auto-calculation for shareable URLs. Form protection (honeypot / Turnstile) is checked before calculation.
5. **Pre-HTML computed values** — `$bg_override_style`, `$share_url`.
6. **HTML/CSS/JS template** — single `?>` exits PHP; inline `<style>` block; `<?= ?>` for output; inline `<script>` at end of body.

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
