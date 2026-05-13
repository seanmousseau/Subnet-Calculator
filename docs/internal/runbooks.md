# Production Runbooks

**Audience:** Operator (Sean) + future-agent picking up an incident.
**Scope:** Anything that can go wrong in production at https://subnetcalculator.app/ and how to triage it.
**Update protocol:** Every incident adds (or amends) a runbook here. Capture the smell, the check, the fix. Drop runbooks that haven't applied in a year.

Production targets:
- **Origin:** OpenLiteSpeed at `root@192.168.80.23:/usr/local/lsws/vhosts/subnetcalculator.app/html/`
- **CDN:** Cloudflare in front (zone purge required after deploys)

---

## Quick reference

| Symptom | Jump to |
|---|---|
| Site returns 5xx | [R1](#r1--site-returning-5xx) |
| Site returns old/stale content after deploy | [R2](#r2--stale-content-after-deploy) |
| Service worker serving old shell | [R3](#r3--service-worker-serving-stale-shell) |
| Smoke test fails immediately after `systemctl restart lsws` | [R4](#r4--first-smoke-test-fails-after-lsws-restart) |
| `config.php` got overwritten by a deploy | [R5](#r5--operator-configphp-overwritten) |
| API returns 401/403 unexpectedly | [R6](#r6--api-auth-failure-after-key-rotation) |
| Rate-limit triggering on legitimate traffic | [R7](#r7--rate-limit-false-positive) |
| Session DB locked / corrupt | [R8](#r8--sqlite-session-db-locked-or-corrupt) |
| GMP missing on host | [R9](#r9--gmp-extension-missing) |
| Cloudflare returning stale despite purge | [R10](#r10--cloudflare-stale-after-purge) |
| Need to roll back a release | [R11](#r11--rollback-a-release) |

---

## R1 — Site returning 5xx

1. SSH to origin: `ssh root@192.168.80.23`.
2. Check OLS error log: `tail -n 200 /usr/local/lsws/logs/error.log`.
3. Check PHP error log (path varies by vhost; typically next to OLS logs).
4. Most common cause: PHP fatal from a missing extension (`gmp`, `pdo_sqlite`) — see R9.
5. Second-most-common: file ownership wrong after rsync — `chown -R nobody:nogroup /usr/local/lsws/vhosts/subnetcalculator.app/html/`.
6. Restart lsws after fix: `systemctl restart lsws && sleep 3`.
7. Smoke test from local: `curl -sSI https://subnetcalculator.app/ | head -5`.

If the fix isn't obvious in < 5 min, **roll back (R11)** then triage offline.

---

## R2 — Stale content after deploy

1. Verify the deploy actually wrote files: `ssh root@192.168.80.23 'ls -lt /usr/local/lsws/vhosts/subnetcalculator.app/html/includes/config.php'` — mtime should be recent.
2. Check `$app_version` in the deployed file matches the release: `ssh root@192.168.80.23 'grep app_version /usr/local/lsws/vhosts/subnetcalculator.app/html/includes/config.php'`.
3. If file is fresh but version on the wire is old → OPcache holding stale bytecode. Run `systemctl restart lsws && sleep 3`.
4. If origin shows new version but Cloudflare returns old → see R10.
5. If browser still shows old after CF purge → service worker (R3).

---

## R3 — Service worker serving stale shell

Symptom: user reports "I'm on version X.Y.Z" but the version pill in the footer disagrees with the actual release.

1. Confirm `CACHE_NAME` in `Subnet-Calculator/sw.js` was bumped for this release. If not, ship a patch release that bumps it. (This is invariant I10. v3.2.2 shipped specifically to recover from this drift.)
2. Tell affected users: hard refresh (`Cmd+Shift+R`) or DevTools → Application → Service Workers → Unregister.
3. Long-term: never skip the `CACHE_NAME` bump. The `/release` skill handles it; if doing manually, it's step 2 of the release checklist.

---

## R4 — First smoke test fails after `lsws restart`

Symptom: smoke test immediately after `systemctl restart lsws` returns pre-fix behaviour, but a retry 5 seconds later passes. (This was the v3.6.3 deploy bug.)

**Root cause:** OPcache takes ~1–2 s to settle. The first request after restart can serve stale bytecode.

**Fix:** Always `sleep 3` between `systemctl restart lsws` and the first smoke test. This is invariant I9 and lives in the `/release` skill.

If you hit this in the wild after a deploy, just wait 3 seconds and re-test before assuming a real bug.

---

## R5 — Operator `config.php` overwritten

Symptom: after deploy, operator overrides (custom `$turnstile_*` keys, `$canonical_url`, etc.) are gone.

**Root cause:** unanchored rsync exclude. `--exclude='config.php'` matches both `Subnet-Calculator/config.php` (operator override — must preserve) AND `Subnet-Calculator/includes/config.php` (defaults — must ship). When unanchored, both get excluded; tarball install left a broken includes.

**Recovery:**
1. Restore operator `config.php` from the latest backup (`/opt/backups/...` per host policy).
2. If no backup: copy `config.php.example` to `config.php` and re-enter operator settings from password manager.
3. Verify `includes/config.php` shipped correctly: `grep app_version /usr/local/lsws/vhosts/subnetcalculator.app/html/includes/config.php`.

**Prevention:** anchored excludes only — `--exclude='/config.php' --exclude='/data/'`. This is invariant I8.

---

## R6 — API auth failure after key rotation

Symptom: API client reports 401 after a key was rotated in the admin console.

1. Verify the key's status in admin: `https://subnetcalculator.app/admin/keys.php` (TOTP-gated).
2. If the key was revoked: confirm with the operator that the revocation was intentional. Issue a new key if not.
3. If the key is active but auth still fails: check `Authorization: Bearer <key>` header is exact (no whitespace, no quotes).
4. Check `data/audit.sqlite` for `auth.fail` events: `sqlite3 data/audit.sqlite 'SELECT * FROM audit ORDER BY ts DESC LIMIT 20'`.

---

## R7 — Rate-limit false positive

Symptom: legitimate user/automation hitting rate-limit (`429 Too Many Requests`).

1. Check the rate-limit config in admin (`settings.php`) — confirm per-key limit matches intended use.
2. Anonymous rate-limits are tighter than keyed; suggest the user provision an API key.
3. If the limit is wrong for everyone, adjust in admin and document the change in `CHANGELOG.md` under the next release.
4. **Do not disable rate-limiting** — it's a DoS control (invariant I19's neighbour).

---

## R8 — SQLite session DB locked or corrupt

Symptom: shareable-URL feature returns 500; error log shows `database is locked` or `database disk image is malformed`.

1. SSH to origin.
2. Check disk space: `df -h /usr/local/lsws/vhosts/subnetcalculator.app/html/data/`. SQLite locks if disk is full.
3. Check file perms: `ls -la data/*.sqlite` — must be writable by `nobody`.
4. If locked but no other process: stop lsws, remove `*.sqlite-journal` / `*.sqlite-wal` files, restart.
5. If corrupt: sessions are non-critical user data (shareable URLs only). Move the corrupt DB aside, let the app re-create on next request. Notify users that old shareable links are gone.
6. **Admin and API-key DBs are critical** — restore from backup, do not wipe.

---

## R9 — GMP extension missing

Symptom: PHP fatal on any IPv6 calculation: `Call to undefined function gmp_init()`.

1. `ssh root@192.168.80.23 'php -m | grep -i gmp'` — empty means missing.
2. Install: `apt-get install -y php8.x-gmp` (match host PHP version) or distro equivalent.
3. Restart lsws: `systemctl restart lsws && sleep 3`.
4. Smoke test: `curl -s 'https://subnetcalculator.app/api/v1/ipv6' -d 'address=2001:db8::1&prefix=64'`.

GMP is a hard dependency (invariant I2). The dev-server session-start hook auto-installs it; production is operator-managed.

---

## R10 — Cloudflare stale after purge

Symptom: ran zone purge but old content still served.

1. Confirm purge happened: Cloudflare dashboard → Caching → Configuration → Purge log.
2. Check from a different network (mobile hotspot) — local DNS / ISP cache could be at play.
3. Cloudflare edge propagation takes up to 30 s in practice. Wait, retest.
4. If still stale after 60 s: hit the origin directly to confirm it's fresh: `curl -sI -H 'Host: subnetcalculator.app' https://192.168.80.23/`. If origin is fresh and CF is stale, open a CF support ticket.
5. Last resort: bump the CSS/JS asset filenames (forces re-fetch). Don't habit-form this — fix CF properly.

---

## R11 — Rollback a release

Pre-conditions: previous release tarball exists in `releases/`.

1. Identify the last known-good release: `ls -t releases/ | head -5`.
2. SSH to origin: `ssh root@192.168.80.23`.
3. Extract the previous tarball over the docroot (anchored excludes preserve operator config):
   ```bash
   cd /usr/local/lsws/vhosts/subnetcalculator.app/html/
   tar -xzf /path/to/subnet-calculator-X.Y.Z.tar.gz \
       --exclude='./config.php' --exclude='./data/'
   chown -R nobody:nogroup .
   ```
4. `systemctl restart lsws && sleep 3`.
5. Smoke test (curl version pill).
6. Purge Cloudflare.
7. **File a regression issue** describing what shipped wrong and link to the post-mortem.
8. Optionally: tag a `vX.Y.Z+rollback` annotation on the git tag for visibility.

Do not delete the broken release tarball — keep it for forensics.

---

## Post-incident checklist

After resolving any incident:

1. Capture the timeline + root cause in a one-paragraph Memory MCP observation under the relevant release entity.
2. If a new failure mode emerged, add a runbook section here.
3. If a new invariant emerged, add a row to `design-document.md` §5.
4. If the fix is a code change, follow the normal release process (don't hot-patch production).
5. If the fix is a process change (e.g., the `sleep 3` rule), update both the `/release` skill and this runbook.
