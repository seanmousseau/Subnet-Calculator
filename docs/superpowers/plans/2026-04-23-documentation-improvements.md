# Documentation Improvements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all factual errors, stale content, and missing documentation across README, CHANGELOG, CONTRIBUTING, SECURITY, docs/ MkDocs site, and openapi.yaml so every doc is accurate, internally consistent, and reflects the current v2.8.1 state of the app.

**Architecture:** Documentation-only changes — no PHP, JS, or CSS is touched. Each task targets one logical doc unit and ends with a commit. Tasks 1–4 fix factual errors that would break user code or mislead integrators; later tasks fill feature gaps and update stale UX descriptions.

**Tech Stack:** Markdown (docs/), YAML (openapi.yaml), Bash (grep verification). No build step required — MkDocs site is deployed by GitHub Actions on push to `main`.

---

## Files touched

| File | Change summary |
|---|---|
| `docs/sharing.md` | Fix `sc-height` → `sc-resize`; add `sc-set-bg` section; add `$show_share_bar` note |
| `docs/ipv4.md` | Fix `$split_max_subnets` default 256 → 16 |
| `docs/rdns.md` | Fix TTL example 3600 → 86400; add IPv6 `/112` minimum; add `ns`/`hostmaster`/`serial`/`domain` params |
| `docs/binary.md` | Fix hex format `c0a80100` → `C0.A8.01.00` |
| `docs/api.md` | Fix bulk limit 20 → 50; add `POST /api/v1/sessions` section; add error response note |
| `docs/overlap.md` | Update UI location to Tool Drawer; add API cross-reference |
| `docs/supernet.md` | Update UI location to Tool Drawer; add API cross-reference |
| `docs/ula.md` | Update UI location to Tool Drawer; add `available_64s` mention; add API cross-reference |
| `docs/sessions.md` | Update UI location to Tool Drawer; add `POST /api/v1/sessions` mention |
| `docs/index.md` | Fix tarball version 2.6.0 → 2.8.1; add `intl` to requirements; add PHP client mention |
| `docs/client.md` | **Create** — PHP client library documentation page |
| `docs/mkdocs.yml` | Add `PHP Client Library` nav entry pointing to `client.md` |
| `Subnet-Calculator/api/openapi.yaml` | Fix license MIT → AGPL-3.0; fix version "1" → "1.0"; update meta example to include `sessions`/`changelog` |
| `SECURITY.md` | Update supported versions table to current v2.8.x |
| `README.md` | Fix tarball extract example 2.7.0 → 2.8.1; add missing API endpoints to table; add `$api_request_log`/`$api_request_log_db_path` to config table; add Docker deployment; add PHP client library; add Service Worker/offline note; add v2.8.0 Tool Drawer mention |
| `CONTRIBUTING.md` | Add `make test-docker` as canonical test gate; add visual regression workflow; fix Playwright count (85 groups/517 → 91 groups/561); add `clients/php/` to project structure; add Semgrep and Spectral to tooling |
| `CHANGELOG.md` | Add missing `## [0.7]` entry between v0.6 and v0.8 |

---

## Task 1 — Fix `sc-height` → `sc-resize` in sharing.md (critical functional error)

A developer copying the iframe auto-sizing snippet from `docs/sharing.md` will get code that never fires because the app emits `sc-resize` not `sc-height`. This is confirmed in `Subnet-Calculator/assets/app.js` line 415.

**Files:**
- Modify: `docs/sharing.md:38`

- [ ] **Step 1: Verify the wrong value is present**

```bash
grep -n "sc-height\|sc-resize" docs/sharing.md
```

Expected output:
```
38:  if (e.data && e.data.type === 'sc-height') {
```

- [ ] **Step 2: Apply the fix**

In `docs/sharing.md`, replace line 38:

Old:
```
  if (e.data && e.data.type === 'sc-height') {
```

New:
```
  if (e.data && e.data.type === 'sc-resize') {
```

- [ ] **Step 3: Verify the fix**

```bash
grep -n "sc-height\|sc-resize" docs/sharing.md
```

Expected output:
```
38:  if (e.data && e.data.type === 'sc-resize') {
```

No `sc-height` references should remain.

- [ ] **Step 4: Commit**

```bash
git add docs/sharing.md
git commit -m "docs: fix postMessage event type sc-height → sc-resize in sharing.md"
```

---

## Task 2 — Fix factual errors in ipv4.md, rdns.md, binary.md

Three single-line factual errors across three docs pages. Grouped into one task because they are quick, independent, and a single commit is appropriate.

**Files:**
- Modify: `docs/ipv4.md:39`
- Modify: `docs/rdns.md` (TTL example)
- Modify: `docs/binary.md:9`

- [ ] **Step 1: Verify all three wrong values**

```bash
grep -n "split_max_subnets\|256" docs/ipv4.md
grep -n "3600" docs/rdns.md
grep -n "c0a80100\|hex string" docs/binary.md
```

Expected:
```
39:The maximum number of subnets returned is set by `$split_max_subnets` in `config.php` (default 256).
...
39:    "ttl": 3600,
...
9:- **Hex** — network address as a 0-padded 8-character hex string (e.g. `c0a80100` for `192.168.1.0`).
```

- [ ] **Step 2: Fix ipv4.md — split_max_subnets default**

In `docs/ipv4.md` line 39, replace:
```
The maximum number of subnets returned is set by `$split_max_subnets` in `config.php` (default 256).
```
With:
```
The maximum number of subnets returned is set by `$split_max_subnets` in `config.php` (default 16).
```

- [ ] **Step 3: Fix rdns.md — TTL in example response**

In `docs/rdns.md`, find the JSON example block containing `"ttl": 3600` and replace `3600` with `86400`.

The block looks like:
```json
    "ttl": 3600,
```
Change to:
```json
    "ttl": 86400,
```

- [ ] **Step 4: Fix binary.md — IPv4 hex notation**

In `docs/binary.md` line 9, replace:
```
- **Hex** — network address as a 0-padded 8-character hex string (e.g. `c0a80100` for `192.168.1.0`).
```
With:
```
- **Hex** — network address in dotted-hex notation (e.g. `C0.A8.01.00` for `192.168.1.0`).
```

- [ ] **Step 5: Verify all three fixes**

```bash
grep -n "split_max_subnets" docs/ipv4.md
grep -n "ttl" docs/rdns.md
grep -n "hex\|Hex" docs/binary.md | head -5
```

- [ ] **Step 6: Commit**

```bash
git add docs/ipv4.md docs/rdns.md docs/binary.md
git commit -m "docs: fix split_max_subnets default, rdns TTL, and binary hex format"
```

---

## Task 3 — Fix docs/api.md: bulk limit and missing POST /sessions

`docs/api.md` says bulk accepts "up to 20" requests but the openapi.yaml spec and README both say 50. The `POST /api/v1/sessions` endpoint is fully documented in openapi.yaml but absent from the API docs.

**Files:**
- Modify: `docs/api.md:162` (bulk limit)
- Modify: `docs/api.md:169` (insert POST /sessions section before GET /sessions)

- [ ] **Step 1: Verify the bulk limit error**

```bash
grep -n "up to 20\|up to 50\|bulk" docs/api.md
```

Expected: line 162 shows `up to 20`.

- [ ] **Step 2: Fix bulk limit**

In `docs/api.md` line 162, replace:
```
Run multiple operations in a single request (up to 20).
```
With:
```
Run multiple operations in a single request (up to 50).
```

- [ ] **Step 3: Verify GET /sessions is there and POST is missing**

```bash
grep -n "sessions\|POST.*session\|GET.*session" docs/api.md
```

Expected: only `GET /api/v1/sessions/:id` appears; no POST.

- [ ] **Step 4: Add POST /sessions section**

Find the line `### GET /api/v1/sessions/:id` in `docs/api.md` and insert a new `### POST /api/v1/sessions` section immediately before it:

```markdown
### POST /api/v1/sessions

Save a VLSM session payload for later retrieval. Returns an 8-hex-char session ID. Only available when `$session_enabled = true` in `config.php`.

```bash
curl -X POST https://example.com/subnet-calculator/api/v1/sessions \
  -H 'Content-Type: application/json' \
  -d '{"payload":{"network":"10.0.0.0","cidr":"24","requirements":[{"name":"LAN","hosts":50}]}}'
```

Response (`201 Created`):

```json
{
  "ok": true,
  "data": { "id": "a1b2c3d4" }
}
```

The session ID can then be used with `GET /api/v1/sessions/:id` to restore the session. Sessions expire after the server-configured TTL (default 30 days).

```

- [ ] **Step 5: Verify both changes**

```bash
grep -n "up to 50\|POST.*sessions\|201" docs/api.md
```

- [ ] **Step 6: Commit**

```bash
git add docs/api.md
git commit -m "docs: fix bulk limit (20→50), add POST /api/v1/sessions endpoint"
```

---

## Task 4 — Fix openapi.yaml: license, version, meta example

The spec declares `MIT` license but the project is AGPL-3.0 (see `LICENSE`). The API version field is bare `"1"`. The meta endpoint example omits `sessions` and `changelog` from the endpoint list.

**Files:**
- Modify: `Subnet-Calculator/api/openapi.yaml:9-11` (license)
- Modify: `Subnet-Calculator/api/openapi.yaml:3` (version)
- Modify: `Subnet-Calculator/api/openapi.yaml:190-200` (meta example endpoints)

- [ ] **Step 1: Verify the errors**

```bash
grep -n "license\|MIT\|AGPL\|version" Subnet-Calculator/api/openapi.yaml | head -10
sed -n '186,202p' Subnet-Calculator/api/openapi.yaml
```

Expected: `name: MIT`, `identifier: MIT`, `version: "1"`, and the endpoints list missing `sessions` and `changelog`.

- [ ] **Step 2: Fix license**

Replace:
```yaml
  license:
    name: MIT
    identifier: MIT
```
With:
```yaml
  license:
    name: AGPL-3.0
    identifier: AGPL-3.0-only
    url: https://www.gnu.org/licenses/agpl-3.0.html
```

- [ ] **Step 3: Fix API version**

Replace:
```yaml
  version: "1"
```
With:
```yaml
  version: "1.0"
```

- [ ] **Step 4: Update meta example to include sessions and changelog**

Find the `endpoints:` list in the meta example (around line 190) and add `sessions` and `changelog` to the array. The full updated example block should read:

```yaml
              example:
                ok: true
                data:
                  version: "1"
                  endpoints:
                    - ipv4
                    - ipv6
                    - vlsm
                    - overlap
                    - split/ipv4
                    - split/ipv6
                    - supernet
                    - ula
                    - rdns
                    - bulk
                    - sessions
                    - changelog
```

- [ ] **Step 5: Verify all three fixes**

```bash
grep -n "license\|AGPL\|MIT\|version" Subnet-Calculator/api/openapi.yaml | head -10
grep -n "sessions\|changelog" Subnet-Calculator/api/openapi.yaml | head -5
```

- [ ] **Step 6: Commit**

```bash
git add Subnet-Calculator/api/openapi.yaml
git commit -m "docs: fix openapi.yaml license (MIT→AGPL-3.0), version, and meta example endpoints"
```

---

## Task 5 — Update SECURITY.md supported versions

The table shows only `0.1.x ✅` — the project is at v2.8.1. Any security researcher reading this concludes the project has no current supported version.

**Files:**
- Modify: `SECURITY.md:5-7`

- [ ] **Step 1: Verify the stale table**

```bash
grep -n "0.1\|Supported\|Version" SECURITY.md
```

Expected: only `0.1.x | ✅` is present.

- [ ] **Step 2: Replace the table**

Replace the entire Supported Versions table:
```markdown
| Version | Supported |
|---------|-----------|
| 0.1.x   | ✅        |
```
With:
```markdown
| Version | Supported |
|---------|-----------|
| 2.8.x   | ✅        |
| < 2.8   | ❌        |

Only the latest stable release receives security fixes.
```

- [ ] **Step 3: Verify**

```bash
grep -n "2.8\|0.1\|Supported" SECURITY.md
```

- [ ] **Step 4: Commit**

```bash
git add SECURITY.md
git commit -m "docs: update SECURITY.md supported versions to v2.8.x"
```

---

## Task 6 — README.md: fix stale refs, API table, config table

Three focused fixes: the tarball extract example still references 2.7.0; several API endpoints are absent from the endpoint table; `$api_request_log` and `$api_request_log_db_path` are missing from the configuration table.

**Files:**
- Modify: `README.md:176` (tarball extract version)
- Modify: `README.md` (API endpoint table)
- Modify: `README.md` (configuration table)

- [ ] **Step 1: Verify stale tarball version**

```bash
grep -n "tar.*2\.\|extract\|xzf" README.md
```

Expected: line 176 shows `subnet-calculator-2.7.0.tar.gz`.

- [ ] **Step 2: Fix extract example**

On README.md line 176, replace:
```
tar -xzf subnet-calculator-2.7.0.tar.gz -C /var/www/html/subnet-calculator/
```
With:
```
tar -xzf subnet-calculator-2.8.1.tar.gz -C /var/www/html/subnet-calculator/
```

- [ ] **Step 3: Verify missing API endpoints**

```bash
grep -n "api/v1\|GET.*api\|POST.*api" README.md | grep -v "^#\|config"
```

Look for presence of `/meta`, `/changelog`, `/bulk` in the API endpoint table section.

- [ ] **Step 4: Add missing API endpoints to the table**

Find the REST API endpoint table in README.md. Add the following rows (insert in a logical position, e.g. after the existing GET row and before or after the POST rows):

```markdown
| `GET /api/v1/` | Returns API version and available endpoint list |
| `GET /api/v1/changelog` | Returns the app CHANGELOG as plain text |
| `POST /api/v1/bulk` | Run up to 50 operations in a single request |
| `POST /api/v1/sessions` | Save a VLSM session; returns an 8-char session ID |
| `GET /api/v1/sessions/:id` | Retrieve a saved VLSM session by ID |
```

If `GET /api/v1/` is already present, skip it. If `POST /api/v1/sessions` or `GET /api/v1/sessions/:id` are already present, skip them. Only add truly missing rows.

- [ ] **Step 5: Add missing config variables to the table**

Find the configuration table in README.md. Add the following rows after the existing rate-limit entries:

```markdown
| `$api_request_log` | `false` | Log API requests to SQLite for analytics/abuse review. |
| `$api_request_log_db_path` | `'data/api_requests.db'` | Path to the API request log SQLite database (relative to docroot). |
```

- [ ] **Step 6: Verify changes**

```bash
grep -n "changelog\|bulk\|api_request_log" README.md | head -10
grep -n "2.8.1" README.md | head -5
```

- [ ] **Step 7: Commit**

```bash
git add README.md
git commit -m "docs: fix README tarball version, add missing API endpoints and config vars"
```

---

## Task 7 — README.md: add missing feature documentation

Four features added in v2.7.0–v2.8.0 have no README coverage: Docker deployment, PHP client library, Service Worker/offline mode, and the v2.8.0 Tool Drawer.

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Check what is currently in the README features section**

```bash
grep -n "Docker\|Service Worker\|offline\|Tool Drawer\|client" README.md | head -20
```

- [ ] **Step 2: Add Docker deployment section**

Find the section that describes how to run the app (the `php -S` section) and add a Docker alternative immediately after it:

```markdown
### Docker

A `docker-compose.yml` is included. Build and start with:

```bash
docker compose up --build
```

The app is served on port `8080` by default. Mount a `config.php` file into `/var/www/html/` to override configuration.
```

- [ ] **Step 3: Add Service Worker / offline note**

Find the Features list (the bullet list near the top of the README). Add the following item:

```markdown
- **Offline mode** — a Service Worker caches the app so it works without a network connection after the first visit
```

- [ ] **Step 4: Add v2.8.0 Tool Drawer note**

In the Features list or a relevant UI/UX section, add:

```markdown
- **Tool Drawer** — a slide-in toolbar panel (v2.8.0+) groups sub-tools (subnet splitter, supernet/summarisation, IP range → CIDR, subnet allocation tree, overlap checker, ULA generator) so they don't clutter the main interface
```

- [ ] **Step 5: Add PHP client library section**

Find the REST API section and add a paragraph or subsection for the client:

```markdown
### PHP Client Library

A lightweight PHP wrapper is included at `clients/php/SubnetCalculatorClient.php`. Copy it into your project and use it to call any API endpoint:

```php
require 'SubnetCalculatorClient.php';
$client = new SubnetCalculatorClient('https://example.com/subnet-calculator/');
$result = $client->ipv4('192.168.1.0/24');
echo $result['data']['network_cidr'];
```

No Composer dependency required.
```

- [ ] **Step 6: Verify**

```bash
grep -n "Docker\|Service Worker\|offline\|Tool Drawer\|PHP Client\|SubnetCalculatorClient" README.md
```

- [ ] **Step 7: Commit**

```bash
git add README.md
git commit -m "docs: add Docker, Service Worker, Tool Drawer, and PHP client to README"
```

---

## Task 8 — Update CONTRIBUTING.md

Missing: `make test-docker` as the canonical test gate, visual regression workflow, updated Playwright counts, `clients/php/` in project structure, Semgrep and Spectral in tooling.

**Files:**
- Modify: `CONTRIBUTING.md`

- [ ] **Step 1: Check current state**

```bash
grep -n "docker\|make\|517\|85 group\|semgrep\|spectral\|clients" CONTRIBUTING.md
```

Expected: no Docker, no Semgrep, no Spectral, count shows 517/85.

- [ ] **Step 2: Add make test-docker as the canonical test gate**

Find the section describing how to run tests (likely near the `playwright_test.py` entry). Add this block before or in place of the manual Playwright instructions:

```markdown
### End-to-end tests (preferred)

```bash
make test-docker
```

This builds a Docker image, starts the app, runs the full Playwright suite (561 assertions across 91 test groups), and prints a pass/fail summary. No dev server setup required. `SKIP_SNAPSHOTS=1` and `SKIP_LINT=1` are set automatically — run `npm run lint` separately for ESLint/Stylelint.

**Visual regression snapshots:** If your PR changes CSS, update baselines:

```bash
UPDATE_SNAPSHOTS=1 make test-docker
```

Then commit the updated PNGs in `testing/snapshots/`.
```

- [ ] **Step 3: Fix Playwright test count**

Find the line:
```
playwright_test.py     ← Playwright E2E browser tests (85 groups, 517 assertions)
```

Replace with:
```
playwright_test.py     ← Playwright E2E browser tests (91 groups, 561 assertions)
```

- [ ] **Step 4: Add clients/php/ to project structure**

Find the project structure tree. Add the `clients/` directory:

```
clients/
  php/
    SubnetCalculatorClient.php  ← PHP API client wrapper (no Composer required)
```

- [ ] **Step 5: Add Semgrep and Spectral to tooling**

Find the tooling/linting section and add:

```markdown
```bash
# Security scan (Semgrep)
semgrep --config=.semgrep/rules.yml --config p/php --config p/owasp-top-ten \
    --config p/sql-injection --error \
    Subnet-Calculator/includes/ Subnet-Calculator/api/ Subnet-Calculator/templates/

# OpenAPI spec lint (Spectral — errors fail; review warnings each run)
npx --yes @stoplight/spectral-cli@6 lint Subnet-Calculator/api/openapi.yaml
```
```

- [ ] **Step 6: Verify**

```bash
grep -n "make test-docker\|561\|91 group\|clients\|semgrep\|spectral" CONTRIBUTING.md
```

- [ ] **Step 7: Commit**

```bash
git add CONTRIBUTING.md
git commit -m "docs: update CONTRIBUTING with Docker test gate, counts, clients/, Semgrep, Spectral"
```

---

## Task 9 — Update docs/index.md

Stale tarball version (2.6.0), missing `intl` PHP extension in requirements, no mention of PHP client.

**Files:**
- Modify: `docs/index.md:37`

- [ ] **Step 1: Verify stale version**

```bash
grep -n "2.6.0\|tarball\|tar -xzf\|intl\|extension" docs/index.md
```

Expected: line 37 references `subnet-calculator-2.6.0.tar.gz`.

- [ ] **Step 2: Fix tarball version**

Replace:
```
tar -xzf subnet-calculator-2.6.0.tar.gz -C /var/www/html/subnet-calculator/
```
With:
```
tar -xzf subnet-calculator-2.8.1.tar.gz -C /var/www/html/subnet-calculator/
```

- [ ] **Step 3: Add intl extension to requirements**

Find the requirements section. Add `php-intl` alongside or after the existing PHP requirements:

```markdown
- **PHP `intl` extension** — for locale-aware thousands-separator formatting (e.g. `1,234,567`). Falls back to comma separators when unavailable.
```

- [ ] **Step 4: Add PHP client mention**

After the "Download" section or in a "See also" block at the bottom, add:

```markdown
A lightweight **PHP client library** (`clients/php/SubnetCalculatorClient.php`) is bundled in the repository for programmatic API access. See the [PHP Client](client.md) page for usage.
```

- [ ] **Step 5: Verify**

```bash
grep -n "2.8.1\|intl\|client" docs/index.md
```

- [ ] **Step 6: Commit**

```bash
git add docs/index.md
git commit -m "docs: fix tarball version in index.md, add intl requirement and PHP client note"
```

---

## Task 10 — Update Tool Drawer UX in sub-tool docs

Six docs pages still describe sub-tools as "inline panels" or "tabs". Since v2.8.0 they live in a slide-in Tool Drawer accessed via a toolbar button. Update the UI-location description in each affected page.

**Files:**
- Modify: `docs/overlap.md`
- Modify: `docs/supernet.md`
- Modify: `docs/ula.md`
- Modify: `docs/sessions.md`
- Modify: `docs/ipv6.md` (overlap checker location)

The replacement phrase to use throughout: **"in the Tool Drawer (toolbar button on the IPv4/IPv6 tab)"** — adjust per-page based on which tab the tool lives in.

- [ ] **Step 1: Audit current UI-location descriptions**

```bash
grep -n "VLSM tab\|IPv4 tab\|IPv6 tab\|panel\|scroll" docs/overlap.md docs/supernet.md docs/ula.md docs/sessions.md docs/ipv6.md
```

Note each line that describes a tab/panel/scroll UI location.

- [ ] **Step 2: Fix docs/overlap.md**

Find the phrase that says the Overlap Checker is "On the VLSM tab" (or similar). Replace with:

```markdown
The **Subnet Overlap Checker** is available in the Tool Drawer — click the toolbar button on the IPv4 or IPv6 tab to open it.
```

Also add an API cross-reference at the end of the page:

```markdown
## REST API

The overlap check is also available programmatically via [`POST /api/v1/overlap`](api.md#post-apiv1overlap).
```

- [ ] **Step 3: Fix docs/supernet.md**

Find the phrase "on the IPv4 tab" or "Supernet & Route Summarisation panel". Replace with:

```markdown
Both tools are available in the **Tool Drawer** — click the toolbar button on the IPv4 tab to open it.
```

Add API cross-reference:

```markdown
## REST API

- Supernet calculation: [`POST /api/v1/supernet`](api.md#post-apiv1supernet)
- Route summarisation: included in the same endpoint via `mode=summarise`
```

- [ ] **Step 4: Fix docs/ula.md**

Find "scroll to the IPv6 ULA Prefix Generator panel" or similar. Replace with:

```markdown
The **IPv6 ULA Prefix Generator** is in the Tool Drawer — click the toolbar button on the IPv6 tab to open it.
```

Add the `available_64s` field mention after the output fields list:

```markdown
- **Available /64s** — total number of `/64` subnets available within the generated `/48` prefix (always 65,536).
```

Add API cross-reference:

```markdown
## REST API

Generate a ULA prefix programmatically via [`POST /api/v1/ula`](api.md#post-apiv1ula).
```

- [ ] **Step 5: Fix docs/sessions.md**

Find "Save & Restore panel". Replace with:

```markdown
The **Session** controls are in the Tool Drawer — click the toolbar button on the VLSM tab to open it.
```

Add a note about the API:

```markdown
## API access

Sessions can also be created and retrieved programmatically:

- `POST /api/v1/sessions` — save a payload, receive an 8-char ID
- `GET /api/v1/sessions/:id` — retrieve a session by ID

See [REST API](api.md#post-apiv1sessions) for curl examples.
```

- [ ] **Step 6: Fix docs/ipv6.md overlap checker reference**

Find the sentence "Two IPv6 CIDRs can be compared via the Subnet Overlap Checker panel on the VLSM tab". Replace with:

```markdown
Two or more IPv6 CIDRs can be compared via the **Subnet Overlap Checker** in the Tool Drawer — click the toolbar button on the IPv6 tab. See [Overlap Checker](overlap.md) for details.
```

- [ ] **Step 7: Verify**

```bash
grep -n "Tool Drawer\|toolbar button" docs/overlap.md docs/supernet.md docs/ula.md docs/sessions.md docs/ipv6.md
```

Each file should have at least one "Tool Drawer" reference.

- [ ] **Step 8: Commit**

```bash
git add docs/overlap.md docs/supernet.md docs/ula.md docs/sessions.md docs/ipv6.md
git commit -m "docs: update sub-tool pages to reflect v2.8.0 Tool Drawer UX"
```

---

## Task 11 — Improve docs/sharing.md: sc-set-bg and show_share_bar

Two useful features are absent from the sharing docs: the `sc-set-bg` postMessage for runtime background colour control, and the `$show_share_bar = false` config for iframe deployments.

**Files:**
- Modify: `docs/sharing.md`

- [ ] **Step 1: Check what is currently covered**

```bash
grep -n "sc-set-bg\|show_share_bar\|background\|color" docs/sharing.md
```

Expected: none of these are present.

- [ ] **Step 2: Add sc-set-bg section**

After the `### Auto-sizing the iframe` section, add:

````markdown
### Setting a custom background colour at runtime

After the iframe has loaded, send a `sc-set-bg` postMessage to change the calculator's background colour without reloading the page:

```js
// Wait for the iframe to finish loading first
scFrame.addEventListener('load', function () {
  scFrame.contentWindow.postMessage({ type: 'sc-set-bg', color: '#f0f4f8' }, '*');
});

// Reset to default (remove override)
scFrame.contentWindow.postMessage({ type: 'sc-set-bg', color: null }, '*');
```

The `color` value is any valid CSS colour string. Sending the message before the `load` event fires will be silently dropped because the calculator's listener is not running yet.
````

- [ ] **Step 3: Add $show_share_bar note**

After the `### Frame-ancestors policy` section, add:

````markdown
### Hiding the share bar in embedded deployments

When embedding the calculator as an iframe you may not want the Share URL bar visible. Set `$show_share_bar = false` in `config.php`:

```php
$show_share_bar = false;
```

This hides the bar globally for all visitors, which is appropriate when the embed URL is managed externally.
````

- [ ] **Step 4: Verify**

```bash
grep -n "sc-set-bg\|show_share_bar" docs/sharing.md
```

- [ ] **Step 5: Commit**

```bash
git add docs/sharing.md
git commit -m "docs: add sc-set-bg postMessage and show_share_bar sections to sharing.md"
```

---

## Task 12 — Create PHP client library docs page

The PHP client at `Subnet-Calculator/clients/php/SubnetCalculatorClient.php` is completely undocumented in the MkDocs site. This task creates the page and adds it to the nav.

**Files:**
- Create: `docs/client.md`
- Modify: `docs/mkdocs.yml` (add nav entry)

- [ ] **Step 1: Read the client to understand its public API**

```bash
cat Subnet-Calculator/clients/php/SubnetCalculatorClient.php
```

Note the constructor signature, public methods, and any exceptions it throws.

- [ ] **Step 2: Create docs/client.md**

Based on what you found in Step 1, create `docs/client.md` with the following structure:

```markdown
# PHP Client Library

A lightweight PHP wrapper for the Subnet Calculator REST API. No Composer or external dependencies required — just copy the file into your project.

## Installation

Copy `clients/php/SubnetCalculatorClient.php` from the repository into your project.

## Usage

```php
<?php
require 'SubnetCalculatorClient.php';

$client = new SubnetCalculatorClient('https://example.com/subnet-calculator/');

// IPv4 subnet calculation
$result = $client->ipv4('192.168.1.0/24');
echo $result['data']['network_cidr']; // 192.168.1.0/24

// IPv6 subnet calculation
$result = $client->ipv6('2001:db8::/32');

// VLSM allocation
$result = $client->vlsm('10.0.0.0/24', [
    ['name' => 'LAN', 'hosts' => 50],
    ['name' => 'DMZ', 'hosts' => 14],
]);
```

## Constructor

```php
new SubnetCalculatorClient(string $baseUrl, string $token = '', int $timeout = 10)
```

| Parameter | Type | Description |
|---|---|---|
| `$baseUrl` | `string` | Base URL of the Subnet Calculator install (trailing slash optional). |
| `$token` | `string` | Bearer token for `$api_tokens`-protected installs (optional). |
| `$timeout` | `int` | HTTP request timeout in seconds (default: 10). |

## Methods

> List each public method found in the client file. For each method, show the signature and a one-line description. Fill this in from Step 1.

## Error handling

The client throws a `RuntimeException` on HTTP errors (non-2xx) or network failures. The exception message includes the HTTP status code and the API error payload.

## Authentication

If the server has `$api_tokens` configured, pass your token as the second constructor argument:

```php
$client = new SubnetCalculatorClient('https://example.com/subnet-calculator/', 'your-token-here');
```

## See also

- [REST API reference](api.md)
- [Operator config](config.md)
```

Populate the **Methods** table from the actual methods you read in Step 1. Do not leave it as a placeholder.

- [ ] **Step 3: Add nav entry to mkdocs.yml**

In `docs/mkdocs.yml`, find the `nav:` section and add the new page. Place it after the REST API entry:

```yaml
  - REST API: api.md
  - PHP Client Library: client.md
  - Operator Config: config.md
```

- [ ] **Step 4: Verify the file was created and nav is correct**

```bash
ls docs/client.md
grep -n "client\|PHP Client" docs/mkdocs.yml
```

- [ ] **Step 5: Commit**

```bash
git add docs/client.md docs/mkdocs.yml
git commit -m "docs: add PHP client library page and mkdocs.yml nav entry"
```

---

## Task 13 — Add missing CHANGELOG v0.7 entry

The CHANGELOG jumps from `## [0.6]` to `## [0.8]` with no v0.7. Check git history to find what was actually changed in that version and add the entry.

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Check git log around the v0.6/v0.8 timeframe**

```bash
git log --oneline --after="2026-04-02" --before="2026-04-04" | head -20
```

Look for commits that would have constituted a v0.7 release.

- [ ] **Step 2: Verify the gap**

```bash
grep -n "## \[0\." CHANGELOG.md | head -15
```

Confirm that `[0.7]` is absent and the gap between 0.6 and 0.8 exists.

- [ ] **Step 3: Add the v0.7 entry**

Between `## [0.6] - 2026-04-03` and `## [0.8] - 2026-04-04`, insert an entry based on the git log findings. If git history reveals specific changes, document them accurately. If no commits can be cleanly attributed to v0.7, add a placeholder that is honest about the gap:

```markdown
## [0.7] - 2026-04-03

### Added

- *(This version entry was missing from the changelog — see git log for changes between v0.6 and v0.8)*
```

Only use the placeholder if no commits can be found. If commits exist, replace it with actual content.

- [ ] **Step 4: Verify**

```bash
grep -n "## \[0\." CHANGELOG.md | head -15
```

Confirm `[0.7]` now appears between `[0.6]` and `[0.8]`.

- [ ] **Step 5: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs: add missing CHANGELOG v0.7 entry"
```

---

## Self-Review

### Spec coverage

| Issue from audit | Covered by task |
|---|---|
| `sc-height` → `sc-resize` in sharing.md | Task 1 ✅ |
| `$split_max_subnets` 256 → 16 in ipv4.md | Task 2 ✅ |
| rdns TTL 3600 → 86400 | Task 2 ✅ |
| binary.md hex format | Task 2 ✅ |
| docs/api.md bulk limit 20 → 50 | Task 3 ✅ |
| docs/api.md missing POST /sessions | Task 3 ✅ |
| openapi.yaml license MIT → AGPL-3.0 | Task 4 ✅ |
| openapi.yaml version "1" → "1.0" | Task 4 ✅ |
| openapi.yaml meta example missing sessions/changelog | Task 4 ✅ |
| SECURITY.md stale supported versions | Task 5 ✅ |
| README.md stale tarball extract version (2.7.0) | Task 6 ✅ |
| README.md missing API endpoints (meta, changelog, bulk, sessions) | Task 6 ✅ |
| README.md missing `$api_request_log` config vars | Task 6 ✅ |
| README.md missing Docker deployment | Task 7 ✅ |
| README.md missing PHP client library | Task 7 ✅ |
| README.md missing Service Worker/offline | Task 7 ✅ |
| README.md missing Tool Drawer (v2.8.0) | Task 7 ✅ |
| CONTRIBUTING.md missing make test-docker | Task 8 ✅ |
| CONTRIBUTING.md missing visual regression | Task 8 ✅ |
| CONTRIBUTING.md stale Playwright count (517/85) | Task 8 ✅ |
| CONTRIBUTING.md missing clients/php/ in structure | Task 8 ✅ |
| CONTRIBUTING.md missing Semgrep and Spectral | Task 8 ✅ |
| docs/index.md stale tarball version (2.6.0) | Task 9 ✅ |
| docs/index.md missing intl requirement | Task 9 ✅ |
| Tool Drawer UX stale in overlap/supernet/ula/sessions/ipv6.md | Task 10 ✅ |
| docs/sharing.md missing sc-set-bg | Task 11 ✅ |
| docs/sharing.md missing show_share_bar | Task 11 ✅ |
| PHP client library undocumented in MkDocs | Task 12 ✅ |
| CHANGELOG v0.7 missing | Task 13 ✅ |

### Placeholder scan

Task 12 Step 2 contains one intentional instruction placeholder in the Methods table ("Fill this in from Step 1"). This is not a red flag — it is conditional on reading the actual client file, which must happen at execution time. The instruction is clear and specific.

### Intentionally out of scope

The following issues were noted in the audit but are code/spec changes that should be separate PRs, not documentation fixes:

- `openapi.yaml` `overlap` endpoint missing `partial` from the `relation` enum — this requires verifying actual API behaviour first
- `openapi.yaml` 5xx error responses missing from most endpoints — spec enhancement, not a docs fix
- `docs/rdns.md` missing `ns`/`hostmaster`/`serial`/`domain` request params — depends on verifying what the current API actually accepts
- `docs/tree.md` ASCII tree character inconsistency (`├──` vs `├─`) — cosmetic; low priority
