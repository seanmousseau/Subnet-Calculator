# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development

```bash
# Run the app locally
php -S localhost:8080

# Syntax check
php -l index.php
```

There are no build steps, test suites, or package managers. The entire application is `index.php`.

## Architecture

Single-file PHP application. The file is structured top-to-bottom in this order:

1. **Configuration block** — all operator-tunable `$variables` (`$fixed_bg_color`, `$default_tab`, `$split_max_subnets`)
2. **PHP functions** — pure utility functions: IPv4 (`cidr_to_mask`, `calculate_subnet`, etc.), IPv6 (`ipv6_to_gmp`, `calculate_subnet6`, etc.), address type detection (`get_ipv4_type`, `get_ipv6_type`), subnet splitter (`split_subnet`)
3. **Request handling** — reads `$_GET`/`$_POST`, populates `$result`/`$result6`/`$error`/`$split_result`; GET triggers auto-calculation for shareable URLs
4. **Pre-HTML computed values** — `$bg_override_style`, `$share_url`
5. **HTML/CSS/JS template** — single `?>` exits PHP; inline `<style>` block; Jinja-style `<?= ?>` for output; inline `<script>` at end of body

### Key implementation details

- **IPv4 arithmetic**: always `& 0xFFFFFFFF` after bitwise ops — PHP's `ip2long()` returns a signed integer on 64-bit systems
- **IPv6 arithmetic**: uses PHP GMP extension (`gmp_init`, `gmp_and`, etc.) with `inet_pton()`/`inet_ntop()` and a hex string intermediary
- **CIDR paste auto-detection**: handled both server-side (in all GET/POST handlers via `strpos($input, '/')`) and client-side (JS `blur` event) to cover the case where a user types CIDR notation and presses Enter without blurring
- **Shareable URLs**: GET parameters auto-trigger calculation; JS prepends `window.location.origin + pathname` to the relative query string for display/copy
- **iframe auto-sizing**: `window.self !== window.top` detection adds `in-iframe` to `<html>`, activating CSS overrides (`min-height:0`, `align-items:flex-start`) and a `postMessage` height reporter via `ResizeObserver`
- **Theme**: `html[data-theme="light"]` CSS overrides; dark is default; `localStorage` persistence via inline `<script>` in `<head>` (runs before render to avoid flash)

## Branching

- Active development branch: `dev`
- All PRs target `main`
- Issues are labelled by release milestone (`v0.4`, `v0.5`, etc.)

## Session start hook

`.claude/hooks/session-start.sh` runs automatically in remote Claude Code sessions. It installs `php-gmp` if the GMP extension is not loaded (required for IPv6 calculation).
