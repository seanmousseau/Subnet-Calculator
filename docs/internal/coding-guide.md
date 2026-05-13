# Coding Guide

**Audience:** Developer / future-agent writing PHP, JS, or CSS in this repository.
**Companion to:** `design-document.md` §4 (code methodology, in summary form). This guide expands with practical rules.

The guide is split into:
- **PHP conventions** — the bulk of the codebase.
- **JS conventions** — vanilla, single-file.
- **CSS conventions** — token-based.
- **Cross-cutting** — naming, comments, commits.

---

## PHP conventions

### Strict typing — always

Every file in `includes/`, `api/v1/`, `api/v1/handlers/`, `admin/` starts with:

```php
<?php

declare(strict_types=1);
```

No exceptions. PHPStan L9 enforces this transitively (functions must declare types; arrays must declare shapes via PHPDoc).

### Naming

| Construct | Convention | Example |
|---|---|---|
| File | `kebab-case.php` for modules, lowercase for entry points | `functions-multicast6.php`, `index.php` |
| Function | `snake_case` | `calculate_subnet6`, `cidrs_overlap` |
| Class (rare — we prefer pure functions) | `PascalCase` | `SessionStore` |
| Constant | `UPPER_SNAKE` | `MAX_SPLIT_SUBNETS` |
| Variable | `$snake_case` | `$ipv6_address`, `$prefix_length` |
| Template global (set by `request.php` for template) | `$snake_case` | `$result`, `$vlsm_plan` |

Function names should be read as a verb phrase: `calculate_subnet`, `parse_ipv6`, `validate_cidr`. If you find yourself wanting "and" in the name, it's two functions.

### Pure functions are the default

Every `functions-*.php` file exports pure functions. Pure means:
- No globals read or written (except in the dedicated `functions-session.php` / admin / audit / apikeys files where SQLite is the data store).
- No I/O.
- Deterministic for the same input.
- No side effects.

If you're tempted to write a non-pure helper outside the session/admin/audit/apikeys files, stop. Either:
- Refactor to take the I/O dependency as an argument, or
- Put the side-effecting code in `request.php` or the relevant handler, calling the pure function for the calculation.

### Error handling

Throw `InvalidArgumentException` for caller-controlled bad input. Don't return `false` or `null` to signal invalid — the type system carries the contract.

```php
// Good
function calculate_subnet(string $cidr): array
{
    if (!str_contains($cidr, '/')) {
        throw new InvalidArgumentException('CIDR must contain a slash');
    }
    // ...
}

// Bad
function calculate_subnet(string $cidr): array|false
{
    if (!str_contains($cidr, '/')) {
        return false;
    }
    // ...
}
```

Catch exceptions at the boundary (`request.php` or `api/v1/handlers/*.php`), not in pure functions. Pure functions should propagate.

### IPv4 arithmetic (invariant I1)

`ip2long()` returns a signed integer on 64-bit PHP. After **every** bitwise op, mask with `& 0xFFFFFFFF`:

```php
// Good
$network = ($ip & $mask) & 0xFFFFFFFF;
$broadcast = ($network | ~$mask) & 0xFFFFFFFF;

// Bad — bug waiting to happen
$network = $ip & $mask;
```

Test with values ≥ 128.0.0.0 to make sure the masking is correct.

### IPv6 arithmetic (invariants I2, I3)

GMP only. Never PHP signed-int math. Standard pipeline:

```php
$gmp = gmp_init(bin2hex(inet_pton($address)), 16);
$gmp_result = gmp_and($gmp, $mask_gmp);
$hex = str_pad(gmp_strval($gmp_result, 16), 32, '0', STR_PAD_LEFT);
$result = inet_ntop(hex2bin($hex));
```

Subnet/host counts that may exceed 2^63 are returned as the **string** `"2^N"`, never as a PHP int. Match the convention in `calculate_subnet6` and `vlsm6_allocate`.

### Validation at the boundary

`request.php` (web) and `api/v1/handlers/*.php` (API) are the validators. Pure functions trust their inputs. This means:

```php
// In api/v1/handlers/ipv4.php (the boundary)
$cidr = $_POST['cidr'] ?? '';
if (!is_string($cidr) || strlen($cidr) > 18 || !str_contains($cidr, '/')) {
    json_err(400, 'INVALID_INPUT', 'cidr must be a CIDR string like 10.0.0.0/24');
    return;
}

$result = calculate_subnet($cidr);  // Pure function; assumes preconditions hold.
```

Don't re-validate inside `calculate_subnet`. If the boundary missed something, fix the boundary.

### Output escaping (invariant I12)

Every `echo` of user-influenced data:

```php
<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>
```

Always `ENT_QUOTES`. Always UTF-8. Single-quoted attribute safety depends on `ENT_QUOTES`.

For JSON in templates (rare):
```php
<?= htmlspecialchars(json_encode($data, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>
```

For URLs in attributes:
```php
href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"
```

### Schema changes — update the data dictionary

Any PR that touches `CREATE TABLE`, `CREATE INDEX`, `ALTER TABLE`, `PRAGMA table_info`, or any DB-path config variable **must** update [`data-dictionary.md`](data-dictionary.md) in the same PR. New tables/columns get a row; migrations get a sub-section entry; DB-path changes update the physical-layout section. PRs that don't are incomplete.

See `data-dictionary.md` "Migration policy" for the idempotent-migration pattern (PRAGMA-check + ALTER, hard-fail on partial schema, never rename/remove in place).

### Config-variable changes — update the config reference

Any PR that adds, removes, renames, or changes the default of an operator-tunable `$variable` in `includes/config.php` **must** update [`config-reference.md`](config-reference.md) and `config.php.example` in the same PR. The reference must catalogue every operator-facing variable; if a variable exists in code but not in the reference, the docs are stale.

### SQL — always parameterised

Every SQLite query uses prepared statements with bound parameters:

```php
// Good
$stmt = $db->prepare('SELECT * FROM sessions WHERE id = :id');
$stmt->execute(['id' => $session_id]);

// Bad — and impossible to ship past Semgrep
$rows = $db->query("SELECT * FROM sessions WHERE id = '$session_id'");
```

No user-controlled table or column names anywhere. If you're tempted, you're modelling something wrong.

### No silent fallbacks (design-document §4.6)

If a feature can't run, surface it. Don't degrade silently:

```php
// Good
if (!extension_loaded('gmp')) {
    throw new RuntimeException('GMP extension required for IPv6 operations');
}

// Bad — masks a real problem
if (!extension_loaded('gmp')) {
    return ['error' => 'IPv6 unavailable'];
}
```

### `sc_run_*` symmetry (design-document §4.7)

Tools with shareable URLs use one helper in `request.php`:

```php
function sc_run_<name>(): void
{
    global $<name>_result, $<name>_error;

    $input = $_POST['x'] ?? $_GET['x'] ?? null;
    if ($input === null) {
        return;
    }

    try {
        $<name>_result = <name>_compute($input);
    } catch (InvalidArgumentException $e) {
        $<name>_error = $e->getMessage();
    }
}
```

The template renders identically whether the helper was triggered by POST or GET. Don't split paths.

### Function size

Aim for ≤50 lines per pure function. Beyond that, look for an extraction point. Hard cap: 100 lines. If you can't get under 100, the function is doing too much.

### PHPDoc

Required when the type system can't express the contract — typically array shapes:

```php
/**
 * @return array{
 *     network: string,
 *     broadcast: string,
 *     hosts: int,
 *     prefix: int<0, 32>,
 *     mask: string,
 *     wildcard: string,
 *     class: string,
 * }
 */
function calculate_subnet(string $cidr): array
```

PHPStan reads these and enforces them. Don't write decorative PHPDoc that restates the signature — it's noise.

### Comments

Default to none. Add a comment only when the *why* is non-obvious:

```php
// `& 0xFFFFFFFF` keeps the result unsigned — ip2long is signed on 64-bit.
$network = ($ip & $mask) & 0xFFFFFFFF;
```

Don't write what the code does — names should do that. Don't reference issue numbers, PRs, or commit hashes — they rot.

---

## JavaScript conventions

`app.js` is single-file vanilla JS. ~2845 lines. No build step, no transpilation, no framework.

### Style

- ES2020 minimum (we ship to all modern browsers).
- `const` by default; `let` when reassignment is needed; never `var`.
- Strict equality (`===`, `!==`).
- Template literals for string composition.
- Arrow functions for callbacks; named `function` for hoisted handlers.
- Module pattern via IIFEs or top-level `const`s; no ES modules (the file is `<script src=...>` not `<script type=module>`).

### DOM

- `document.querySelector` / `querySelectorAll`. No `getElementById` chains.
- Avoid string-concatenation into `innerHTML` for user-supplied content — use `textContent` or `dataset`.
- For dynamic markup, use `document.createElement` + `append`. Don't write template strings of HTML.

### Event handling

- Delegate where sensible (one listener on `document` for `[data-tool]` clicks, not 30 listeners).
- Always feature-detect (`if (navigator.clipboard) ...`) — fall back gracefully.
- Use `addEventListener`, not `onclick`.

### State

- `localStorage` for user preferences (theme, tool-group collapse state). Always handle JSON parse failures.
- `sessionStorage` for ephemeral session data (iframe target origin fallback).
- No global mutable state objects. If you need to share state across functions, pass it explicitly.

### Async

- `async`/`await`, never `.then()` chains beyond 2 levels.
- Catch errors at the boundary; don't let promises reject silently.

### Testing

- Browser tests cover JS behaviour via Playwright. There's no unit-test layer for JS — the surface area is small enough that E2E is sufficient.
- Clipboard interception in tests uses `page.add_init_script()` to stub `navigator.clipboard.writeText` (see `design-document.md` and the layout-PHP CLAUDE.md note about `add_init_script` matching the production API surface).

---

## CSS conventions

`app.css` is single-file, token-based, ~3115 lines.

### Tokens — reuse, don't add

CSS custom properties (`--color-*`, `--space-*`, `--text-*`) are the design system. Reuse them. Adding a new token requires a formal style-guide bump.

Frozen names that **cannot** be renamed:
- `--color-bg` (invariant I4 — iframe API depends on this exact name).
- `--color-btn-text` (invariant I5 — must stay dark for teal-button contrast).

### Theming

Light mode: `html[data-theme="light"] { ... }` overrides.
Dark mode: default.
Print: `@media print` redefines tokens (not selectors) to print-safe values.

### Selectors

- Class-based. No ID selectors except `#main-content` (the page landmark — invariant I13).
- Avoid `!important` except in print stylesheet overrides (`@media print` block).
- Nesting depth ≤ 3 levels.

### Focus

`:focus-visible` for focus rings (invariant I21). Never `:focus` alone — that gives the sticky mouse-click outline that v3.7.0 T5 specifically removed.

### Responsive

- Mobile-first where it doesn't hurt readability of the rule.
- Breakpoints: 480px is the only formal one (mobile/desktop switch). Others are situational.
- `.card` overflow handling is split (invariant I6): `overflow-x: clip` base, full `clip` only at ≤480px.

### Animation

- Respect `prefers-reduced-motion`. Use a `@media (prefers-reduced-motion: reduce)` block to neutralise transforms/transitions.
- No infinite animations except very subtle loaders.

### Stylelint

Enforced in CI. Conventions are partly automated; run `npm run lint:css` before pushing.

---

## Cross-cutting

### File headers

Every PHP file starts with:

```php
<?php

declare(strict_types=1);
```

Nothing else. No copyright block, no author tag, no version comment.

### Imports

`require` at the top. Use `require_once` for files that may be required transitively; `require` for files we know are loaded exactly once (bootstrap chain in `index.php`).

### Commit messages

Format:
- Line 1: `type(scope): short summary` (e.g., `fix(ipv4): cidrs_overlap should use min not max`).
- Blank line.
- Body: what + why, not how. Reference invariants when fixing one (e.g., "Fixes I18 violation introduced in v1.2.0.").
- Footer: `Co-Authored-By: ...` if applicable.

Types: `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `release`.
Scopes: `ipv4`, `ipv6`, `api`, `ui`, `vlsm`, `vlsm6`, `admin`, `release`, `docs`, etc.

### PR descriptions

```text
## Summary
1–3 bullets

## Test plan
- [ ] PHPUnit green
- [ ] PHPStan L9 clean
- [ ] make test-docker green
- [ ] Manual smoke test of the affected surface
```

### Branch names

Branching model + naming conventions (including hotfix and patch-release flow) live in [`design-document.md`](design-document.md) §10.1. Don't duplicate them here — read the canonical source before naming a branch.

### Code review

CodeRabbit auto-reviews. Treat actionable findings as required; nitpicks may be deferred only with explicit operator approval. Merge with `--merge` (repo policy disallows squash); `--admin` only when CodeRabbit posted stale CHANGES_REQUESTED on early task commits.

Manual review by the operator is also required for any non-trivial change. The pattern is: PR opens → CodeRabbit + CI run → operator skims and approves → merge.

### When in doubt

Ask the existing code. The 50+ `functions-*.php` files are the corpus. If your new function looks different from every existing one, *you* are probably the outlier, not the codebase.

---

## Update protocol

Update this guide when:
1. A new convention emerges (e.g., we adopt readonly classes when PHP 8.1 support drops).
2. A linter rule changes (PHPStan or PHPCS config updates).
3. A new anti-pattern is identified.
4. A naming convention shifts.

Bump the "Last updated" header.

Don't update this guide for:
- Per-PR style preferences.
- Personal taste differences (use the existing style).
- Library version bumps that don't change conventions.
