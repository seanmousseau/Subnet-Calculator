# Testing Guide

**Audience:** Developer / future-agent adding code to this project.
**Companion to:** `design-document.md` §9 (testing topology).
**Premise:** CI is necessary but not sufficient. Manual regression is mandatory on every release (v3.6.2 lesson).

---

## The five layers

| Layer | Tool | Speed | When to add |
|---|---|---|---|
| Unit | PHPUnit 11 | ~1 s | Every new pure function in `includes/functions-*.php` |
| Static | PHPStan L9 / PHPCS / Semgrep | ~5–10 s | Automatic on every PHP edit (PostToolUse hook) |
| API | PHPUnit + curl from Playwright | ~30 s | Every new API endpoint or response-shape change |
| E2E | Playwright (Docker) | ~3–5 min | Every new UI surface or user-visible behaviour change |
| Visual | Pillow snapshots | ~30 s on opt-in | Every CSS change that affects layout or colour |
| **Manual** | **You + Playwright + `ui-ux-pro-max`** | **15–30 min** | **Every release. No exceptions.** |

---

## When to add what

### Adding a new pure function

→ **Unit test required.** Test happy path + every documented error case + IPv4/IPv6 edge values (0.0.0.0, 255.255.255.255, ::, ffff:…, /0, /32, /128).

For IPv4: also test values that trigger sign-bit (anything ≥ 128.0.0.0 / `0x80000000`). The `& 0xFFFFFFFF` mask is the load-bearing detail — if a test passes only with values < 128.0.0.0, it's not really testing the bitwise correctness.

For IPv6: test values requiring GMP (≥ 2^63 host count), and values that triggered the `"2^N"` string convention.

### Adding a new API endpoint

→ **Unit test the handler's pure function, then Playwright covers the HTTP surface.**

The Playwright test pattern:
```python
def test_new_endpoint(page):
    resp = page.request.post(f"{BASE}/api/v1/<name>", data={"input": "..."})
    assert resp.status == 200
    body = resp.json()
    assert body["ok"] is True
    assert body["data"]["expected_field"] == "expected_value"
```

Cover: happy path, 400 on bad input, 405 on wrong method, auth-required endpoints with and without a key.

Also update `api/openapi.yaml` — Spectral lint will fail CI if the new endpoint isn't documented.

### Adding a new tool drawer

→ **Both unit + Playwright.** The unit test covers the pure function; the Playwright test covers:
1. Trigger button exists and is keyboard-focusable.
2. Drawer opens on click + on Enter.
3. Drawer closes on Escape + on outside click.
4. Form submission produces the expected output cell.
5. Shareable URL hydration works (`?tool=<slug>&...` reproduces the result).
6. Focus returns to the trigger button on close (v3.7.0 T7).
7. Tab order through the drawer is sensible.
8. Help-bubble tooltips reveal on keyboard focus.

### Adding a CSS rule

→ **Visual snapshot test.** Snapshots live in `testing/snapshots/`. Run `SKIP_SNAPSHOTS=1 make test-docker` for normal runs; do an explicit snapshot capture run when intentionally changing visuals. Review the diff before committing the new baseline.

### Adding form-protection logic / auth / rate-limiting

→ **Semgrep custom rule** in `.semgrep/rules.yml` if the pattern is repeatable, plus Playwright covering both the positive (legitimate request succeeds) and negative (attack/abuse blocked) paths.

---

## Running tests locally

```bash
# Unit tests (fast — run constantly)
vendor/bin/phpunit

# A single unit test file
vendor/bin/phpunit testing/unit/Vlsm6Test.php

# Static analysis
phpstan analyse --no-progress --memory-limit=512M

# Linters (PHP)
vendor/bin/phpcs --standard=PSR12 Subnet-Calculator/includes/ Subnet-Calculator/api/
vendor/bin/phpcs --standard=.phpcs-admin.xml Subnet-Calculator/admin/

# Linters (JS/CSS)
npm run lint

# OpenAPI
npx --yes @stoplight/spectral-cli@6 lint Subnet-Calculator/api/openapi.yaml

# Security
semgrep --config=.semgrep/rules.yml --config p/php --config p/owasp-top-ten \
        --config p/sql-injection --error \
        Subnet-Calculator/includes/ Subnet-Calculator/api/ Subnet-Calculator/templates/

# E2E via Docker (preferred — no dev server needed; SKIP_SNAPSHOTS=1 is set automatically)
make test-docker
```

Run all of these green before pushing any PR.

---

## Manual production regression (mandatory per release)

This is the gate that catches what CI doesn't. The v3.6.2 `cidrs_overlap` bug shipped for 5+ months because every existing test used `10.0.0.0/X` pairs where the broken `max()` masking coincidentally produced correct results.

**Why coverage alone fails here:** structural CI catches bugs the test set anticipates. The blind spot is the test set's own bias. Manual regression with a human + an LLM consultant looking at real production explores spaces no automated test was written for.

### Pattern

1. Spin up a Playwright session against **production** (https://subnetcalculator.app/).
2. **Single agent, fetch-only, tightly scoped.** Don't parallelise — the first attempt at parallel browser-screenshot agents locked up Playwright for 2+ hours.
3. Hit every endpoint with diverse inputs (not just `10.0.0.0/24`). Use `192.168.1.0/24`, `172.16.5.0/28`, `203.0.113.128/29`, etc. — any time the inputs all share a prefix base, you're vulnerable to the v3.6.2 class of bug.
4. For UI: invoke `ui-ux-pro-max` to inspect each tab + every drawer. Note any visual or interaction issue, even if minor.
5. File findings as GitHub issues with milestone-appropriate labels.

### What to test every release

- Calculator (IPv4, IPv6) at /24, /16, /30, /32, /128, /48, /0.
- Every drawer tool with at least one non-default input.
- Shareable URL on every supporting tool.
- API: `GET /api/v1/meta` returns the new version. Then a handful of POST endpoints with diverse inputs.
- Security headers: HSTS present, CSP nonce changes per request.
- Service worker: footer version pill matches `$app_version`.
- Theme toggle works without FOUC.
- iframe consumer (`testing/fixtures/iframe-test.html` deployed to dev) still functional.

If anything looks wrong, **don't ship the release**. File the bug, ship a patch first.

---

## Snapshot workflow

Visual regression baselines live in `testing/snapshots/` as committed PNGs.

```bash
# Normal run: snapshots skipped (docker test harness sets SKIP_SNAPSHOTS=1)
make test-docker

# Capture/update snapshots: run via dev server flow with snapshots enabled
# (See playwright_test.py for the exact env wiring.)
```

When intentionally changing visuals:
1. Run the snapshot capture mode.
2. Review the diff visually — *don't blindly accept*. A failing snapshot can mean intended change or unintended regression. You must look.
3. Commit the new baseline with the same PR as the CSS change.

---

## Anti-patterns (don't do these)

- **Tests that assert on `htmlspecialchars()` output character-by-character.** Brittle. Assert on the visible text or the rendered DOM structure.
- **Tests that hardcode an entire JSON response.** Use specific field assertions so the test survives benign envelope changes.
- **Skipping tests for "experimental" code.** Either the code ships or it doesn't. Experimental code that's running on production needs the same coverage.
- **One test per HTTP status code.** Test the *behaviour*, not the status code. The status code is one assertion among several.
- **Mocking pure functions.** They're pure — call them directly.
- **`@dataProvider` exploding into thousands of micro-cases without testing the boundaries.** Five well-chosen boundary cases beat 5000 random cases.

---

## CI configuration

`.github/workflows/php.yml` — 3-version PHP matrix (8.2, 8.3, 8.4) running:
- PHPUnit (`--testdox` for readable output)
- PHPStan L9 (`--memory-limit=512M`)
- PHPCS PSR-12 (includes + api) + admin ruleset
- Spectral on `openapi.yaml`

On every PR and push to `main`/`dev`/`release/*`.

`.github/workflows/docs.yml` — MkDocs build + GitHub Pages deploy on push to `main`.

CodeRabbit posts reviews on every PR. Treat actionable findings as required fixes; nitpicks may be deferred only with explicit operator approval.

---

## Adding a new test layer

Don't, casually. The layers above represent five years of learning what catches bugs cheaply. New layers (e.g., property-based testing, fuzzing) are worth considering only when:
1. An incident occurs that *no existing layer would have caught*.
2. The new layer is genuinely orthogonal (not a slow re-run of an existing one).
3. The maintenance cost is bounded (the layer itself doesn't become tech debt).

If you do add a layer, update this guide and `design-document.md` §9 in the same PR.
