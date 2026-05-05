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

- **Mint a key:** enter a human-readable name and submit. The token is shown
  once on the next page; copy it immediately. After page reload it is gone.
- **List keys:** see name, public prefix (first 8 hex chars), creation time,
  last-used time, and active/revoked status.
- **Revoke a key:** click *Revoke*. Revocation is immediate and permanent.

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
