# Design Guide

**Last updated:** 2026-05-13 (app v3.7.0)
**Audience:** Developer / future-agent making UX or visual decisions.
**Companions:**
- `docs/superpowers/style-guide.md` — visual identity (logo, colours, typography, spacing tokens). The source of truth for *what things look like*.
- `docs/superpowers/plans/2026-04-25-v2.9.0-ui-ux-style-guide.md` — detailed UI/UX style guide from v2.9.0. The source of truth for *which patterns to use*.

**This document** captures the *design philosophy* — the principles that drive the visual + UX decisions. When the existing patterns don't cover a situation, this is what you reason from.

---

## Core principles

### 1. The calculator is the page

Everything else is a tool that augments calculator output. No drawer, tab, or modal should make the calculator harder to reach. If a new feature pushes the calculator below the fold on mobile, the feature is wrong.

This drove:
- The wall-of-buttons problem (v3.6.4) → 5 collapsible IPv6 tool groups (v3.7.0 T3).
- The decision to keep tools as drawers rather than separate pages.
- The "Copy All" + per-field copy convenience — read the calculator output, share it, stay on page.

### 2. Zero install, zero JS dependency

End users get a single PHP tarball. No build step. No SaaS account. No JS framework. `app.js` is 2845 lines of vanilla JS. The app must work with JS disabled for everything except clipboard, iframe resize, and tool-group `localStorage` persistence.

Implication: every new feature must degrade gracefully when JS is off. Form submissions are HTML-form-first; JS enhances but never replaces.

### 3. Shareability over persistence

Users don't have accounts. There's no history, no saved workspaces, no "my calculations." Instead, every tool result is in a **shareable URL** — paste it, share it, bookmark it. The `sc_run_*` helpers in `request.php` make POST and GET produce identical state.

Implication: any new tool with non-trivial input must support `?tool=<slug>&...` hydration. Don't add a tool that only works via POST — half its utility is gone.

### 4. Operator self-host first

The audience runs LAMP shared hosts. Drop-in tarball install on PHP 8.1+. No required external services. Cloudflare, Turnstile, etc. are all optional.

Implication: don't add hard runtime deps. If a feature needs a JS library, it ships in `assets/vendor/` with SRI hashes. If it needs a PHP extension beyond GMP and pdo_sqlite, it's optional and degrades gracefully.

### 5. Network engineers, not first-time users

The audience already knows what a CIDR is. We don't explain it. We don't onboard. We expose dense controls with help bubbles for the genuinely ambiguous details (e.g., "what does the wildcard mask mean here?"). Every help-bubble click costs the user time; only add them where a knowledgeable engineer might still be uncertain.

### 6. Density over whitespace

Calculator result panels show 15+ fields at once. That's intentional. Network engineers scanning a result want everything in view, not a paginated wizard. Use the existing `.result-grid` density.

### 7. Keyboard first, mouse second, touch third

Mouse is the lowest-friction input for casual use but the slowest for power users. Every drawer trigger has `tabindex="0"` and responds to Enter. Every help bubble is keyboard-reachable. Tab order is asserted in Playwright per drawer (v3.7.0 T7).

Touch users are real but rare for this audience — the mobile UX (v3.7.0 T4) is supportive, not primary.

### 8. Don't surprise the user with hiding

When the IPv6 wall-of-buttons UX problem (#420) was solved with collapsible groups, the call was: **all groups default-open on first visit, user-collapse state persists in `localStorage`**. Never default-hide content that was previously visible. The "look at all this!" → "where did everything go?" reaction is design failure.

### 9. WCAG AA contrast is non-negotiable

`--color-btn-text` stays dark on teal because white-on-teal fails AA (this is invariant I5). Any new colour pairing must hit AA contrast for normal text (4.5:1) and AA for non-text UI (3:1). Run the actual contrast check; don't eyeball it.

### 10. Reduced motion is a real preference

`prefers-reduced-motion` respected throughout. Drawer slide animations have reduced-motion equivalents. New animations must too.

---

## UX patterns to reuse (don't reinvent)

### Drawers (tool entry points)

- Markup convention: `<button data-tool="<slug>">` triggers; `<div class="drawer" data-tool="<slug>">` panels.
- One file per drawer at `templates/_tools/<slug>.php` (post v3.7.0 T2 refactor).
- Open: click or Enter. Close: Escape, click outside, or click trigger again.
- Focus returns to the trigger on close (v3.7.0 T7).
- Drawer panels use the result-grid pattern for output. They're not modal; the calculator above remains visible.

### Forms

- All forms are HTML-form-first. POST to `index.php` (web) or `/api/v1/<name>` (programmatic).
- Validation server-side; JS-side is a UX hint only.
- Errors render in a banner above the form. Don't pop modals.
- CIDR auto-detect: both server-side (`strpos($input, '/')`) and client-side (JS `blur` event) — covers the Enter-without-blur case.

### Result panels

- Use `.result-grid` for label/value pairs.
- Each field is independently copyable via a per-field copy button.
- "Copy All" button on every tab — pre-formatted plain text.
- "Copy as Markdown" and "Copy as Cisco" available for select tools (v2.11.0).

### Help bubbles

- Use `help_bubble($text)` from `functions-util.php`.
- Auto-inherits `tabindex="0" role="button" aria-label="Help"`.
- `:focus-within` CSS reveals the tooltip on keyboard focus — no JS needed.
- Placement: immediately after the label, before the input. Right side of the line.

### Shareable URLs

- Use a `sc_run_*` helper in `request.php`. One helper handles both POST and GET hydration.
- URL shape: `?tool=<slug>&<param>=<value>&<param>=<value>`.
- Template renders identically for POST and GET. No duplicate code paths.

### Toasts

- For ephemeral feedback ("Copied!", "Session saved"). Not for errors.
- `aria-live="polite"`. Auto-dismiss after 2 seconds.
- Don't nest toasts; the latest replaces the previous.

### Theme toggle

- Light/dark via `html[data-theme="light"]`.
- Persisted in `localStorage`.
- Theme-init `<script>` runs in `<head>` before paint to avoid FOUC. **Don't move it.**
- Print stylesheet redefines tokens for dark-mode printing (`@media print` block).

---

## What NOT to do

### Don't add new tabs

Four top-level tabs (IPv4, IPv6, VLSM, VLSM IPv6). The IPv6 surface is feature-complete per the original 4-release roadmap. New tools go into existing tabs as drawers, not new top-level tabs.

### Don't add new design tokens

Reuse the existing `--color-*`, spacing scale, and type scale. New tokens only land via a formal style-guide bump (and `--color-bg` cannot be renamed — invariant I4).

### Don't add modal dialogs

We use drawers and inline result panels. Modals trap focus, demand dismissal, and break the "calculator stays visible" principle. No modal has ever been justified yet.

### Don't add onboarding / tooltips-on-first-visit

The audience doesn't need a tour. If a feature is unintuitive, fix the feature, don't paper over it with a tour.

### Don't add toast notifications for normal flow

Toasts are for unexpected success (copied to clipboard, session saved). Don't toast on calculator submit — the result panel IS the feedback.

### Don't reinvent visual patterns

If you find yourself writing new card / panel / button CSS, stop. Read the existing `app.css`. The pattern probably exists; if it doesn't, propose adding it to the style guide before using it.

### Don't add a JS framework

Vanilla JS handles everything. The vendored SheetJS is the only third-party JS lib, and it's there because writing XLSX export from scratch isn't worth it. Anything beyond that requires a very high bar.

---

## Decision-making framework

When a UX call isn't obvious:

1. **Does an existing pattern cover this?** → Use it. Even if it's "good enough" — pattern consistency is worth more than local optimisation.
2. **Does this break a principle above?** → Reconsider. The principles are load-bearing; deviating costs more than the feature is worth.
3. **Does the user actually need this on the calculator page?** → If no, it doesn't belong here. We don't have an admin panel for end-user features. The calculator is the page.
4. **Will this make sense to someone landing cold via a shareable URL?** → If no, the shareability principle is broken.
5. **What does `ui-ux-pro-max` say?** → For any non-trivial UI change, consult before AND after the edit. This is a release gate (see [`design-document.md`](design-document.md) §6.9 — the manual-regression mandate that introduced the consult pattern).

---

## Mobile-specific notes

The mobile UX baseline (v3.7.0 T4):
- 375px is the narrowest viewport we explicitly target.
- 480px breakpoint flips the tab strip into a 2×2 grid.
- 480px breakpoint switches `.card` to `overflow: clip` (containing the bottom-sheet drawer); desktop uses `overflow-x: clip` only (preserves tooltips escaping vertically).
- Header h1 may wrap at <480px rather than truncate.
- Drawer panels are bottom-sheet-style on mobile, side-drawer on desktop.

These rules are in `app.css` and are part of invariant I6.

---

## Iframe consumers

Subnet Calculator is embeddable. Consumers:

- Auto-detected via `window.self !== window.top`, adding `in-iframe` to `<html>`.
- Receive height updates via `postMessage` (via `ResizeObserver`).
- Target origin uses `window.location.ancestorOrigins[0]` (Chrome/Edge) with `sessionStorage` fallback (Firefox). **Not** `document.referrer` — it breaks after same-origin form-submit navigations.
- Can customise background via `?bg=…` query param or `postMessage`. This works by setting `--color-bg` on `documentElement.style`. **This token name is invariant I4.**
- See `testing/fixtures/iframe-test.html` for the canonical consumer example.

Don't break the iframe contract without a major version bump.

---

## Adding a new tool — design checklist

Before writing code:

- [ ] Which tab does it belong to? (Reuse, don't add.)
- [ ] Which group within the IPv6 tab? (If IPv6, pick one of: foundational, address utilities, transition, prefix planning, multicast.)
- [ ] What's the drawer trigger label? Short, clear, scannable.
- [ ] What inputs does it need? (Validate the boundary; trust the function.)
- [ ] What does the result panel look like? (Reuse `.result-grid`.)
- [ ] Is the result panel copyable? Add copy button(s).
- [ ] Does it need a shareable URL? (Default: yes.) Wire `sc_run_<name>` in `request.php`.
- [ ] Does it have help bubbles where a network engineer might still be uncertain?
- [ ] What's the API surface? Add to `api/openapi.yaml` + `api/v1/handlers/<name>.php`.
- [ ] What's the Playwright assertion plan? (See `testing-guide.md`.)
- [ ] Has `ui-ux-pro-max` reviewed the markup + CSS before push?

If any of these are missing, the tool isn't done.

---

## Update protocol

Update this guide when:
1. A new principle emerges that wasn't documented.
2. An anti-pattern is added or removed.
3. The mobile baseline changes.
4. The iframe contract changes.
5. A new reusable UX pattern lands and should be standardised.

Bump the "Last updated" header.

Do **not** update this guide for:
- Per-tool styling tweaks (visual style guide handles those).
- Per-release content changes.
- Bug fixes that don't change design intent.
