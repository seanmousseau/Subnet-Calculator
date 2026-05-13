# Data Dictionary

**Audience:** Developer / future-agent making schema changes or reasoning about persisted state.
**Premise:** SQLite is the only persistence layer. Every table is enumerated here. Schema changes must update this doc in the same PR.

**Last updated:** 2026-05-13 (app v3.7.0)

---

## Physical layout

All databases live under `Subnet-Calculator/data/` (web-blocked via `.htaccess`, git-ignored). The runtime opens them through operator-configurable paths from `includes/config.php`:

| Operator variable | Default | Used by |
|---|---|---|
| `$session_db_path` | `data/sessions.sqlite` (auto) | `sessions` table only |
| `$apikey_db_path` | falls back to `$session_db_path`; else `data/sessions.sqlite` | `api_keys` + all admin tables |

In a default deployment, **one SQLite file** (`data/sessions.sqlite`) holds every table. Operators with stricter separation may configure `$apikey_db_path` to a distinct file (e.g., `data/admin.sqlite`) so the admin surface lives on a separately-backed-up volume; the schema does not change between the two arrangements.

All connections use:
- `$db->enableExceptions(true)` — never silent failures.
- `$db->busyTimeout(1500–3000)` — concurrent-writer retry window.

---

## Tables

### `sessions`

**Defined in:** `includes/functions-session.php`
**Purpose:** Shareable-URL state. Write-once, read-many. Each row is one shareable session created via `POST /api/v1/sessions` or the UI "Share" action.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | TEXT | NOT NULL PRIMARY KEY | 16-hex-char (8 random bytes via `random_bytes()`). Invariant I20 widened this from 8 to 16 in v3.6.4. |
| `payload` | TEXT | NOT NULL | JSON-encoded session payload (calculator inputs + computed result). Schema is application-defined; no foreign keys. |
| `created_at` | INTEGER | NOT NULL | Unix epoch seconds. |
| `expires_at` | INTEGER | NOT NULL | Unix epoch seconds. Honoured by `session_purge()`, called on every `session_create()` for lazy GC. |

**Indexes:**
- `idx_sessions_expires (expires_at)` — supports the lazy-purge sweep.

**Lifecycle:**
- Created by `session_create($db, $payload, $ttl_days)`.
- Read by `session_load($db, $id)` — returns NULL if expired or absent.
- Purged opportunistically by `session_purge($db)` on every new session creation. No external cron required.

**Security notes:**
- ID entropy: 64 bits. Adequate for non-secret shareable URLs. If session contents were ever sensitive, the entropy should be widened again.
- IDOR class threat documented in `security-model.md` T11.

---

### `api_keys`

**Defined in:** `includes/functions-apikeys.php`
**Purpose:** API key lifecycle for `Authorization: Bearer <key>` consumers.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT | Internal row ID. |
| `name` | TEXT | NOT NULL | Operator-assigned label (e.g., "Network team automation"). |
| `prefix` | TEXT | NOT NULL | First few characters of the key, plaintext — for operator identification in the admin UI. Not secret on its own. |
| `token_hash` | TEXT | NOT NULL | Argon2id hash of the full key (`password_hash(..., PASSWORD_ARGON2ID)`). The plaintext key is shown to the operator exactly once at issuance. |
| `created_at` | INTEGER | NOT NULL | Unix epoch seconds. |
| `last_used_at` | INTEGER | NULL | Unix epoch seconds; updated by the auth path on each successful verification. |
| `revoked_at` | INTEGER | NULL | Unix epoch seconds when revoked; NULL = active. Revocation is soft (row retained for audit). |
| `rate_limit_rpm` | INTEGER | NULL | Per-key requests-per-minute override. NULL → inherits global setting. **Added in v3.0.0 (#312) via `apikey_migrate_add_rate_limit()`.** Idempotent migration; safe to re-run. |

**Indexes:**
- `idx_api_keys_prefix (prefix)` — supports the admin UI's "find by prefix" lookup. The auth path itself hashes the candidate key and probes by `token_hash`, not prefix.

**Migrations history:**
- **v3.0.0 (#312)** — added `rate_limit_rpm`. Backfill: NULL on existing rows. Implemented in `apikey_migrate_add_rate_limit()`; runs on every `apikey_db_open()` call.

**Security notes:** see `security-model.md` T12 (key theft) and T7 hardening (per-key rate limits).

---

### `admin_audit`

**Defined in:** `includes/functions-audit.php`
**Purpose:** Append-only log of every admin action. Operational forensics, not non-repudiation (see N5 in `security-model.md`).

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT | Row ID. |
| `ts` | INTEGER | NOT NULL | Unix epoch seconds. |
| `actor` | TEXT | NULL | Admin username; NULL for system actions. Truncated to `AUDIT_ACTOR_MAX`. |
| `ip` | TEXT | NULL | Source IP; NULL when not derivable. |
| `action` | TEXT | NOT NULL | Action verb (e.g., `apikey.create`, `apikey.revoke`, `auth.login`, `auth.fail`). Length-capped at `AUDIT_ACTION_MAX`. |
| `target_id` | INTEGER | NULL | Row ID of the affected entity (e.g., the `api_keys.id` being revoked). NULL when action has no target. |
| `meta` | TEXT | NULL | JSON-encoded action-specific detail. |

**Indexes:**
- `idx_admin_audit_ts (ts)` — chronological scan.
- `idx_admin_audit_action (action)` — filter by action type.

**Write semantics:** `audit_log()` is **best-effort** — a write failure logs to `error_log` but never throws. Rationale (in code): "we never want an audit failure to mask the underlying admin action."

---

### `admin_sessions`

**Defined in:** `includes/functions-admin-session.php`
**Purpose:** Cookie-backed admin authentication sessions (post-TOTP).

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `session_id` | TEXT | PRIMARY KEY | 64-hex-char (32 random bytes). Set as an HttpOnly cookie. |
| `user` | TEXT | NOT NULL | Admin username. |
| `created_at` | INTEGER | NOT NULL | Unix epoch seconds. |
| `last_seen` | INTEGER | NOT NULL | Updated on each authenticated request; supports idle-timeout. |
| `expires_at` | INTEGER | NOT NULL | Hard expiry = `created_at + ADMIN_SESSION_TTL`. |
| `totp_pending` | INTEGER | NOT NULL DEFAULT 0 | `1` between password verify and TOTP verify; `0` after full auth. |
| `csrf` | TEXT | NOT NULL | 64-hex-char per-session CSRF token (32 random bytes). Validated on every admin POST. |
| `ip` | TEXT | NULL | Source IP at session creation. |
| `user_agent` | TEXT | NULL | UA string at session creation. |

**Indexes:**
- `idx_admin_sessions_expires_at (expires_at)` — supports session sweep.

---

### `auth_rate_limit`

**Defined in:** `includes/functions-admin-session.php`
**Purpose:** Admin-login throttling — track failed-auth bursts per `(IP, username)` pair.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `ip` | TEXT | NOT NULL (PK part) | Client IP. |
| `user` | TEXT | NOT NULL (PK part) | Username attempted. |
| `failures` | INTEGER | NOT NULL DEFAULT 0 | Consecutive failure count; reset on successful auth. |
| `last_at` | INTEGER | NOT NULL DEFAULT 0 | Unix epoch seconds of the most recent failure. |

**Primary key:** `(ip, user)` composite.

**Throttle policy:** ≥5 failures within 15 minutes triggers cooldown. See `functions-admin-auth.php` for the current threshold.

---

### `admin_recovery_codes`

**Defined in:** `includes/functions-admin-totp.php`
**Purpose:** One-time-use recovery codes for admin TOTP. Issued at TOTP setup; consumed when used.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT | Row ID. |
| `code_hash` | TEXT | NOT NULL | Argon2id hash of the recovery code. Plaintext shown to operator once at issuance. |
| `created_at` | INTEGER | NOT NULL | Unix epoch seconds. |
| `used_at` | INTEGER | NULL | Unix epoch seconds when consumed; NULL = unused. |
| `used_via_ip` | TEXT | NULL | IP that consumed the code, for forensics. **Added later via `ALTER TABLE` migration** in `functions-admin-totp.php`. Idempotent; checks `PRAGMA table_info` before adding. |

**Indexes:**
- `idx_admin_recovery_used (used_at)` — fast "any unused codes remaining?" query.

---

### `admin_state`

**Defined in:** `includes/functions-admin-totp.php`
**Purpose:** Key/value bag for small admin-scope settings that don't merit their own table (e.g., `totp_secret`, `wizard_complete`, etc.).

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `key` | TEXT | PRIMARY KEY | Setting name. |
| `value` | TEXT | NOT NULL | Setting value (string; callers JSON-encode if they need structure). |
| `updated_at` | INTEGER | NOT NULL | Unix epoch seconds. |

Use sparingly. Anything with more than a handful of related settings or any relational structure deserves its own table.

---

## Migration policy

The app uses **idempotent migrations baked into each `*_db_open()` function**, not a versioned migration tool. Rules:

1. **Every `*_db_open()` runs `CREATE TABLE IF NOT EXISTS`.** New installs land on the current schema.
2. **Adding a column = `PRAGMA table_info` check + `ALTER TABLE ADD COLUMN`** inside a dedicated `*_migrate_*()` function. Safe to re-run on every open.
3. **Never remove a column in place** — keep it nullable, stop writing to it, document deprecation here. Real removal only in a major-version migration (e.g., dropping `dev` SQLite files and asking operators to re-init).
4. **Never rename a column in place** — same reason.
5. **No data backfill in the migration path** unless it's O(N) over a small table. Anything else needs an explicit operator step documented in CHANGELOG.
6. **On migration failure: hard-fail.** Don't continue with a partial schema (see `apikey_migrate_add_rate_limit()` for the pattern). A partial schema crashes later, mysteriously; a thrown exception is deterministic.

---

## When to update this doc

Any PR that:
- Adds, removes, or renames a table.
- Adds, removes, or renames a column.
- Changes a primary key or index.
- Adds a migration function.
- Adds, changes, or removes a database file path / operator config variable.

The PR must:
1. Update the relevant table section here.
2. Add a row to the table's "Migrations history" sub-section (or create the sub-section if none exists).
3. Add a CHANGELOG entry under the release where the migration lands.
4. If the change affects security posture (e.g., new auth-related field), cross-reference `security-model.md` and update its threat/control inventory.
5. Bump the "Last updated" header.

If a PR touches any `CREATE TABLE`, `CREATE INDEX`, `ALTER TABLE`, or `PRAGMA table_info` and does NOT update this doc, the PR is incomplete.

---

## What's intentionally not in this dictionary

- **Application-level JSON shapes** stored inside `sessions.payload` — those are versioned by the app code, not by the DB schema. The DB sees opaque text.
- **In-memory caches.** PHP request-scope arrays don't persist; no schema applies.
- **OPcache / service-worker caches.** Not data, not relational, not in scope.
- **External logs.** OLS error log, PHP error log, etc. are operator-managed text files.

---

## Cross-references

- Threat model for each data store: [`security-model.md`](security-model.md) — see T11 (sessions), T12 (api_keys), T13 (admin).
- Operational runbook for "SQLite locked / corrupt": [`runbooks.md`](runbooks.md) R8.
- Pure-function rules vs. DB-touching code: [`design-document.md`](design-document.md) §4.1 (only `functions-session.php`, `functions-apikeys.php`, `functions-audit.php`, `functions-admin-*.php` are allowed to touch persistence).
