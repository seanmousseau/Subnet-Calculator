# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **IPv6 range → CIDR converter.** New drawer entry on the IPv6 tab
  (`Range→CIDR`) mirroring the existing v4 Range tool. Pure GMP arithmetic
  so /128-wide ranges work without overflow. Available via UI,
  `POST /api/v1/range6`, and the shareable URL
  `?tab=ipv6&tool=range6&range6_start=…&range6_end=…`. First feature of
  v3.3.0 IPv6 Foundations.
- IPv6 supernet finder + summariser as new drawer entry on the IPv6 tab. Mirrors the v4 Supernet drawer. UI + `POST /api/v1/supernet6` + shareable URL.

### Changed

- **`range_to_cidrs` (v4) now enforces a configurable output cap.** Default
  256 CIDRs, configurable via `$range_max_cidrs` in `config.php`.
  Pathological fragmented inputs that previously returned thousands of
  CIDRs now return the first 256 with `truncated: true` in the response.
  API consumers should check the new `truncated` and `cap` response fields.
  Same cap applies to the new `range6_to_cidrs`.

## [3.2.4] - 2026-05-06

**Tab-bar polish.** The "VLSM IPv6" tab label wrapped to two lines on
every viewport (mobile and desktop alike) because `.tab-btn` had no
`white-space` rule, so the space inside the label broke at the
narrowest fit point. Single-line tab labels are the design intent;
the wrap was a latent CSS gap inherited from earlier releases.

### Fixed

- **Tab labels stay on one line.** Adds `white-space: nowrap` to
  `.tab-btn` in `assets/app.css`. "VLSM IPv6" now renders cleanly on
  one line at 375 px and up. No other tab text was wrapping; this is
  forward-compatible with future multi-word tab labels.
- **Bump `CACHE_NAME` to `sc-v3.2.4`** so the activate handler
  purges the v3.2.3 cache on rollout.

## [3.2.3] - 2026-05-05

**Admin service-worker hotfix.** Earlier releases shipped
`assets/app.js` with an unconditional
`navigator.serviceWorker.register('sw.js')` call that fired on every
page — including `/admin/*`. On admin pages the URL resolved to
`/admin/sw.js`, which did not exist, parking the registration in a
permanent "trying to install" state that DevTools could not
unregister (no installed worker to unregister yet). It also meant
the calculator's root-scope SW was sometimes intercepting admin
navigations and caching no-store admin HTML in the shell slot —
contributing to the v3.2.2 stale-shell symptoms.

### Fixed

- **Skip SW registration on `/admin/*`.** `assets/app.js` now
  detects admin paths and instead actively unregisters any
  registration whose scope falls under `/admin/`, breaking out of
  the stuck "trying to install" loop on next visit.
- **Tombstone `Subnet-Calculator/admin/sw.js`.** Deploys a real file
  at the previously-404ing URL whose only job is to call
  `self.registration.unregister()` on activate and force its
  controlled clients to navigate fresh, so existing stuck
  installations finally complete and clear themselves.
- **Root SW bypasses `/admin/*` in fetch handler.** Even where the
  calculator SW (scope `/`) was indirectly intercepting admin
  navigations, it now early-returns on those paths and lets the
  network handle them directly.
- **Bump `CACHE_NAME` to `sc-v3.2.3`** so the activate handler
  purges the v3.2.2 cache on rollout.

## [3.2.2] - 2026-05-05

**Service-worker hotfix.** The service worker's `CACHE_NAME` constant
had not been bumped since v2.7.0, so any browser that ever visited the
site was still serving the cached app shell from that release —
bypassing the origin entirely and masking every release in between
(including v3.2.0's admin UX overhaul and v3.2.1's polish). New
clients and freshly-cleared sessions were fine; long-lived sessions
in browsers like Vivaldi were stuck on a six-month-old shell.

### Fixed

- **Bump `sw.js` `CACHE_NAME` to `sc-v3.2.2`.** The activate handler
  now actually purges the old `sc-v2.7.0` cache and pre-fetches the
  current shell on first navigation. `sw.js` itself is served with
  `no-cache` (existing `.htaccess` rule), so the new worker file is
  picked up on the next visit.

### Changed

- **`CLAUDE.md` release checklist now reminds operators to bump
  `CACHE_NAME` in `sw.js` on every release.** Process gap that caused
  the staleness in the first place.

## [3.2.1] - 2026-05-05

**Admin polish.** Five fixes addressing visual + behavioural rough edges
on the v3.2.0 admin surface.

### Fixed

- **Login / TOTP card layout.** The signed-out admin shell (sign-in form
  and two-factor verification page) now renders a narrow centred card
  (~480 px) with vertically-stacked fields, replacing the stretched
  full-width layout that left the username/password row floating.
- **Sidebar leak on TOTP step.** `admin/totp-verify.php` no longer
  renders the admin nav sidebar while a session is `totp_pending` — the
  user sees only the verification card with an inline Sign-out fallback.
  Prevents a visual implication that the user is signed in before the
  second factor is verified.
- **Stale config reads after save.** `settings_parse_config_file()` now
  calls `clearstatcache()` + `opcache_invalidate()` before each
  `@include`, so the layered settings loader always sees the freshly
  written `config-admin.php`. Eliminates the "settings revert after
  logout / log back in" symptom caused by opcache holding a stale
  snapshot of the file.

### Changed

- **Settings page locks `config.php`-pinned rows.** Operator hand-edits
  in `config.php` continue to win over UI saves, but the Settings page
  now disables those inputs and shows a clearer "locked" badge + per-row
  banner ("Set in `config.php` and cannot be changed from the UI"). A
  new help card at the top of the page documents the precedence chain
  (`config.php` → `config-admin.php` → defaults). Server-side guards
  ignore submitted values for locked keys (so a disabled boolean
  checkbox cannot be coerced to `false` on POST) and reject reset
  attempts for locked keys with a flash error.

### Security

- **Block direct web access to `config-admin.php`.** Added matching
  `<Files>` deny + OpenLiteSpeed `RewriteRule [F,L]` entries in
  `Subnet-Calculator/.htaccess`. The admin-tier override file (which
  can hold writable secrets such as `$turnstile_secret_key`) is no
  longer publicly readable when the `<Files>` directive is honoured.

## [3.2.0] - 2026-05-05

**Admin UX overhaul.** Eight PRs (`#342`–`#349`) bring the entire `/admin/`
surface — login, logout, navigation, audit log, TOTP, API keys, and a new
Settings page — under one cohesive design language with first-class
accessibility, sticky chrome, sidebar navigation, and a schema-driven
config editor.

### Added

- **Admin chrome adoption (#345).** `/admin/` pages now share the
  calculator's header (logo, version pill, theme toggle) via the new
  `templates/_app_header.php` partial, render under a single outer
  `.card`, and inherit the v2.9.0 token palette for consistent
  light/dark theming. Interim footer link cluster lasts only until the
  sidebar lands in #344.
- **Cookie-session admin login (#342).** New `/admin/login.php` form
  replaces HTTP Basic Auth on web admin pages. Bcrypt + CSRF (timing-safe
  `hash_equals`) + per-IP rate limiter (`auth_rate_limit` table) +
  `sc_admin_sid` cookie (`HttpOnly; Secure; SameSite=Lax`). Plays nicely
  with password managers. The legacy Basic Auth path is retained
  exclusively for `/api/v1/admin/*`.
- **Logout button (#343).** New `/admin/logout.php` POST endpoint ends
  the session server-side, clears the cookie, and audit-logs `auth.logout`.
  CSRF-protected; `405 Method Not Allowed` on non-POST.
- **Left sidebar navigation (#344).** `admin/_sidebar.php` lists API
  Keys, Audit Log, TOTP / 2FA, Settings; marks the active row with
  `aria-current="page"` plus a 3 px teal left-border. Mobile (≤ 768 px)
  collapses to a hamburger drawer with focus trap, Escape close, and
  outside-click dismiss. Sidebar is suppressed on `login.php`.
- **Audit log polish (#346).** Filter strip is now a real
  `<div role="tablist">`. Action cells render colour-coded badges via the
  shared `audit_action_badge_class()` helper (suffix beats prefix —
  `auth.login.fail` is red, not blue). Sticky `<thead>`. Timestamps in
  `<time datetime="…Z">`. Disabled pagination is `<span aria-disabled>`
  not a focusable `<a tabindex=-1>`. Sr-only `<caption>`.
- **TOTP enrol + disable + usage tracking (#347).** Inline enrol flow
  on `/admin/totp.php` when no secret is configured (manual base32 +
  `<details>` URI + 6-digit verify form; persists via
  `admin_totp_enrol_persist()`). Disable sub-card requires a current
  TOTP or recovery code; clears the secret AND wipes recovery rows on
  success. Per-recovery-code usage list (row id + `used_at` + IP); last
  TOTP login timestamp surfaced in the Status sub-section.
- **API keys post-mint Copy + Got it controls (#348).** New-token panel
  gets a Copy button (`navigator.clipboard.writeText` with execCommand
  fallback, "Copied!" feedback for 1.5 s) and a Got it dismiss button.
  Empty state replaced with a one-paragraph nudge plus links to API
  reference and rate-limit headers.
- **Settings page (#349).** New `/admin/settings.php` surfaces 23
  config knobs across six visible sections (Branding, Forms / Captcha,
  API limits, Sessions, Admin & audit, Limits) plus a collapsed Advanced
  (CSP) `<details>`. Schema-driven validators (`string`, `int`, `float`,
  `bool`, `enum`, `multiselect`) reject bad input before disk write. Per-row
  source badges (`default` / `admin` / `config`) plus a `shadowed` tag
  + inline banner when a hand-edit in `config.php` overrides the wizard
  tier. Per-row reset-to-default button (greyed when shadowed). Secret
  inputs render masked (`••••••• (set)` / `(empty)`) and never round-trip
  the existing value. Per-section atomic write (tmp + rename) +
  `opcache_invalidate()`. Audit log gets one `config.update` row per
  changed key (secrets redacted to `(set)` / `(empty)`).

### Changed

- **`admin_recovery_codes` schema** gains a `used_via_ip TEXT` column
  via idempotent `PRAGMA table_info` probe + `ALTER TABLE`. Existing
  data unaffected.
- **`admin_recovery_verify_and_consume()`** now records the
  consumer's IP alongside `used_at`.
- **`admin_state` k/v table added** for single-row server state
  (currently only `last_totp_at`).
- **`admin_config_admin_set_keys()` merge helper** (in
  `functions-admin-wizard.php`) replaces or appends single-line
  `$key = …;` rows in `config-admin.php`. Used by the TOTP enrol /
  disable flow and the new Settings page.
- **`/admin/audit.php`** filter strip switched from button group to
  proper tablist semantics. Action badges restyled per
  `audit_action_badge_class()` mapping.

### Tests

- **PHPUnit:** 351 → 377 tests / 820 → 906 assertions / 14 skipped
  (`AdminSessionTest`, `AuditBadgeClassTest`, `AdminTotpEnhancementsTest`,
  `AdminSettingsTest`).
- **Playwright:** 952 → 1050 assertions across 31 new admin-related
  test groups covering login, logout, sidebar (current page / mobile
  collapse / keyboard order / login-absent), audit polish (tablist /
  badges / sticky thead / disabled pagination / time attrs / caption),
  TOTP enrol + audit events, keys Copy + empty state, and Settings
  (renders / validation rejects / secret masking / anchors + multiselect
  + reset).

### Pull Requests

- #351 (#345 admin chrome) — `feat(v3.2.0): #345 admin chrome adoption`
- #352 (#342 admin login form) — `feat(v3.2.0): #342 admin login form`
- #353 (#343 logout button) — `feat(v3.2.0): #343 logout button`
- #354 (#344 sidebar) — `feat(v3.2.0): #344 left sidebar for admin`
- #355 (#346 audit polish) — `feat(v3.2.0): #346 audit log polish`
- #356 (#347 TOTP enhancements) — `feat(v3.2.0): #347 TOTP enrol + disable + usage tracking`
- #357 (#348 keys polish) — `feat(v3.2.0): #348 keys post-mint Copy + helpful empty state`
- #358 (#349 settings page) — `feat(v3.2.0): #349 admin settings page — 23 knobs, schema validators`

## [3.1.3] - 2026-05-04

Tiny follow-up to v3.1.2.

### Fixed

- **Tree Editor session load returned 404** — v3.1.1's `loadSession()`
  fetched `api/v1/sessions?session_id=…`, but the API only routes
  `GET /api/v1/sessions/{id}` (path segment) — query-string lookups hit
  the front-controller's "not found" branch. Result: visiting a
  Save-Session URL or entering a session ID into the *Load saved session*
  form returned `Load failed: Not found.` and never hydrated the tree.
  Switched the fetch to the path-segment route.
- **`config.php.example` was missing several admin knobs** — added
  `$admin_totp_secret` (v3.0.0 / #313), `$admin_audit_retention_days`,
  `$admin_audit_trust_xff`, `$admin_audit_purge_strategy`,
  `$admin_audit_purge_sample_rate` (v3.0.0 / #306), and
  `$tree_presets_dir` (v3.1.0 / #323). All were already documented in
  `includes/config.php` but absent from the operator-facing example.

## [3.1.2] - 2026-05-04

Tiny follow-up to v3.1.1.

### Fixed

- **Tree Editor init forms remained visible after the editor opened** —
  the `.tree-editor-init` rule sets `display: flex`, which won out over
  the browser's default `[hidden] { display: none }`. So even though
  v3.1.1's `startEditor()` correctly set `hidden=true` on every init
  form, they kept rendering on top of the canvas. Added an explicit
  `.tree-editor-init[hidden] { display: none; }` rule.

## [3.1.1] - 2026-05-04

Hotfix on top of v3.1.0. Restores tree-editor sharing, lets operators
extend CSP without forking, and fixes the mobile heading layout.

### Fixed

- **Tree Editor share URL** — visiting a `?tree=…` link no longer stops
  at the "Start Editing" form. The encoded blob is now decoded on page
  load, used to populate the Root CIDR input, and the editor auto-starts
  with state hydrated from the URL.
- **Tree Editor session loading** — `Save Session` URLs with
  `?session_id=…` now actually load the saved tree. Added a "Load saved
  session" form to the editor init screen so a session ID can be entered
  manually without crafting a URL.
- **Mobile header truncation** — at viewports ≤ 480px the version pill
  hides so the "Subnet Calculator" heading no longer truncates to "Su…"
  next to the icon-button cluster.

### Added

- **CSP extension hooks** — three new config knobs
  (`$csp_connect_extra`, `$csp_script_extra`, `$csp_img_extra`) let
  operators allowlist additional origins on `connect-src`, `script-src`,
  and `img-src` without editing `index.php`. Default is empty (strict).
  The production deploy at subnetcalculator.app uses these to allowlist
  the Cloudflare Zaraz / GA4 beacons that Cloudflare injects at the CDN
  edge — previously those were blocked by `default-src 'self'`.
- **`connect-src` directive** — the CSP header now emits an explicit
  `connect-src` (default `'self'`, extensible via the config knob above)
  instead of relying on the `default-src` fallback.

## [3.1.0] - 2026-05-04

Polish + deferred-from-v3.0.0. Closes 8 issues across 9 PRs landing into
`release/v3.1.0`. Headlined by the multi-tree diff and the preset library
for the v3.0.0 tree editor; rounded out by IPv6 drawer parity, test-rig
hygiene, audit purge strategy, PHPCS admin coverage, history capture
parity, and keyboard ergonomics.

### Added

- **Multi-tree diff** (#322) — new "Diff" entry in the Tree Editor toolbar
  opens a modal accepting two source pickers (paste JSON, share URL,
  current draft). Computes added / removed / prefix-changed / rename /
  notes-only categories via canonical-CIDR keying; renders annotated
  tree with `data-diff` colour coding; "Copy diff as Markdown" export.
  Server-side `tree_diff()` in `includes/functions-tree-diff.php`
  available for tests + future API; current modal computes client-side.
  PHPUnit: +8 cases (`TreeDiffTest`); Playwright: new
  `test_tree_editor_diff_two_sessions` group.
- **Tree preset library** (#323) — new "Apply Template" toolbar entry
  with picker modal listing 6 starter presets:
  - LAN/DMZ/Mgmt split of a /24
  - HQ + 4×/20 branches of a /16
  - Three-tier (Production/Staging/Dev) /24
  - Equal split (parameterised 2/4/8/16-way)
  - IPv6 site /48 (8×/51)
  - IPv6 three-tier /48
  Operator-extensible: drop a JSON validating against
  `api/schemas/tree-preset.schema.json` into `data/tree-presets/` and it
  appears in the picker. Apply path replays each preset operation
  through the existing reducer so undo/redo works per-operation. New
  `GET /api/v1/tree-presets` (manifest) + `GET /api/v1/tree-presets/{id}`
  (full preset). PHPUnit: +6 cases (`TreePresetsTest`); Playwright:
  `test_tree_editor_apply_preset_then_undo`.
- **IPv6 VLSM save-session drawer parity** (#321) — IPv6 save-session UI
  moved from inline card into `tool-drawer[data-tool="session6"]`,
  matching the IPv4 pattern. New `Save Session` toolbar trigger; auto-
  open hint after `?session_id=` GET. Playwright:
  `test_vlsm6_session_drawer_pattern`.
- **Audit purge strategy** (#325) — new `$admin_audit_purge_strategy`
  config (`'inline' | 'sampled' | 'cron'`, default `'sampled'` at 0.1%);
  new `$admin_audit_purge_sample_rate` (`0.0`–`1.0`). `audit_log()`
  dispatches via switch; sanitiser fails closed (unknown strategies
  collapse to `'sampled'`, non-numeric rates collapse to `0.0`). New
  CLI `bin/sc-audit-purge.php` for the `'cron'` path; `bin/.htaccess`
  denies all web access. PHPUnit: +5 cases (`AuditPurgeStrategyTest`).
- **Test-rig admin-state drain** (#324) — new `admin/_test-drain.php`
  endpoint gated by `PHPUNIT_TEST_DRAIN_TOKEN` (404 when unset, 403 on
  mismatch via timing-safe `hash_equals()`). Truncates `api_keys`,
  `admin_audit`, `admin_recovery_codes`; clears `sess_*` files in the
  resolved session save directory with realpath confinement + symlink
  rejection. `Makefile` generates a fresh per-run token; `docker-compose
  .yml` forwards to both webapp + playwright-tests. CI `playwright.yml`
  switched to `make test-docker` so the token reaches the rig. Excluded
  from release tarballs via `tar --exclude=admin/_test-drain.php`.
  Playwright: `test_admin_drain_endpoint_zeroes_state`.
- **PHPCS admin scope** (#326) — new `.phpcs-admin.xml` template-aware
  ruleset extending PSR-12 with 7 wholesale exclusions (LineLength,
  SideEffects, ControlSignature, ScopeIndent, ScopeClosingBrace,
  ControlStructureSpacing, FirstExpressionLine — each justified inline
  in the XML). `.github/workflows/php.yml` runs both PHPCS gates per PR.
  Zero source-code changes to `admin/*.php` — ruleset absorbs every
  offender.
- **History pane capture extension** (#327) — `data-history-source` /
  `data-history-active` / `data-history-label` attribute trio on every
  tool result block (Lookup v4+v6, Diff v4+v6, Tree, Wildcard, Range,
  Supernet, Summarise, ULA). `captureCurrentPage()` prefers attributes;
  falls back to legacy four-container selectors for back-compat. Per-
  tool labels follow consistent voice (`Tool: <detail>`). Playwright: 3
  new groups (`test_history_captures_lookup_result`,
  `_diff_result`, `_wildcard_result`).
- **Keyboard tab-switch focus ergonomics** (#328) — pressing `1`–`4`
  switches tabs **and** focuses the first text input on the destination
  panel via `requestAnimationFrame`. Drops the previous "follow up with
  `/`" advice from the keyboard overlay help text. Playwright:
  `test_kbd_tab_switch_focuses_input` across 4 tabs.

### Changed

- **CSS token addition.** New `--color-warning-fg` token (amber, `#f59e0b`
  dark / `#b45309` light) — added because the warning/in-progress slot
  was missing from the v2.9.0 style guide. `--color-success-fg` and
  `--color-error-fg` are aliases pointing at existing tokens, not new
  primitives. All other tokens unchanged.
- **CI Playwright workflow** uses `make test-docker` as the canonical
  entry point (was bare `docker compose run`). Ensures the
  `PHPUNIT_TEST_DRAIN_TOKEN` env is generated and forwarded.

### Tests

- PHPUnit: 325 → 344 cases (+19); 669 → 794 assertions (+125). New test
  files: `AuditPurgeStrategyTest`, `TreeDiffTest`, `TreePresetsTest`.
- Playwright: 840 → 903 assertions across the suite (+63). New groups
  cover every PR's acceptance criteria; legacy groups updated where
  drawer markup changed (e.g. `test_session_forms_spacing` re-scoped to
  `#panel-vlsm` after IPv6 moved into a drawer).
- All gates green on fresh containers: PHPUnit, PHPStan L9, PHPCS PSR-12
  + admin ruleset, Spectral OpenAPI, Semgrep (php + owasp + sql-injection),
  ESLint + Stylelint.

### Pull requests

- PR1 (#330) — #324 test rig drains admin state
- PR2 (#331) — #326 PHPCS admin scope
- PR3 (#332) — #325 audit purge strategy
- PR4 (#333) — #321 IPv6 VLSM drawer parity
- PR5 (#334) — #328 tab-switch focus ergonomics
- PR6 (#335) — #327 history `data-history-source` extension
- PR7 (#336) — #322 multi-tree diff
- PR8 (#337) — #323 tree presets / template library
- PR9 — Release cut (this release)

## [3.0.0] - 2026-05-04

Major release. Headlined by an interactive subnet tree editor; rounded out by a
substantial admin-hardening pass and several power-user UX improvements.

### Added

- **Interactive subnet tree editor** (#302) — new "Tree Editor" panel in the IPv4
  Tools drawer. Click-split picker (2 / 4 / 8 / 16 children); desktop drag-merge
  between siblings; mobile action sheet (`@media (hover: none)`) replacing
  drag-merge on touch; inline rename + notes per node; undo / redo
  (`Ctrl/Cmd+Z`, `+Shift`; 50-deep stack); 300 ms-debounced `localStorage`
  autosave under `sc.tree.draft.<rootCidr>`; exports — copy as CIDR / Markdown
  / Cisco, CSV (lossy) / JSON (lossless) download, base64url shareable URL
  (`?tree=…`) with a 50-node soft cap; Save Session via the existing
  `POST /api/v1/sessions` endpoint with the new `type: 'tree'` payload form;
  plain JS (~676 LOC), no new runtime dependencies. New PHP helper
  `tree_validate()` enforces canonical CIDR form, containment, sibling
  non-overlap (gaps allowed), `name` ≤128 / `notes` ≤1024, depth ≤16, total
  nodes ≤1024, and single-family per tree.
- **Admin first-run wizard** (#311) — when `$admin_ui_enabled` is on but
  `$admin_pass_hash` is empty, `/admin/keys.php` shows a one-shot setup form.
  Atomic temp-file + rename writer to `Subnet-Calculator/config-admin.php`
  (sibling to `config.php`, never overwrites); double-submit CSRF via dedicated
  cookie since no auth context exists yet; manual fallback shows a
  `var_export` snippet for read-only filesystems. Self-locks once
  `$admin_pass_hash` is set. Audit-logs `wizard.complete`.
- **TOTP / 2FA + recovery codes for `/admin/`** (#313) — pure-PHP RFC 6238
  in `functions-admin-totp.php` (~250 LOC including recovery codes). Base32
  codec, HOTP truncation, ±1 step drift, 6 digits, 30 s period, 160-bit
  secret. New `admin_recovery_codes` SQLite table; 10 bcrypt-hashed codes
  minted per regenerate, formatted `XXXX-XXXX`, shown once. New
  `/admin/totp.php` (status banner, generate-secret helper, regenerate-codes
  button) and `/admin/totp-verify.php` (step-up form). API callers use
  `X-Admin-TOTP` header (or recovery code); UI callers complete the form
  and the result is cached in the `sc_admin` PHP session for 30 min.
  Audit-logs `login.totp.fail`, `totp.recovery.use`, `totp.recovery.regenerate`.
- **Admin audit log** (#306) — new `admin_audit` SQLite table + paginated
  `/admin/audit.php` with prefix filter (`login.` / `key.` / `wizard.` /
  `totp.`). Hooks: `login.ok`, `login.fail`, `key.mint`, `key.revoke`,
  `key.rate_limit`, `wizard.complete`, plus the TOTP events above. Lazy
  purge on every write; `$admin_audit_retention_days` config (default 90).
- **Per-API-key rate-limit overrides** (#312) — `ALTER TABLE api_keys ADD
  COLUMN rate_limit_rpm INTEGER NULL` (idempotent migration).
  `apikey_create()` accepts an optional RPM; new `apikey_set_rate_limit()`.
  `api_rate_limit()` 3-tier lookup: static `$api_rate_limit_tokens` →
  SQLite per-key → global fallback. `/admin/keys.php` mint form gained an
  RPM field plus a per-row inline editor; new `PATCH /api/v1/admin/keys/{id}/rate-limit`.
- **IPv6 VLSM session persistence** (#315) — `vlsm-session.schema.json`
  rewritten as `oneOf` over a `type: 'ipv4' | 'ipv6' | 'tree'` discriminator
  (schema v2; `$id` ends in `.v2.schema.json` so pinned consumers don't break).
  IPv6 `hosts` accepts integer **or** the `"2^N"` string form. Missing
  `type` defaults to `'ipv4'` for back-compat. New IPv6 "Save & Restore IPv6
  Session" card on the IPv6 VLSM panel. Share URLs are tab-bound
  (`?tab=vlsm` vs `?tab=vlsm6`).
- **Keyboard shortcut overlay** (#300) — global modal triggered by `?` (Shift+/)
  or a header icon button. Implemented shortcuts: `1`–`4` switch tabs;
  `/` focuses the first text input on the active tab; `Ctrl/Cmd+R`
  intercepted to reset the active tab; `Ctrl+Shift+C` copies the first
  `.result-value`; `H` opens history. `?` and `/` are ignored while typing
  in inputs. The header help button is hidden via `@media (hover: none)`.
- **Recent calculations history** (#301) — opt-in pane in the header (`H`
  key or icon button). Backed by `localStorage` (`sc.history.enabled`,
  `sc.history.entries`, capped at 50 FIFO). Captures the current page URL
  on load whenever any result block is present; consecutive duplicates
  de-duped. Per-row remove + Clear All; clicking an entry re-runs via
  `location.assign`.

### Changed

- **Session schema bumped to v2.** Versioned via `$id` so callers pinned to
  the v1 URL keep working unchanged. The schema is permissive on the `tree`
  branch from PR1; PR3 activated the strict `tree_validate()` server gate.
- **Admin config split.** `config-admin.php` is the wizard's write target;
  `config.php` remains hand-edited only. `index.php` requires both.
- **`admin/` is now in the Semgrep scan path** (was previously not). 0 new
  findings; capturing this so future changes there don't go unscanned.

### Fixed

- **Dockerfile printf escape bug** in the test-rig `config.php` generator —
  inherited from PR1; single-quoted printf format was treating `\$` as a
  literal `\$`, producing a parse error in the generated file. Fixed by
  dropping the backslashes; added `php -l` on the generated file as a
  build-time guard.

### Tests

- PHPUnit: 226 → 325 cases (+99). New test files: `AuditTest`,
  `AdminWizardTest`, `AdminTotpTest`, `TreeValidateTest`. Existing
  `ApiKeysTest`, `SessionTest`, `SchemaTest` extended.
- Playwright: 720 → 840 assertions (+120 across ~40 new test groups
  covering admin auth, audit, TOTP, wizard, keyboard overlay, history
  pane, tree editor split / merge / rename / undo / autosave / save / share /
  copy formats / mobile action sheet / share-too-large fallback).
- All QA gates green on fresh containers: PHPUnit, PHPStan L9, PHPCS PSR-12,
  Spectral OpenAPI, Semgrep (php + owasp + sql-injection), ESLint + Stylelint.

### Pull requests

- PR1 (#317) — Foundations (#315, #312, #306, #307 smoke set)
- PR2 (#318) — Admin hardening (#311, #313, #307 matrix)
- PR3a (#319) — Power-user UX (#300, #301)
- PR3b (#320) — Tree editor (#302)
- PR4 — Release cut (this release)

## [2.12.1] - 2026-05-03

Hotfix for the v2.12.0 admin UI deploy.

### Fixed

- **Meta endpoint resilient to opcache staleness** — `api/v1/index.php` line
  111 read `$admin_ui_enabled` directly. After a v2.11.0→v2.12.0 deploy the
  PHP-FPM workers can hold pre-v2.12.0 bytecode for `includes/config.php`
  during the warm-restart window, leaving the variable undefined on a few
  early requests. PHP then emitted `Warning: Undefined variable` HTML
  before the JSON envelope, corrupting the meta response body. Now wrapped
  in `($admin_ui_enabled ?? false)` so the read is undefined-safe regardless
  of opcache state. No functional change once opcache catches up.

## [2.12.0] - 2026-05-03

API & integration enhancements release: rate-limit headers exposed in client-readable
HTTP response headers, JSON-Schema export for VLSM session payloads, and a new
admin UI / JSON API for self-hosters to mint, list, and revoke API keys without
editing `config.php`.

### Added

- **API rate-limit headers** (#298) — every response (including 4xx) now carries
  `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `X-RateLimit-Reset` when
  rate limiting is active, alongside the existing `Retry-After` on 429s.
  Implemented in `api_rate_limit()` in `api/v1/helpers.php`. Documented under
  `components.headers` in the OpenAPI spec and referenced explicitly on every
  documented `429` response and on the `/` and `/schemas/vlsm-session` `200`
  responses.
- **VLSM session JSON-Schema** (#299) — new Draft 2020-12 schema at
  `Subnet-Calculator/api/schemas/vlsm-session.schema.json` describing the
  shape persisted by `POST /sessions` and returned by `GET /sessions/{id}`.
  Served at `GET /api/v1/schemas/vlsm-session` with
  `Content-Type: application/schema+json`. New docs page `docs/schemas.md`.
- **API key management UI** (#297) — opt-in via `$admin_ui_enabled = true`
  plus `$admin_user` / `$admin_pass_hash` (bcrypt) in `config.php`. Provides:
  - Server-rendered admin web UI at `/admin/keys.php` (HTTP Basic, no JS,
    CSRF-protected).
  - JSON CRUD parallel at `/api/v1/admin/keys` — `GET` list, `POST` mint,
    `DELETE /{id}` revoke.
  - SQLite-backed `api_keys` table (token bcrypt-hashed; plaintext shown
    once at creation).
  - Token format `sk_live_<32 hex>`; first 8 hex chars stored as a public
    prefix for UI identification.
  - `api_authenticate()` extended to check SQLite keys after `$api_tokens`.
    Both auth methods coexist; either one being non-empty triggers
    auth-required mode.
  - New docs page `docs/admin.md`.

### Tests

- PHPUnit grew from 226 tests / 379 assertions (v2.11.0) to **245 tests /
  493 assertions** (14 still GMP-skipped):
  - 7 new `SchemaTest.php` cases covering schema dialect, structure, regex
    pattern, example conformance, and handler MIME advertisement.
  - 11 new `ApiKeysTest.php` cases covering token format, bcrypt storage,
    create/verify/list/revoke/record-use, name validation, and prefix
    collision resolution.

### Docs

- `mkdocs.yml` `extra.version` bumped to 2.12.0; new nav entries for
  `schemas.md` and `admin.md`.
- `docs/index.md` tarball example bumped to `subnet-calculator-2.12.0.tar.gz`.
- `Subnet-Calculator/config.php.example` extended with the new admin / API
  key block; `$api_allowed_endpoints` allowlist comment refreshed to list
  every endpoint name (including `schemas` and `admin`).

## [2.11.0] - 2026-04-27

### Added
- **IPv6 VLSM Planner** (#293) — `vlsm6_allocate()` GMP-backed allocator in `includes/functions-vlsm6.php`; `POST /api/v1/vlsm6` endpoint accepts integer or `"2^N"` host counts (N is 0–128); full UI parity with the IPv4 planner on the IPv6 tab — input table, dynamic rows, results table with CSV / JSON / ASCII exports (XLSX intentionally omitted), Copy All button, utilisation summary card, shareable URLs, reset, keyboard-delete. New `docs/ipv6-vlsm.md`.
- **Inverse Subnet Lookup** (#295) — `lookup_ips()` in `includes/functions-lookup.php`; `POST /api/v1/lookup` endpoint; dual-tab tool drawer (IPv4 + IPv6); shared `sc_run_lookup()` POST/GET helper in `request.php`; GET shareable URLs; deepest-prefix-match semantics; mixed IPv4/IPv6 input supported. Caps configurable via `$lookup_max_cidrs` (default 100, hard ceiling 1000) and `$lookup_max_ips` (default 1000, hard ceiling 10000). New `docs/lookup.md`.
- **Subnet Aggregation Diff** (#296) — `subnet_diff()` in `includes/functions-diff.php`; `POST /api/v1/diff` endpoint; dual-tab tool drawer; BEM-style colour-coded result groups (added / removed / changed / unchanged); shared `sc_run_diff()` POST/GET helper; `templates/_diff_result.php` shared partial; GET shareable URLs; canonical-form normalisation (host bits zeroed, IPv6 lowercased + compressed) before comparison. 1000-entry caps per side. New `docs/diff.md`.
- **Copy-as-Markdown / Copy-as-Cisco exports** (#294) — JS-only feature (`buildMarkdown` + `buildCiscoConfig` in `app.js`); new `Copy as Markdown` and `Copy as Cisco` buttons added to IPv4 / IPv6 / VLSM / splitter result panels via a shared `.copy-actions` flex container; help bubble notes that Cisco output is generic IOS-style. New `docs/exports.md`.

### Changed
- **Footer** — the GitHub source link in the footer was trimmed from the full GitHub URL to the single word "GitHub" (#293).

### Tests
- PHPUnit grew from 200 tests / 313 assertions (v2.10.0) to **226 tests / 379 assertions** (14 still GMP-skipped).
- Playwright groups grew from 91 (v2.10.0) to **131 groups**, covering each new feature: IPv6 VLSM UI / API / shareable URL / exports / utilisation summary; inverse subnet lookup UI + API + shareable URL; subnet diff UI + API + shareable URL + canonical-form behaviour; copy-as-Markdown / copy-as-Cisco button presence and clipboard payload.

### Docs
- `docs/api.md` updated with reference sections for `/vlsm6`, `/lookup`, `/diff` (curl, response shape, caps, error matrix, cross-link to per-feature pages).
- New per-feature pages: `docs/ipv6-vlsm.md`, `docs/lookup.md`, `docs/diff.md`, `docs/exports.md` — added to `mkdocs.yml` nav.
- `mkdocs.yml` `extra.version` bumped to 2.11.0; `docs/index.md` tarball example bumped to `subnet-calculator-2.11.0.tar.gz`.

## [2.10.0] - 2026-04-27

### Added
- **Wildcard mask ↔ CIDR converter** — new tool drawer entry on the IPv4 tab and `POST /api/v1/wildcard` endpoint that converts between Cisco-style wildcard masks (e.g. `0.0.0.255`) and CIDR prefixes (e.g. `/24`). Non-contiguous masks are rejected. Closes #292.
- **`/sitemap.xml`** — static sitemap published at the app root listing app + docs URLs, advertised in `robots.txt`. Closes #285.
- **CI matrix** — `.github/workflows/php.yml` now runs PHPUnit, PHPStan (level 9), and PHPCS against PHP 8.2, 8.3, and 8.4 in parallel. (PHP 8.1 omitted from the matrix because PHPUnit 11 and PHPStan 2 dev-dependencies both require 8.2+; the app runtime still supports 8.1.) Closes #287.
- **OpenAPI `externalDocs`** — top-level `externalDocs.url` block added pointing at `https://docs.subnetcalculator.app/api/`. Closes #290.

### Changed
- **Documentation URL** — every in-app and in-repo reference to the legacy `seanmousseau.github.io/Subnet-Calculator` URL now points at the canonical custom domain `https://docs.subnetcalculator.app/` (README, footer link, Playwright assertion). Closes #284.
- **Print stylesheet** — `@media print` now redefines all dark-theme CSS custom properties to print-safe light values when `data-theme="dark"` is active, so users in dark mode get readable, ink-efficient pages instead of dark surfaces. Teal accent dimmed to `#0a8f6b` for paper. Closes #291.

## [2.9.2] - 2026-04-26

### Added
- **Light + dark mode logo variants** — every brand asset (`logo`, `favicon-32`, `apple-touch-icon`) now ships in both light and dark variants, in PNG and WebP. Canonical sources live in `/logo/`; previous versions archived to `/logo/old/`.
- **Adaptive favicon** — browser tab icon now switches between dark-mode (teal on transparent) and light-mode (navy on transparent) automatically via `prefers-color-scheme` media queries on the `<link rel="icon">` tags.

### Fixed
- **`apple-touch-icon.webp` white background** — the WebP variant was carrying an opaque white background while the PNG variant was correct (`#0a1a12` baked bg). WebP regenerated to match the PNG.
- **README header** — refreshed to v2.9.x style guide with light/dark logo switching via `<picture>` + `prefers-color-scheme`.

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
