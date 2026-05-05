# Admin login

Starting in **v3.2.0**, the web admin (`/admin/*.php`) authenticates through a
server-rendered HTML login form instead of HTTP Basic Auth. The change exists
purely to fix one user-facing problem: browser password managers
(1Password, Bitwarden, Chrome built-in, Safari Keychain) cannot save or
autofill credentials on the native Basic Auth dialog. The form gives them
two ordinary `<input>` fields they can introspect.

## Sign-in flow

1. Visit any protected page (`/admin/keys.php`, `/admin/audit.php`,
   `/admin/totp.php`). Without a valid `sc_admin_sid` cookie you receive
   a `303` redirect to `/admin/login.php?next=<requested-path>`.
2. The form posts back to itself with **Username**, **Password**, and a
   hidden **CSRF** token bound to a placeholder session row that was
   minted on the GET.
3. On success the row is upgraded to a real session and the `sc_admin_sid`
   cookie is set with `HttpOnly; Secure; SameSite=Lax`. You are redirected
   to the original `?next=` target (whitelisted to `/admin/*`).
4. If `$admin_totp_secret` is configured the new session row carries
   `totp_pending = 1`. Every subsequent web admin request redirects to
   `/admin/totp-verify.php?next=<path>` until you complete the second
   factor, at which point `admin_session_promote()` clears the flag and
   rotates the CSRF token.

## Cookie semantics

| Attribute | Value | Why |
| -- | -- | -- |
| Name | `sc_admin_sid` | Avoids colliding with PHP's default `PHPSESSID`. |
| Lifetime | session cookie (no `Max-Age`) | Server-side sliding 8h TTL backs the actual freshness check. |
| Path | `/` | The cookie is sent to the API endpoint too, but `/api/v1/admin/*` ignores it (see boundary below). |
| Secure | yes (when the request is HTTPS or `X-Forwarded-Proto: https`) | Prevents leaking the session id over plaintext. |
| HttpOnly | yes | JS cannot read the session id. |
| SameSite | `Lax` | Form submissions from a same-site context still send the cookie; cross-site GETs (the worst case) do not. |

## Rate limiting

Failed login attempts are counted per `(ip, user)` pair in the
`auth_rate_limit` SQLite table. The first three failures are free; the
fourth and beyond escalate the wait time:

| Attempt # past threshold | Wait |
| -- | -- |
| 4 | 1 second |
| 5 | 5 seconds |
| 6 | 30 seconds |
| 7 | 5 minutes |
| 8 | 30 minutes |
| 9+ | 60 minutes |

A successful login deletes the row, clearing the counter. The lockout
banner appears in the same `<form>` slot as the error message, with the
submit button disabled while you are still inside the wait window.

## CSRF protection

The login form uses the **synchroniser-token pattern**:

- `GET /admin/login.php` mints a placeholder row in `admin_sessions`
  (`user = ''`, `totp_pending = 1`) and embeds the row's `csrf` value in
  a hidden form field.
- `POST /admin/login.php` looks up the row via the `sc_admin_sid` cookie,
  runs `hash_equals` on the submitted CSRF token, and only then proceeds
  to the password check.
- A successful POST destroys the placeholder row and replaces it with a
  fresh row scoped to the verified user.

CSRF tokens for *post-login* admin pages (key creation, key revocation,
TOTP enrollment, settings save) are bound to the live session row. Each
page passes the token through `_admin_layout.php`'s `$admin_csrf_token`
slot. `admin_session_promote()` rotates the token when TOTP succeeds, so
a token captured during the pending window cannot survive past the
privilege boundary.

## API boundary — `/api/v1/admin/*` keeps Basic Auth

The cookie-session flow ONLY applies to the **web admin pages** under
`/admin/*.php`. The JSON admin API at `/api/v1/admin/*`
(`api/v1/handlers/admin_keys.php`) continues to authenticate via HTTP
Basic Auth. This is by design — API clients (CLIs, CI scripts, the
official PHP client at `clients/php/SubnetCalculatorClient.php`) cannot
drive an HTML form. Both paths share the same `$admin_user` and
`$admin_pass_hash`; the only difference is how the credentials are
submitted.

The boundary lives in two places:

- `Subnet-Calculator/api/v1/handlers/admin_keys.php` calls
  `admin_authenticate()` directly with a JSON-envelope failure callback.
- `Subnet-Calculator/includes/functions-admin-auth.php` retains the
  `admin_authenticate()` helper unchanged for the API; the new
  `admin_session_require()` helper is what every web admin page calls.

## TOTP integration

`/admin/totp-verify.php` is reachable while the session row has
`totp_pending = 1`. On successful verification:

- `admin_session_promote()` flips the flag to 0 and rotates the CSRF.
- `admin_auth_rate_limit_clear()` drops any pending lockout for the user.
- An `auth.totp.success` audit row is written.
- The user is redirected to the `?next=` target (default `/admin/`).

Recovery codes substitute for the TOTP code on the same page; both paths
go through `admin_totp_consume_attempt()`.

## Audit-log entries

| Event | When | Actor field |
| -- | -- | -- |
| `auth.login.success` | Password verified, session minted. | `$admin_user` |
| `auth.login.fail` | Bad password / CSRF / rate-limited. | submitted username (or `null` if blank) |
| `auth.totp.success` | TOTP or recovery code accepted. | session user |
| `auth.totp.fail` | Bad TOTP / CSRF / rate-limited. | session user |

The `meta` column carries a JSON object with the `reason` (one of
`password`, `csrf`, `rate_limit`) and any other context the call site
chose to record (e.g. `via: 'header'` for API callers).

## Operator setup

No new configuration knobs ship in v3.2.0 — the same `$admin_user` and
`$admin_pass_hash` keys you already set in `config.php` (or
`config-admin.php` if you completed the first-run wizard) drive the new
form. Generate the bcrypt hash the same way as before:

```bash
php -r "echo password_hash('mysecret', PASSWORD_BCRYPT) . PHP_EOL;"
```

Paste the resulting hash into `$admin_pass_hash`. Existing TOTP secrets
(`$admin_totp_secret`) and recovery codes are reused without
modification.
