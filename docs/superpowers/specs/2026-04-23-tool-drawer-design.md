# Tool Drawer — v2.8.0 Design Spec

**Date:** 2026-04-23  
**Status:** Approved  
**Scope:** UI/UX — sub-tool navigation overhaul

## Problem

The IPv4, IPv6, and VLSM tab panels each contain multiple sub-tools (Split Subnet, Supernet, Range→CIDR, Subnet Allocation Tree, Overlap Checker, ULA Generator, Reverse DNS, Save/Restore Session) rendered fully inline, always visible, regardless of whether the user intends to use them. This makes the main calculator feel cluttered and hard to scan.

## Solution

Replace always-visible inline sub-tool panels with a **persistent slide-in tool drawer**. A compact toolbar of labelled buttons appears below the main result; clicking a button opens the drawer to that tool. The main calculator and Binary Representation section are unaffected.

## Scope

### What moves into the drawer

| Tab  | Sub-tools |
|------|-----------|
| IPv4 | Split Subnet, Supernet & Route Summarisation, IP Range→CIDR, Subnet Allocation Tree, Overlap Checker |
| IPv6 | Split Subnet, ULA Prefix Generator, Reverse DNS, Overlap Checker |
| VLSM | Save & Restore Session |

### What stays in place

- Main calculator form + result rows
- Binary Representation `<details>` (already collapsible)

## Architecture

### HTML (`templates/layout.php`)

- Each sub-tool block is wrapped in `<div class="tool-panel" data-tool="<id>">` and moved into a `<div class="tool-drawer" role="dialog" aria-modal="true" aria-labelledby="tool-drawer-title-<tabid>">` at the bottom of each tab panel (one drawer per panel; use class not id to avoid duplicate-id violations).
- A new `<div class="tool-toolbar">` is inserted directly after the binary `<details>`, containing one `<button class="tool-trigger" data-tool="<id>" aria-expanded="false">` per sub-tool.
- The drawer includes a header: `<span id="tool-drawer-title">` (tool name) + `<button class="tool-drawer-close">×</button>`.
- No-JS fallback: toolbar buttons are hidden via `.js-enabled .tool-toolbar { display: flex }` (default hidden); sub-tool panels render inline when JS is absent.

### CSS (`assets/app.css`)

- Card gets `position: relative; overflow-x: clip` — clips horizontal overflow to hide the off-screen drawer without creating a new BFC (`overflow: hidden` would trap absolutely-positioned descendants like help-bubble tooltips).
- `.tool-drawer` — `position: absolute; right: 0; top: 0; bottom: 0; width: 340px; background: var(--color-surface); border-left: 1px solid var(--color-border); transform: translateX(100%); transition: transform 200ms ease; overflow-y: auto; z-index: 10`.
- `.tool-drawer.open` — `transform: translateX(0)`.
- Bottom-sheet at `width <= 480px` — `top: auto; left: 0; right: 0; width: 100%; height: 60vh; transform: translateY(100%); border-left: none; border-top: 1px solid var(--color-border)`. `.tool-drawer.open` — `transform: translateY(0)`.
- `@media (prefers-reduced-motion: reduce)` — `transition: none`.
- `.tool-toolbar` — `display: none` by default; `.js-enabled .tool-toolbar` — `display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 1rem`.
- `.tool-trigger` — compact secondary button style (outline variant of existing `.splitter-btn`); `.tool-trigger.active` — accent background to indicate open tool.

### JS (`assets/app.js`)

A `toolDrawer` object (~80 lines) added to the existing script:

```
toolDrawer = {
  drawer, title, panels, triggers,
  open(toolId),   // show panel[toolId], update aria-expanded, move focus
  close(),        // hide drawer, restore focus to last trigger
  swap(toolId),   // switch visible panel without close/reopen animation
  init()          // bind toolbar clicks, Escape keydown, × button
}
```

- Toolbar button click: if drawer closed → `open(toolId)`; if drawer open and same tool → `close()`; if drawer open and different tool → `swap(toolId)`.
- Escape keydown: `close()`.
- × button click: `close()`.
- Focus management: on open, focus first focusable element in the drawer; on close, return focus to the trigger button that opened it.
- On page load: if a PHP result variable indicates a sub-tool was just submitted (detected via a `data-open-tool` attribute emitted by PHP on the toolbar wrapper), call `open(toolId)` immediately. JS queries are scoped to the active tab panel (`document.querySelector('.panel.active .tool-drawer')`) to handle per-panel drawer instances.

### PHP (`templates/layout.php`)

- Emit `data-open-tool="split"` (or whichever tool) on the `.tool-toolbar` wrapper when the corresponding result variable is set (e.g. `$split_result !== null`, `$supernet_result !== null`, etc.).
- No changes to request handling, form POST targets, or any `includes/` files.

## Behaviour Details

- Only one tool visible at a time; switching tools cross-fades content (opacity 0→1, 150ms) without closing the drawer.
- Drawer state is not persisted across page loads (sub-tool results already require a POST/GET round-trip; the auto-reopen on load covers the post-submit case).
- `aria-expanded` on each toolbar button reflects whether the drawer is open **and** that button's tool is active.
- Drawer `aria-labelledby` points to the `<span id="tool-drawer-title">` which updates to the active tool name on swap.

## Testing

### Playwright (new test groups, ~6–8)

1. Toolbar renders after IPv4 calculate; correct buttons present
2. Click Split button → drawer opens, Split panel visible, button `aria-expanded="true"`
3. Submit Split form → page reloads → drawer auto-reopens to Split with results
4. Escape closes drawer; focus returns to trigger button
5. × button closes drawer
6. Click same button twice → drawer closes (toggle)
7. Click different button while open → content swaps, drawer stays open
8. Viewport 480px: drawer renders as bottom sheet

### Accessibility

- `prefers-reduced-motion`: transition disabled
- Focus trap: Tab key cycles within open drawer
- Screen reader: `role="dialog"`, `aria-modal="true"`, `aria-labelledby` set correctly

### PHPUnit

No new unit tests — no PHP logic changes.

## Out of Scope

- Cross-tool input sharing (e.g. auto-populate Split with the IPv4 result CIDR)
- Persisting last-open tool across sessions
- New tool tabs or new sub-tools
