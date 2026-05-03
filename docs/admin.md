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

## Out of scope

The following are intentionally not in v2.12.0 and will land in v3.0.0:

- A first-run setup wizard for the admin password (currently it is a
  manual `php -r` step so the hash never touches the wire).
- Per-key rate-limit overrides via the UI (still possible via
  `$api_rate_limit_tokens` for static tokens).
- Audit log of admin actions (mint/revoke history beyond `revoked_at`).
- TOTP / 2FA on admin login.
