# CLAUDE.md

Agent quick-reference for this repository. **Source of truth for "how this project works" is `docs/internal/`** — start there for anything beyond the commands and operational quirks below.

## Internal documentation

| If you need… | Read |
|---|---|
| Architecture, invariants, design decisions | [`docs/internal/design-document.md`](docs/internal/design-document.md) |
| UX principles before adding tools / changing UI | [`docs/internal/design-guide.md`](docs/internal/design-guide.md) |
| PHP / JS / CSS conventions before writing code | [`docs/internal/coding-guide.md`](docs/internal/coding-guide.md) |
| Test layers, when to add what | [`docs/internal/testing-guide.md`](docs/internal/testing-guide.md) |
| Threat model + trust boundaries | [`docs/internal/security-model.md`](docs/internal/security-model.md) |
| API versioning + breaking-change policy | [`docs/internal/api-contract.md`](docs/internal/api-contract.md) |
| SQLite schemas + migration policy | [`docs/internal/data-dictionary.md`](docs/internal/data-dictionary.md) |
| Operator-tunable config variables | [`docs/internal/config-reference.md`](docs/internal/config-reference.md) |
| What to do when production breaks | [`docs/internal/runbooks.md`](docs/internal/runbooks.md) |
| Index + reading order | [`docs/internal/README.md`](docs/internal/README.md) |

## Hot invariants (must hold in working memory)

Full list: [`docs/internal/design-document.md`](docs/internal/design-document.md) §5 (22 invariants). The ones you cannot afford to look up:

1. **IPv4 arithmetic:** mask every bitwise op with `& 0xFFFFFFFF` — `ip2long` is signed on 64-bit.
2. **IPv6 arithmetic:** GMP only. Subnet counts ≥ 2^63 return as the string `"2^N"`.
3. **Output escaping:** `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')` on every echo.
4. **Production deploy:** anchored rsync excludes (`--exclude='/config.php' --exclude='/data/'`) + `sleep 3` after `systemctl restart lsws`.
5. **`CACHE_NAME` in `sw.js`:** bump every release. Skipped bumps break the service-worker shell silently (the v3.2.2 lesson).
6. **`--color-bg` CSS custom property:** never rename. iframe consumers depend on the exact name.
7. **Pure functions + boundary validation:** `request.php` / handlers validate; functions in `includes/*.php` trust their inputs.

## Development

**Prereqs (macOS):** `brew install gnu-tar`. The release build uses `gtar` to strip build-user UID/GID and skip macOS PAX xattr records that bsdtar would materialise as `._FILENAME` AppleDouble sidecars.

**QA gates (PHPUnit, PHPStan, PHPCS, ESLint/Stylelint, Spectral, Semgrep, `make test-docker`):** canonical command list lives in [`testing-guide.md`](docs/internal/testing-guide.md) "Running tests locally." Run them all green before pushing any PR.

```bash
# Run the app locally (serve the Subnet-Calculator/ subfolder)
php -S localhost:8080 -t Subnet-Calculator/

# Deploy to dev test instance for manual inspection
rsync -a --delete Subnet-Calculator/ root@192.168.80.15:/opt/container_data/dev.seanmousseau.com/html/testing/sc/
scp testing/fixtures/iframe-test.html root@192.168.80.15:/opt/container_data/dev.seanmousseau.com/html/testing/sc/

# Build a release tarball (admin/_test-drain.php is test-rig only — never ship it; #324)
cp CHANGELOG.md Subnet-Calculator/CHANGELOG.md
gtar --owner=0 --group=0 --numeric-owner \
     --exclude='admin/_test-drain.php' \
     -czf releases/subnet-calculator-X.Y.Z.tar.gz -C Subnet-Calculator .
rm Subnet-Calculator/CHANGELOG.md
```

Release checklist: [`design-document.md`](docs/internal/design-document.md) §10.2. Run `/release` to automate steps 1–7.

## Repository layout (top-level only)

```
Subnet-Calculator/      ← docroot (point webserver here)
  index.php, sw.js, .htaccess, config.php.example
  includes/             ← pure functions; web-blocked
  templates/            ← layout.php + _tools/<slug>.php partials
  assets/               ← app.css, app.js, vendored SheetJS
  api/v1/               ← router + handlers/<name>.php
  admin/                ← TOTP-gated operator console
  data/                 ← SQLite DBs; web-blocked; git-ignored
docs/
  internal/             ← architecture + guides + runbooks (this set)
  superpowers/          ← per-release plans, specs, style guide
  ...mkdocs sources...  ← user-facing docs deployed to GH Pages
releases/               ← versioned tarballs
testing/                ← unit, scripts (Playwright), snapshots, fixtures
.github/workflows/      ← php.yml (3-version matrix), docs.yml
.claude/                ← skills + hooks
```

Full annotated layout: [`design-document.md`](docs/internal/design-document.md) §3.

## Architecture (TL;DR)

PHP application with a slim entry point (`index.php`) that requires all `includes/`, runs `request.php` to dispatch input, then renders `templates/layout.php` which `require`s per-drawer partials from `templates/_tools/<slug>.php`. The REST API at `/api/v1/*` calls the **same pure functions** as the web UI; only input shape and output rendering differ. SQLite (sessions, admin, audit, API keys) is the only persistence layer. GMP is the IPv6 math runtime.

Full architecture + execution order: [`design-document.md`](docs/internal/design-document.md) §2.

## Branching

Branching model, naming, patch + hotfix flow, and merge style: [`design-document.md`](docs/internal/design-document.md) §10.1.

## Git and GitHub (operational quirk)

The local git remote (`origin`) points to a session-scoped proxy at `127.0.0.1`. **`git push` output is not reliable confirmation that commits reached GitHub** — the proxy accepts the push locally but may not forward it depending on the session. Always verify that commits actually landed on GitHub using the MCP GitHub tools (e.g. `mcp__github__list_commits`) before declaring a push successful.

To write files to GitHub from a Claude Code session, prefer `mcp__github__push_files` for small files (works up to ~20 KB per file). For larger files (e.g. `index.php` at ~64 KB, release tarballs at ~600 KB), instruct the user to push from their own machine.

## Claude automations

**Skills** (invoke with `/skill-name`):
- `/deploy-test` — rsync to dev server + run CDP browser suite
- `/release` — full release checklist (version, changelog, internal-docs sync, tarball, PR)

**Hooks** (PostToolUse, auto-run on every PHP edit):
- `php -l` — syntax check (~50ms)
- `phpstan analyse` — level 9 static analysis (~2s)

**Session start hook** (`.claude/hooks/session-start.sh`): installs `php-gmp` in remote sessions if missing.
