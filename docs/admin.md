# Admin UI & API Key Management

**v2.12.0+** — Self-hosters can mint, list, and revoke API keys through a
built-in admin UI rather than editing `$api_tokens` in `config.php`. Keys
are bcrypt-hashed in SQLite; the plaintext token is shown exactly once at
creation.

## Enabling the admin UI

Set three values in `config.php`:

```php
$admin_ui_enabled = true;
$admin_user       = 'admin';                    // any non-empty string
$admin_pass_hash  = '$2y$10$...';                // see below
```

Generate the password hash on the server with PHP's CLI:

```bash
php -r "echo password_hash('your-password-here', PASSWORD_BCRYPT) . PHP_EOL;"
```

Paste the `$2y$10$…` output as `$admin_pass_hash`. Never commit `config.php`.

The admin UI is only meant to be reached over TLS. If your install is behind
a reverse proxy, terminate TLS there and ensure `/admin/` is not proxied to
plain HTTP.

## Using the web UI

Navigate to `https://your-host/admin/keys.php`. The browser will prompt for
the admin username + password (HTTP Basic Auth). Each request re-authenticates
— there is no session cookie. After login you can:

### Shared admin chrome (v3.2.0+, #345)

Starting in v3.2.0 the admin pages render through the same shared layout as
the calculator. Each admin page (`keys.php`, `audit.php`, `totp.php`,
`totp-verify.php`, the first-run wizard) shows the calculator's logo,
version pill, and theme toggle in the page header, plus a breadcrumb chip
between the version pill and the theme toggle. The dark/light theme toggle
now works on `/admin/` (it previously force-rendered dark because the
admin pages did not load the toggle script).

Each admin page has exactly one outer `.card` wrapping all sub-sections.
Sub-sections render as `<section>` blocks separated by a 1px divider — no
nested cards. Inputs share the calculator's `--color-input-bg` token, and
form layouts reuse the calculator's `.form-group` / `.splitter-btn`
components. Destructive actions use a token-driven `.btn-danger` class.

An interim footer link cluster (API Keys · TOTP / 2FA · Audit Log) appears
at the bottom of every admin page with the active page rendered as a
non-link `aria-current="page"` text node. The cluster will be replaced by
a left sidebar in v3.3.0 (#344).

### Available actions


- **Mint a key:** enter a human-readable name and submit. The token is shown
  once on the next page; copy it immediately. After page reload it is gone.
- **List keys:** see name, public prefix (first 8 hex chars), creation time,
  last-used time, and active/revoked status.
- **Revoke a key:** click *Revoke*. Revocation is immediate and permanent.

### Post-mint UX (v3.2.0+, #348)

After a successful mint, the new-token panel renders an inline `<code>`
block with two buttons:

- **Copy** — writes the token to the system clipboard via
  `navigator.clipboard.writeText()` (with a `document.execCommand('copy')`
  fallback for legacy browsers / cross-origin iframes). Flips to "Copied!"
  for 1.5 seconds on success, then reverts.
- **Got it** — removes the panel from the DOM. The panel is one-shot per
  mint anyway (the next page load clears the PRG flash), so the dismiss
  button is purely an explicit "I've copied it" affordance.

The empty state (no keys minted yet) now shows a one-paragraph nudge
explaining what keys authenticate against, with links to the
[API reference](https://docs.subnetcalculator.app/api/) and
[rate-limit headers](https://docs.subnetcalculator.app/api/#rate-limiting)
docs so an operator can find the next step without leaving the admin UI.

## Using the JSON API

Mirror endpoints under `/api/v1/admin/keys` accept the same Basic Auth and
respond with the standard JSON envelope. Useful for scripting or CI:

```bash
# List
curl -u admin:pass https://host/api/v1/admin/keys

# Mint
curl -u admin:pass -X POST -H 'Content-Type: application/json' \
  -d '{"name":"production"}' https://host/api/v1/admin/keys

# Revoke
curl -u admin:pass -X DELETE https://host/api/v1/admin/keys/42
```

## Auth semantics

API authentication is governed by what is configured:

| `$api_tokens`       | Active SQLite keys exist | Bearer required? |
|---------------------|--------------------------|------------------|
| `[]`                | no                       | no — open API    |
| `[]`                | yes                      | yes              |
| `['static-tok']`    | no                       | yes              |
| `['static-tok']`    | yes                      | yes              |

In other words: minting your first SQLite key flips the API from open to
auth-required. Revoking the last active SQLite key flips it back to open
(unless `$api_tokens` is non-empty). To keep the API permanently locked
regardless of mint/revoke state, set at least one entry in `$api_tokens`.

Static `$api_tokens` and SQLite-stored keys are checked in that order; both
auth methods coexist. Static tokens still rotate by editing `config.php`.

## Token format

Minted tokens look like:

```text
sk_live_a1b2c3d4e5f6...   (8-char "sk_live_" prefix + 32 random hex chars)
```

The first 8 hex characters become the public prefix used to identify the
key in the UI without storing the secret. The remaining 24 hex chars are
known only to the holder; the database stores a bcrypt hash of the full
token.

## Storage

The `api_keys` table is created in:

1. `$apikey_db_path` if set, otherwise
2. `$session_db_path` if set, otherwise
3. `<docroot>/../data/sessions.sqlite` (alongside `rate_limit`).

Schema:

```sql
CREATE TABLE api_keys (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  name          TEXT    NOT NULL,
  prefix        TEXT    NOT NULL,
  token_hash    TEXT    NOT NULL,
  created_at    INTEGER NOT NULL,
  last_used_at  INTEGER,
  revoked_at    INTEGER
);
CREATE INDEX idx_api_keys_prefix ON api_keys(prefix);
```

`token_hash` is the only secret column. Even with read access to the DB,
an attacker would need to crack the bcrypt hash to recover the token.

## Security notes

- **TLS is your responsibility.** HTTP Basic transmits the password on
  every admin request — never expose `/admin/` over plain HTTP.
- **Bcrypt cost is the default 10 rounds.** Each admin auth check ~100ms
  on a modern CPU; resilient to brute force at the rate-limit ceiling.
- **No session cookies.** Admin re-authenticates every request, so there
  is no session to steal.
- **CSRF protection on the web UI.** The mint/revoke forms include a
  per-request token derived from the admin password hash + client IP;
  attackers without the admin credentials cannot construct a valid token.
- **Rate limiting still applies.** The same `$api_rate_limit_rpm` ceiling
  guards `/api/v1/admin/*` endpoints.
- **Audit trail.** `last_used_at` updates on every successful API call,
  so you can spot keys that haven't been used in a while and revoke them.

## Per-key rate limits (v3.0.0+, #312)

Each SQLite-stored API key may carry an `rate_limit_rpm` override that takes
precedence over the global `$api_rate_limit_rpm` ceiling. Set it in the
**RPM** column on `/admin/keys.php`:

- **blank** → key inherits the global default.
- **`0`** → unlimited for this key.
- any positive integer → that RPM ceiling for this key.

The lookup order in `api_rate_limit()` is: static `$api_rate_limit_tokens`
override → SQLite per-key override → global `$api_rate_limit_rpm`. Keys
bucket by row id (`key:N`) so two tokens that share a prefix (a 1-in-4-billion
collision) cannot interfere with each other's rate-limit window.

There is no upper bound on the SQLite override — operators who configure
themselves into a denial-of-service can revoke and re-mint at any time.

JSON API:

```bash
# Mint a key with a 600 RPM ceiling
curl -u admin:pass -X POST -H 'Content-Type: application/json' \
  -d '{"name":"hot-svc","rate_limit_rpm":600}' \
  https://host/api/v1/admin/keys

# Update an existing key's override; null clears it back to the global default.
# Note: 0 means "unlimited" (no rate-limit cap), NOT "clear" — use null for that.
curl -u admin:pass -X PATCH -H 'Content-Type: application/json' \
  -d '{"rate_limit_rpm":null}' \
  https://host/api/v1/admin/keys/42/rate-limit
```

## TOTP / 2FA management (v3.2.0+, #347)

`/admin/totp.php` is the operator's TOTP configuration surface. The
*login-time* verification flow (when `totp_pending = 1` on a session row)
is covered separately in [admin-login.md](admin-login.md). This section
covers the configuration page itself.

The page renders three sub-sections under one outer card:

- **Status** — always shown.
- **Enrol** — only when `$admin_totp_secret` is empty.
- **Recovery codes** + **Disable TOTP** — only when TOTP is enabled.

### Status card

Shows:

- Enabled/disabled badge.
- **Last TOTP login** — UTC timestamp, wrapped in `<time datetime="…Z">`
  so screen readers and locale-aware browsers can reformat it. Reads from
  `admin_state['last_totp_at']`, set by `admin/totp-verify.php` on every
  successful step-up. Displays "never" before the first verify.
- **Recovery codes: N unused / total** — counts derived from
  `admin_recovery_codes`. Includes used rows in the total so the operator
  can see how many codes have been spent.

### Enrol flow

When `$admin_totp_secret` is empty, the page renders an inline enrol
sub-card:

- A fresh secret is generated and cached in the operator's PHP session
  (`$_SESSION['totp_enrol_secret']`). The secret is never written to disk
  before verification — abandoning the flow has no on-disk side-effect.
- The base32 secret is shown in a copyable inline `<code>` block.
- A `<details>` expander reveals the `otpauth://` URI for authenticator
  apps that accept URI paste rather than manual base32 entry.
- A verify form (`<input type="text" inputmode="numeric" pattern="[0-9]{6}"
  autocomplete="one-time-code">`) accepts the 6-digit code from the
  authenticator. On match, `admin_totp_enrol_persist()` writes
  `$admin_totp_secret = '…'` into `config-admin.php` via the merge helper;
  the page reloads with TOTP enabled.

Audit events:

| Event | When | Notes |
| --- | --- | --- |
| `totp.enrol.start` | First GET of the page when TOTP is disabled (once per session, not per refresh). | Session flag `$_SESSION['totp_enrol_started']` debounces. |
| `totp.enrol.verify.ok` | Verify form accepted; secret written. | Cached enrol session keys cleared. |
| `totp.enrol.verify.fail` | Verify form code didn't match. | Page re-renders with the same cached secret. |

### Recovery codes

Same one-shot mint flow as v3.0.0 — codes are shown exactly once at
generation and stored as bcrypt hashes. v3.2.0 adds usage tracking:

```sql
ALTER TABLE admin_recovery_codes ADD COLUMN used_via_ip TEXT NULL;
```

The migration is idempotent — `admin_recovery_db_init()` probes
`PRAGMA table_info` before running the ALTER, so existing installs
upgrade on the first admin request after deploy.

A `<details>` expander on the page lists every recovery code row:

- Row id + status (`used` / `unused`).
- For used rows: `used_at` (UTC, in a `<time datetime>` element) and
  `used_via_ip` (the direct client IP at consumption, or the `X-Forwarded-For`
  first hop if `$admin_audit_trust_xff` is set).

Plaintext codes are unrecoverable by design — the operator gets
forensic information ("a recovery code was used at *T* from *IP*") without
the page ever displaying or storing the original code string.

Helper copy on the page reminds the operator: codes are shown once at
generation, cannot be retrieved later, and Regenerate invalidates all
previous codes (used or unused).

### Disable TOTP

Bottom-of-page sub-card. Disabling requires the operator to re-prove
control of the second factor:

1. Operator types a current TOTP code or unused recovery code into the
   confirmation input.
2. The handler tries `admin_totp_verify($admin_totp_secret, $code)` first;
   on mismatch, falls back to `admin_recovery_verify_and_consume($db,
   $code)` (which marks the code used).
3. Either path success → `admin_totp_disable($db)` clears
   `$admin_totp_secret` from `config-admin.php` AND `DELETE FROM
   admin_recovery_codes`. Cached enrol session state is also cleared so a
   subsequent re-enrol mints a fresh secret.

| Event | When |
| --- | --- |
| `totp.disable` | Valid second-factor accepted; secret + codes cleared. |
| `totp.disable.fail` | Empty / invalid / rate-limited submission. |

### `admin_state` k/v table

A new table introduced for `last_totp_at` and any future single-row
state values (created idempotently by `admin_recovery_db_init()`):

```sql
CREATE TABLE admin_state (
  key        TEXT PRIMARY KEY,
  value      TEXT NOT NULL,
  updated_at INTEGER NOT NULL
);
```

Helpers `admin_state_get()` / `admin_state_set()` are the only callers.

### Operator notes

- Disable wipes recovery codes. After re-enrolling, click **Mint recovery
  codes** to generate a fresh set — without them, losing your authenticator
  again means no fallback.
- Hand-edits to `$admin_totp_secret` in `config.php` always win over
  whatever the wizard wrote to `config-admin.php` (the v3.0.0 convention).
  Disable cannot reach into `config.php`; if the secret is set there, the
  page surfaces the disable form but the merge helper writes a no-op clear
  to `config-admin.php` and the operator's hand-set value still applies.
- The enrol session secret never touches disk before verification. If the
  operator closes the tab mid-enrol, the cached secret expires with the
  PHP session — no cleanup required.

## Settings page (v3.2.0+, #349)

`/admin/settings.php` surfaces ~23 of the ~35 operator-tunable knobs in
`config.php` across six visible sections plus a collapsed *Advanced (CSP)*
`<details>`. Schema-driven validators reject bad input before any disk write;
saves only modify `config-admin.php` (the wizard tier introduced in v3.0.0).

### What it surfaces

| Section | Knobs |
|---|---|
| Branding | `page_title`, `page_description`, `canonical_url`, `default_tab`, `locale`, `fixed_bg_color`, `show_share_bar`, `frame_ancestors` |
| Forms / Captcha | `form_protection`, `turnstile_*`, `recaptcha_score_threshold` |
| API limits | `api_rate_limit_rpm`, `api_cors_origins` |
| Sessions | `session_enabled`, `session_ttl_days` |
| Admin & audit | `admin_audit_retention_days`, `admin_audit_trust_xff`, `admin_audit_purge_strategy`, `admin_audit_purge_sample_rate` |
| Limits | `split_max_subnets`, `lookup_max_cidrs`, `lookup_max_ips` |
| Advanced (CSP) | `csp_connect_extra`, `csp_script_extra`, `csp_img_extra` |

**Not surfaced**: admin auth (`admin_user` / `admin_pass_hash` /
`admin_totp_secret` — purpose-built pages exist at `/admin/login.php` and
`/admin/totp.php`), filesystem path knobs (operator-only), the legacy
`api_tokens` array (replaced by `/admin/keys.php`).

### Source-of-truth indication

Each row carries a small source badge:

- `default` — neither tier sets the key; the schema default applies.
- `admin` — written by the Settings UI to `config-admin.php`.
- `config` — hand-edited in `config.php`. Always wins over the wizard tier.

When a key is set in BOTH files, the row also carries a red **shadowed**
tag. Saving a shadowed value still writes to `config-admin.php` — the UI
value takes effect the moment the operator removes the hand-edit from
`config.php`.

### Secret handling

Schema entries marked `secret: true` (currently the captcha secret keys)
render as `••••••• (set)` or `(empty)` with an `aria-label` for screen
readers. The existing value is never echoed back into the page, so the
DOM has no source for the original token. The form input is
`<input type="password" autocomplete="new-password">`; an empty submission
means "no change". The audit log row redacts secret values to `(set)` or
`(empty)` in the `meta` JSON.

### Atomic write

Saves are validated per-key against the schema. Any validation failure
blocks the entire section's save and pins inline error messages under the
bad fields. On success, `config-admin.php` is rewritten via the merge
helper introduced in v3.2.0 (#347) — single-line `$key = …;` lines are
replaced in place; new keys are appended. After write, the file is
re-included to confirm round-trip parsability and `opcache_invalidate()`
is called so the next request sees the new values.

A pre-flight writability check disables every Save button (and renders a
banner) when `config-admin.php` (or its parent directory) is not
writable by the web user.

### Audit events

| Event | Trigger |
|---|---|
| `config.update` | One row per actual key change. `meta` carries `key`, `before`, `after`. |
| `config.reset` | When a key is restored to its schema default. |

Both events render with the `badge-private` (amber, mutation) treatment
in the audit-log viewer via the v3.2.0 (#346) action-to-badge mapping.
Secrets are redacted to `(set)` / `(empty)` in the `meta` JSON.

### Operator notes

- The schema in `Subnet-Calculator/includes/functions-admin-settings.php`
  is the authoritative knob list. Adding a new knob requires a schema
  entry; novel validation rules (e.g. CIDR list) require extending
  `settings_validate()`.
- The Settings page never touches `config.php`. Operators who want to
  override the wizard tier should hand-edit `config.php`; the Settings UI
  will then display that key with the red `config` badge plus the
  `shadowed` tag and explain the override.
- Saves are per-section. Changing knobs across multiple sections requires
  one Save click per section — by design, so a validation failure in one
  section can't roll back another.

## Audit log (v3.0.0+, #306)

Every admin auth attempt and every key.mint / key.revoke / key.rate_limit
action writes a structured row to the `admin_audit` table. The log is
viewable at `/admin/audit.php` with a paginated newest-first table and a
prefix filter (`login.`, `key.`, `wizard.` reserved for v3 PR2, `totp.`
reserved for v3 PR2).

Schema:

```sql
CREATE TABLE admin_audit (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  ts        INTEGER NOT NULL,
  actor     TEXT,           -- admin username or NULL on failed auth
  ip        TEXT,           -- client IP from REMOTE_ADDR / X-Forwarded-For
  action    TEXT NOT NULL,  -- 'login.ok', 'login.fail', 'key.mint', 'key.revoke', 'key.rate_limit'
  target_id INTEGER,        -- e.g. api_keys.id for mint/revoke/rate_limit
  meta      TEXT            -- JSON blob with action-specific detail
);
```

Retention is bounded by `$admin_audit_retention_days` (default `90`; set to
`0` to keep everything until manually rotated).

The audit module fails open: a write failure logs to `error_log` but never
masks the underlying admin action.

### Viewer polish (v3.2.0+, #346)

The `/admin/audit.php` viewer was refreshed in v3.2.0. Schema, retention,
and purge behaviour are unchanged — only the rendered page differs:

- **Filter strip** is a real `<div role="tablist">` with `<button role="tab"
  aria-selected="true|false" aria-controls="audit-rows">` per filter
  (`all`, `login`, `key`, `wizard`, `totp`). Behaviour is identical to the
  v3.0.0 link-based strip; screen readers now announce the active filter.
- **Action cells** render as colour-coded badges via the shared helper
  `audit_action_badge_class($action)` in `functions-audit.php`. Suffix
  matching wins over prefix matching, so `auth.login.fail` is red, not blue:

  | Action pattern | Badge | Colour |
  |---|---|---|
  | `*.ok`, `*.success` | `badge-public` | green |
  | `*.fail`, `*.deny`, `*.error` | `badge-multicast` | red |
  | `*.create`, `*.delete`, `*.write`, `*.update`, `*.revoke` | `badge-private` | amber |
  | `wizard.*`, `totp.*`, `config.*`, `auth.*` | `badge-doc` | blue |
  | _(default)_ | `badge-other` | slate |

- **Sticky `<thead>`** keeps column headers visible while scrolling long logs.
- **Timestamps** are wrapped in `<time datetime="2026-05-05T01:44:32Z">…</time>`
  so screen readers and locale-aware browsers can reformat the value.
- **Pagination** disabled side is a non-focusable `<span aria-disabled="true">`
  with `aria-label="Previous page (unavailable)"` rather than a faded
  `<a tabindex="-1">` — keyboard users can no longer focus a "next" link
  that does nothing.
- **`<caption class="sr-only">`** describes the table to screen readers.

The mapping helper is reusable from any future page that renders audit
rows — keep new audit consumers consistent by calling
`audit_action_badge_class()` rather than re-implementing the suffix table.

### Purge strategy (v3.1.0+, #325)

Two settings control **when** old rows are deleted:

- `$admin_audit_purge_strategy` — one of `'inline'`, `'sampled'` (default),
  or `'cron'`.
- `$admin_audit_purge_sample_rate` — float in `[0.0, 1.0]`; only consulted
  when the strategy is `'sampled'`. Default `0.001` (≈1 write in 1000
  triggers a purge).

| Strategy | When the purge runs | When to use it |
|---|---|---|
| `inline` | Every call to `audit_log()`. | Low audit volume (< a few writes/min); the v3.0.0 default. |
| `sampled` (default) | Probabilistic: each write rolls a die against `$admin_audit_purge_sample_rate`. | The general-purpose default. At the 0.001 default, busy bursts (mass key rotation, login.fail floods) pay the `DELETE` cost roughly once per thousand writes instead of every write. |
| `cron` | Never inline. The operator runs the CLI script on a schedule. | High-volume deployments where the operator wants deterministic purge timing and zero per-write overhead. |

The CLI script `bin/sc-audit-purge.php` is shipped with the release tarball.
It is **not web-served** (the directory's `.htaccess` denies all HTTP
access; the CLI also refuses to run outside the `cli` SAPI). Run it from
cron — adjust the path to your install:

```cron
# Daily at 03:00, log to a file so a non-zero exit triggers cron mail.
0 3 * * * php /opt/subnet-calculator/bin/sc-audit-purge.php >> /var/log/sc-audit-purge.log 2>&1
```

Output is one line of the form
`sc-audit-purge: <before> → <after> rows (-<deleted>) [db=…, retention=… days]`.
The script exits `0` on success and non-zero on any failure so the cron
daemon mails you on errors.

Switching strategies requires no schema or data migration — change the
config value and the next request honours it. `cron` mode does **not**
disable the table or the in-app audit viewer; only the inline purge.

## Test-only drain endpoint (v3.1.0+, #324)

The `admin/_test-drain.php` endpoint exists **only** in the docker test rig.
It zeroes `api_keys`, `admin_audit`, and `admin_recovery_codes` and clears
sc_admin PHP-session files so the Playwright suite can run repeatedly
against a non-fresh `webapp` container without leftover rows tripping
later assertions.

The endpoint is hard-gated by the `PHPUNIT_TEST_DRAIN_TOKEN` environment
variable:

- `getenv('PHPUNIT_TEST_DRAIN_TOKEN')` empty / unset → endpoint returns
  `404` and reveals nothing. This is the production case.
- Token present, request `token` mismatched → `403`.
- Token matched (timing-safe `hash_equals()`) → tables truncated, response
  `{"ok":true,"drained":[…]}`.

The token is generated per-run by `make test-docker` and passed to both
the `webapp` and `playwright-tests` containers via docker-compose env. It
is never baked into the image and never committed to source.

The release-tarball build step (see `CLAUDE.md` in the [repository root](https://github.com/seanmousseau/Subnet-Calculator/blob/main/CLAUDE.md)) excludes
`admin/_test-drain.php` so the file never ships to operators. If you copy
the file to a production host by accident, it stays inert because the
production environment does not set `PHPUNIT_TEST_DRAIN_TOKEN`.

**Threat model note:** anyone with Docker socket access on the test host
can read `PHPUNIT_TEST_DRAIN_TOKEN` from `docker inspect <container>`.
This is acceptable because the token is regenerated per `make test-docker`
invocation and grants no production capability — the production webapp
never has the env var set, so even an exfiltrated token is useless against
the live deployment.

## Out of scope

The following are intentionally not in v3.0.0 and are deferred to v3.1.0:

- TOTP recovery codes beyond the 10 generated at TOTP enable (e.g. download
  as printable PDF, automatic regeneration warnings).
- Per-actor TOTP secrets (the v3 implementation is single-secret because
  there is currently a single `$admin_user`).
- Webhook / syslog integration for the audit log.
- Multi-admin user support (separate row per admin in a future
  `admin_users` table).
