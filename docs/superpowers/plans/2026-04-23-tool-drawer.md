# Tool Drawer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace always-visible inline sub-tool panels with a persistent slide-in drawer triggered by a compact toolbar, eliminating the cluttered feel of the main calculator card.

**Architecture:** A `.tool-toolbar` row of buttons sits below the main result; clicking one opens a `.tool-drawer` div (absolutely positioned within the `.card`) that slides in from the right. PHP emits a `data-open-tool` attribute so the drawer auto-reopens after a form POST. All sub-tool markup moves from the inline panel body into the drawer. No-JS users see sub-tools rendered inline (no regression).

**Tech Stack:** PHP 8, vanilla JS (ES2017+), CSS custom properties (no new dependencies)

---

## File Map

| File | Change |
|------|--------|
| `Subnet-Calculator/assets/app.css` | Add ~70 lines: `.card` overflow-x clip, `.tool-toolbar`, `.tool-trigger`, `.tool-drawer`, bottom-sheet, reduced-motion |
| `Subnet-Calculator/templates/layout.php` | Move sub-tool divs into `.tool-drawer`; add `.tool-toolbar` per panel; add PHP `$open_tool_*` logic |
| `Subnet-Calculator/assets/app.js` | Add `toolDrawer` object (~100 lines); add `js-enabled` class to `<html>` |
| `testing/scripts/playwright_test.py` | Add 7 new test groups for drawer open/close/swap/auto-reopen/mobile/keyboard/no-js |

---

### Task 1: Write Failing Playwright Tests

**Files:**
- Modify: `testing/scripts/playwright_test.py`

These tests define the contract. They fail until Tasks 2–6 are complete.

- [ ] **Step 1: Locate the test file insertion point**

Open `testing/scripts/playwright_test.py`. Find the last test group before the visual regression / docs footer tests (search for `"visual regression"` or `"docs footer"`). Insert the new groups before that block.

- [ ] **Step 2: Add the 7 drawer test groups**

```python
# ── Tool Drawer ───────────────────────────────────────────────────────────────
print_group("Tool Drawer: toolbar renders after IPv4 calculate")
page.goto(APP_URL)
page.fill("#ip", "192.168.1.0")
page.fill("#mask", "/24")
page.click("button[type=submit]")
page.wait_for_selector(".tool-toolbar")
toolbar = page.locator(".tool-toolbar")
assert toolbar.is_visible(), "toolbar not visible after calculate"
assert toolbar.locator(".tool-trigger[data-tool='split']").count() == 1
assert toolbar.locator(".tool-trigger[data-tool='supernet']").count() == 1
assert toolbar.locator(".tool-trigger[data-tool='range']").count() == 1
assert toolbar.locator(".tool-trigger[data-tool='tree']").count() == 1
print_pass()

print_group("Tool Drawer: click Split opens drawer")
page.goto(APP_URL)
page.fill("#ip", "10.0.0.0")
page.fill("#mask", "/24")
page.click("button[type=submit]")
page.wait_for_selector(".tool-toolbar")
page.click(".tool-trigger[data-tool='split']")
page.wait_for_selector(".tool-drawer.open")
assert page.locator(".tool-drawer.open").is_visible(), "drawer did not open"
assert page.locator(".tool-trigger[data-tool='split']").get_attribute("aria-expanded") == "true"
assert page.locator(".tool-panel[data-tool='split']").is_visible()
print_pass()

print_group("Tool Drawer: auto-reopens after Split form submit")
page.goto(APP_URL)
page.fill("#ip", "10.0.0.0")
page.fill("#mask", "/24")
page.click("button[type=submit]")
page.wait_for_selector(".tool-toolbar")
page.click(".tool-trigger[data-tool='split']")
page.fill("input[name='split_prefix']", "/25")
page.click(".splitter-btn")
page.wait_for_selector(".tool-drawer.open")
assert page.locator(".tool-drawer.open").is_visible(), "drawer did not auto-reopen after submit"
assert page.locator(".split-item").count() > 0, "no split results in drawer"
print_pass()

print_group("Tool Drawer: Escape closes drawer, focus returns to trigger")
page.goto(APP_URL)
page.fill("#ip", "10.0.0.0")
page.fill("#mask", "/24")
page.click("button[type=submit]")
page.wait_for_selector(".tool-toolbar")
trigger = page.locator(".tool-trigger[data-tool='split']")
trigger.click()
page.wait_for_selector(".tool-drawer.open")
page.keyboard.press("Escape")
page.wait_for_selector(".tool-drawer:not(.open)")
assert not page.locator(".tool-drawer.open").is_visible(), "drawer still open after Escape"
assert page.evaluate("document.activeElement.dataset.tool") == "split", "focus not returned to trigger"
print_pass()

print_group("Tool Drawer: × button closes drawer")
page.goto(APP_URL)
page.fill("#ip", "10.0.0.0")
page.fill("#mask", "/24")
page.click("button[type=submit]")
page.wait_for_selector(".tool-toolbar")
page.click(".tool-trigger[data-tool='supernet']")
page.wait_for_selector(".tool-drawer.open")
page.click(".tool-drawer-close")
page.wait_for_selector(".tool-drawer:not(.open)")
assert not page.locator(".tool-drawer.open").is_visible(), "drawer still open after × click"
print_pass()

print_group("Tool Drawer: clicking same trigger twice toggles closed")
page.goto(APP_URL)
page.fill("#ip", "10.0.0.0")
page.fill("#mask", "/24")
page.click("button[type=submit]")
page.wait_for_selector(".tool-toolbar")
page.click(".tool-trigger[data-tool='range']")
page.wait_for_selector(".tool-drawer.open")
page.click(".tool-trigger[data-tool='range']")
page.wait_for_selector(".tool-drawer:not(.open)")
assert not page.locator(".tool-drawer.open").is_visible(), "drawer did not toggle closed"
print_pass()

print_group("Tool Drawer: switching tools swaps content without close")
page.goto(APP_URL)
page.fill("#ip", "10.0.0.0")
page.fill("#mask", "/24")
page.click("button[type=submit]")
page.wait_for_selector(".tool-toolbar")
page.click(".tool-trigger[data-tool='split']")
page.wait_for_selector(".tool-drawer.open")
page.click(".tool-trigger[data-tool='tree']")
assert page.locator(".tool-drawer.open").is_visible(), "drawer closed on tool switch"
assert page.locator(".tool-panel[data-tool='tree']").is_visible(), "tree panel not visible"
assert not page.locator(".tool-panel[data-tool='split']").is_visible(), "split panel still visible"
assert page.locator(".tool-trigger[data-tool='tree']").get_attribute("aria-expanded") == "true"
assert page.locator(".tool-trigger[data-tool='split']").get_attribute("aria-expanded") == "false"
print_pass()
```

- [ ] **Step 3: Run the tests to confirm they fail**

```bash
make test-docker 2>&1 | grep -A 3 "Tool Drawer"
```

Expected: FAIL — `tool-toolbar` not found.

- [ ] **Step 4: Commit the failing tests**

```bash
git add testing/scripts/playwright_test.py
git commit -m "test: add failing Playwright tests for tool drawer (v2.8.0)"
```

---

### Task 2: CSS — Drawer, Toolbar, Card Overflow

**Files:**
- Modify: `Subnet-Calculator/assets/app.css` (after line 96, the `.card` block)

- [ ] **Step 1: Add `position: relative; overflow-x: clip` to `.card`**

In `app.css`, find the `.card` block at line 89. Add two properties:

```css
.card {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: 12px;
    padding: 2rem;
    width: 100%;
    max-width: 560px;
    position: relative;
    overflow-x: clip;
}
```

> `overflow-x: clip` (not `hidden`) prevents a horizontal scrollbar during the slide animation without creating a new BFC that would clip absolutely-positioned tooltips.

- [ ] **Step 2: Add toolbar + drawer CSS**

Append the following block to `app.css` after the `.overlap-panel` section (after line ~760):

```css
/* ── Tool Toolbar ─────────────────────────────────────────────────────────── */
.tool-toolbar {
    display: none;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 1rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--color-border);
}
.js-enabled .tool-toolbar { display: flex; }

.tool-trigger {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: 6px;
    color: var(--color-text-subtle);
    cursor: pointer;
    font-size: 0.8rem;
    font-weight: 600;
    padding: 0.4rem 0.7rem;
    transition: background 0.15s, color 0.15s, border-color 0.15s;
    white-space: nowrap;
}
.tool-trigger:hover { background: var(--color-border); color: var(--color-text); }
.tool-trigger.active,
.tool-trigger[aria-expanded="true"] {
    background: var(--color-accent);
    border-color: var(--color-accent);
    color: #fff;
}
.tool-trigger:focus-visible {
    outline: 2px solid var(--color-accent);
    outline-offset: 2px;
}

/* ── Tool Drawer ──────────────────────────────────────────────────────────── */
/* No-JS: sub-tools render stacked inline (drawer is normal block flow) */
.tool-drawer { display: block; }

/* JS: convert to slide-in panel */
.js-enabled .tool-drawer {
    position: absolute;
    right: 0;
    top: 0;
    bottom: 0;
    width: 340px;
    background: var(--color-surface);
    border-left: 1px solid var(--color-border);
    border-radius: 0 12px 12px 0;
    overflow-y: auto;
    transform: translateX(100%);
    transition: transform 200ms ease;
    z-index: 10;
}
.js-enabled .tool-drawer.open { transform: translateX(0); }

/* Bottom sheet on narrow viewports */
@media (width <= 480px) {
    .js-enabled .tool-drawer {
        top: auto;
        left: 0;
        right: 0;
        width: 100%;
        height: 60vh;
        border-left: none;
        border-top: 1px solid var(--color-border);
        border-radius: 12px 12px 0 0;
        transform: translateY(100%);
    }
    .js-enabled .tool-drawer.open { transform: translateY(0); }
}

@media (prefers-reduced-motion: reduce) {
    .js-enabled .tool-drawer { transition: none; }
}

.tool-drawer-header {
    display: none;
    align-items: center;
    justify-content: space-between;
    padding: 0.65rem 0.75rem;
    border-bottom: 1px solid var(--color-border);
    background: var(--color-input-bg);
    position: sticky;
    top: 0;
    z-index: 1;
}
.js-enabled .tool-drawer-header { display: flex; }

.tool-drawer-title {
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--color-text-muted);
}

.tool-drawer-close {
    background: none;
    border: none;
    color: var(--color-text-faint);
    cursor: pointer;
    font-size: 1.1rem;
    line-height: 1;
    padding: 0.2rem 0.4rem;
    border-radius: 4px;
}
.tool-drawer-close:hover { color: var(--color-text); background: var(--color-border); }
.tool-drawer-close:focus-visible {
    outline: 2px solid var(--color-accent);
    outline-offset: 2px;
}

/* No-JS: show all panels stacked; JS: hide all, show only the active panel in an open drawer */
.tool-panel { display: block; }
.js-enabled .tool-panel { display: none; }
.js-enabled .tool-drawer.open .tool-panel.active { display: block; }
```

- [ ] **Step 3: Verify no lint errors**

```bash
npm run lint:css 2>&1 | tail -5
```

Expected: 0 errors.

- [ ] **Step 4: Commit CSS**

```bash
git add Subnet-Calculator/assets/app.css
git commit -m "style: add tool-drawer and tool-toolbar CSS"
```

---

### Task 3: PHP — Restructure IPv4 Panel

**Files:**
- Modify: `Subnet-Calculator/templates/layout.php` (lines ~218–385)

The goal: move `div.splitter` (Split Subnet), `div.overlap-panel` (Supernet), `div.overlap-panel` (Range→CIDR), and `div.overlap-panel` (Tree) out of the inline flow and into a `.tool-drawer` + `.tool-toolbar`.

- [ ] **Step 1: Insert the toolbar + drawer opening after the IPv4 result block**

In `layout.php`, find the line that reads `<?php endif; ?>` after the Split Subnet block (currently ~line 253, the `endif` that closes the `if ($result)` block around results + split subnet). 

Replace the entire block from `<div class="splitter">` (line ~218) through the `<?php endif; ?>` that closes the outer result conditional (line ~253, note: this `endif` closes the `if ($result)` that starts at ~line 183), then continues with the Supernet `<!-- Supernet / Route Summarisation Tool -->` comment.

The new structure replaces everything from line ~218 to line ~385 (end of the Tree panel `</div></div>`) with:

```php
            <?php if ($show_share_bar) : ?>
            <div class="share-bar">
                <span class="share-label">Share</span>
                <code class="share-url"><?= htmlspecialchars($share_url_abs) ?></code>
                <button type="button" class="share-copy" data-copy="<?= htmlspecialchars($share_url) ?>">Copy</button>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php
        $open_tool_ipv4 = null;
        if ($split_result !== null || $split_error !== null) { $open_tool_ipv4 = 'split'; }
        elseif ($supernet_result !== null || $supernet_error !== null) { $open_tool_ipv4 = 'supernet'; }
        elseif ($range_result !== null || $range_error !== null) { $open_tool_ipv4 = 'range'; }
        elseif ($tree_result !== null || $tree_error !== null) { $open_tool_ipv4 = 'tree'; }
        ?>
        <div class="tool-toolbar"<?= $open_tool_ipv4 ? ' data-open-tool="' . $open_tool_ipv4 . '"' : '' ?>>
            <button type="button" class="tool-trigger" data-tool="split" aria-expanded="false">Split Subnet</button>
            <button type="button" class="tool-trigger" data-tool="supernet" aria-expanded="false">Supernet</button>
            <button type="button" class="tool-trigger" data-tool="range" aria-expanded="false">Range&rarr;CIDR</button>
            <button type="button" class="tool-trigger" data-tool="tree" aria-expanded="false">Subnet Tree</button>
        </div>

        <div class="tool-drawer" role="dialog" aria-modal="true" aria-labelledby="drawer-title-ipv4">
            <div class="tool-drawer-header">
                <span class="tool-drawer-title" id="drawer-title-ipv4">Tool</span>
                <button type="button" class="tool-drawer-close" aria-label="Close">&times;</button>
            </div>

            <div class="tool-panel" data-tool="split">
                <div class="splitter">
                    <div class="splitter-title">Split Subnet</div>
                    <form method="post" class="splitter-form">
                        <input type="hidden" name="tab" value="ipv4">
                        <input type="hidden" name="ip" value="<?= htmlspecialchars($input_ip) ?>">
                        <input type="hidden" name="mask" value="<?= htmlspecialchars($input_mask) ?>">
                        <div class="splitter-row">
                            <span class="splitter-label">Split into<?= help_bubble('ipv4-split', 'Enter a prefix length larger than the parent (e.g. /25 splits a /24 into two /25 subnets). The result is capped at the configured maximum.') ?></span>
                            <input type="text" name="split_prefix" class="splitter-input"
                                   placeholder="/25" value="<?= htmlspecialchars($input_split_prefix) ?>"
                                   autocomplete="off" spellcheck="false"
                                   <?= $split_error ? 'aria-invalid="true" aria-describedby="split-error-ipv4"' : '' ?>>
                            <button type="submit" class="splitter-btn">Split</button>
                        </div>
                    </form>
                    <?php if ($split_error) : ?>
                        <div class="error" id="split-error-ipv4"><?= htmlspecialchars($split_error) ?></div>
                    <?php elseif ($split_result && $split_result['showing'] > 0) : ?>
                        <div class="split-list" data-parent="<?= htmlspecialchars($result['cidr'] ?? '') ?>">
                            <button type="button" class="copy-all-btn" data-target="split">Copy All</button>
                            <button type="button" class="ascii-export-btn">Export ASCII</button>
                            <?php foreach ($split_result['subnets'] as $s) : ?>
                                <div class="split-item" tabindex="0" role="button" data-copy="<?= htmlspecialchars($s) ?>">
                                    <span class="split-subnet-text"><?= htmlspecialchars($s) ?></span>
                                    <button type="button" class="subnet-copy" data-copy="<?= htmlspecialchars($s) ?>" aria-label="Copy <?= htmlspecialchars($s) ?>">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($split_result['total'] > $split_result['showing']) : ?>
                                <div class="split-more">+&nbsp;<?= format_number($split_result['total'] - $split_result['showing']) ?> more</div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tool-panel" data-tool="supernet">
                <div class="overlap-panel">
                    <div class="overlap-title">Supernet &amp; Route Summarisation</div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv4">
                        <textarea name="supernet_input" rows="4" class="multi-overlap-input"
                                  placeholder="One IPv4 CIDR per line (max 50):&#10;10.0.0.0/24&#10;10.0.1.0/24"
                                  autocomplete="off" spellcheck="false"><?= htmlspecialchars($supernet_input) ?></textarea>
                        <div class="splitter-row supernet-action-row">
                            <button type="submit" name="supernet_action" value="find" class="splitter-btn">Find Supernet</button><?= help_bubble('supernet-find', 'Finds the smallest single CIDR block that contains all of the listed CIDRs. Useful for aggregating routes into a single summary route.') ?>
                            <button type="submit" name="supernet_action" value="summarise" class="splitter-btn">Summarise Routes</button><?= help_bubble('supernet-summarise', 'Computes the minimal set of non-overlapping CIDRs that exactly covers the listed networks. Unlike Find Supernet, this avoids including addresses outside the input ranges.') ?>
                        </div>
                    </form>
                    <?php if ($supernet_error) : ?>
                        <div class="error"><?= htmlspecialchars($supernet_error) ?></div>
                    <?php elseif ($supernet_result !== null) : ?>
                        <?php if ($supernet_action === 'find') : ?>
                            <div class="overlap-result overlap-contains">
                                <?= htmlspecialchars($supernet_result['supernet'] ?? '') ?>
                            </div>
                        <?php else : ?>
                            <div class="split-list split-list--mt">
                                <button type="button" class="copy-all-btn" data-target="supernet">Copy All</button>
                                <?php foreach ($supernet_result['summaries'] ?? [] as $s) : ?>
                                    <div class="split-item" tabindex="0" role="button" data-copy="<?= htmlspecialchars($s) ?>">
                                        <span class="split-subnet-text"><?= htmlspecialchars($s) ?></span>
                                        <button type="button" class="subnet-copy" data-copy="<?= htmlspecialchars($s) ?>" aria-label="Copy <?= htmlspecialchars($s) ?>">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                                <?php $s_count = count($supernet_result['summaries'] ?? []);
                                      $i_count = count(array_filter(explode("\n", $supernet_input))); ?>
                                <div class="split-more"><?= $s_count ?> prefix<?= $s_count !== 1 ? 'es' : '' ?> from <?= $i_count ?> input<?= $i_count !== 1 ? 's' : '' ?></div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tool-panel" data-tool="range">
                <div class="overlap-panel">
                    <div class="overlap-title">IP Range &rarr; CIDR<?= help_bubble('range-cidr', 'Enter a start and end IPv4 address to get the minimal set of CIDR blocks that exactly covers that range using the greedy largest-aligned-block algorithm.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv4">
                        <div class="overlap-inputs">
                            <input type="text" name="range_start"
                                   value="<?= htmlspecialchars($range_start) ?>"
                                   placeholder="Start IP (e.g. 10.0.0.0)"
                                   autocomplete="off" spellcheck="false"
                                   aria-label="Start IP address">
                            <span class="overlap-vs">to</span>
                            <input type="text" name="range_end"
                                   value="<?= htmlspecialchars($range_end) ?>"
                                   placeholder="End IP (e.g. 10.0.0.255)"
                                   autocomplete="off" spellcheck="false"
                                   aria-label="End IP address">
                            <button type="submit" class="splitter-btn">Convert</button>
                        </div>
                    </form>
                    <?php if ($range_error) : ?>
                        <div class="error"><?= htmlspecialchars($range_error) ?></div>
                    <?php elseif ($range_result !== null) : ?>
                        <div class="split-list split-list--mt">
                            <button type="button" class="copy-all-btn" data-target="range">Copy All</button>
                            <?php foreach ($range_result as $r_cidr) : ?>
                                <div class="split-item" tabindex="0" role="button" data-copy="<?= htmlspecialchars($r_cidr) ?>">
                                    <span class="split-subnet-text"><?= htmlspecialchars($r_cidr) ?></span>
                                    <button type="button" class="subnet-copy" data-copy="<?= htmlspecialchars($r_cidr) ?>" aria-label="Copy <?= htmlspecialchars($r_cidr) ?>">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                            <div class="split-more"><?= count($range_result) ?> CIDR block<?= count($range_result) !== 1 ? 's' : '' ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tool-panel" data-tool="tree">
                <div class="overlap-panel">
                    <div class="overlap-title">Subnet Allocation Tree</div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv4">
                        <div class="form-group tree-form-group">
                            <label for="tree_parent" class="tree-parent-label">Parent CIDR</label>
                            <input type="text" id="tree_parent" name="tree_parent"
                                   value="<?= htmlspecialchars($tree_parent) ?>"
                                   placeholder="10.0.0.0/16" autocomplete="off" spellcheck="false">
                        </div>
                        <textarea name="tree_children" rows="4" class="multi-overlap-input"
                                  placeholder="One child CIDR per line (max 100):&#10;10.0.0.0/24&#10;10.0.1.0/24"
                                  autocomplete="off" spellcheck="false"><?= htmlspecialchars($tree_children) ?></textarea>
                        <div class="tree-action-row">
                            <button type="submit" class="splitter-btn">Build Tree</button>
                        </div>
                    </form>
                    <?php if ($tree_error) : ?>
                        <div class="error"><?= htmlspecialchars($tree_error) ?></div>
                    <?php elseif ($tree_result !== null) : ?>
                        <div class="tree-view">
                            <?php render_tree_node($tree_result); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
```

> Note: the final `</div>` closes `#panel-ipv4`. Verify that the panel div is properly closed — use `php -l` after saving.

- [ ] **Step 2: Run PHP lint**

```bash
php -l Subnet-Calculator/templates/layout.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Run PHPStan**

```bash
phpstan analyse --no-progress --memory-limit=512M 2>&1 | tail -5
```

Expected: 0 errors.

- [ ] **Step 4: Commit IPv4 panel**

```bash
git add Subnet-Calculator/templates/layout.php
git commit -m "refactor: move IPv4 sub-tools into tool-drawer"
```

---

### Task 4: PHP — Restructure IPv6 Panel

**Files:**
- Modify: `Subnet-Calculator/templates/layout.php` (lines ~530–615)

- [ ] **Step 1: Move IPv6 split + ULA into drawer**

In `layout.php`, find the IPv6 panel (`div#panel-ipv6`). Locate:
- The `div.splitter` for split6 (lines ~530–570, inside `if ($result6)`)
- The `div.overlap-panel` for ULA (lines ~573–615)

Replace from the split6 `<div class="splitter">` through the closing `</div>` of the ULA panel with:

```php
            <?php if ($show_share_bar) : ?>
            <div class="share-bar">
                <span class="share-label">Share</span>
                <code class="share-url"><?= htmlspecialchars($share_url_abs) ?></code>
                <button type="button" class="share-copy" data-copy="<?= htmlspecialchars($share_url) ?>">Copy</button>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php
        $open_tool_ipv6 = null;
        if ($split_result6 !== null || $split_error6 !== null) { $open_tool_ipv6 = 'split6'; }
        elseif ($ula_result !== null || $ula_error !== null) { $open_tool_ipv6 = 'ula'; }
        ?>
        <div class="tool-toolbar"<?= $open_tool_ipv6 ? ' data-open-tool="' . $open_tool_ipv6 . '"' : '' ?>>
            <button type="button" class="tool-trigger" data-tool="split6" aria-expanded="false">Split Subnet</button>
            <button type="button" class="tool-trigger" data-tool="ula" aria-expanded="false">ULA Generator</button>
        </div>

        <div class="tool-drawer" role="dialog" aria-modal="true" aria-labelledby="drawer-title-ipv6">
            <div class="tool-drawer-header">
                <span class="tool-drawer-title" id="drawer-title-ipv6">Tool</span>
                <button type="button" class="tool-drawer-close" aria-label="Close">&times;</button>
            </div>

            <div class="tool-panel" data-tool="split6">
                <div class="splitter">
                    <div class="splitter-title">Split Subnet</div>
                    <form method="post" class="splitter-form">
                        <input type="hidden" name="tab" value="ipv6">
                        <input type="hidden" name="ipv6" value="<?= htmlspecialchars($input_ipv6) ?>">
                        <input type="hidden" name="prefix" value="<?= htmlspecialchars($input_prefix) ?>">
                        <div class="splitter-row">
                            <span class="splitter-label">Split into</span>
                            <input type="text" name="split_prefix6" class="splitter-input"
                                   placeholder="/65" value="<?= htmlspecialchars($input_split_prefix6) ?>"
                                   autocomplete="off" spellcheck="false"
                                   <?= $split_error6 ? 'aria-invalid="true" aria-describedby="split-error-ipv6"' : '' ?>>
                            <button type="submit" class="splitter-btn">Split</button>
                        </div>
                    </form>
                    <?php if ($split_error6) : ?>
                        <div class="error" id="split-error-ipv6"><?= htmlspecialchars($split_error6) ?></div>
                    <?php elseif ($split_result6 && $split_result6['showing'] > 0) : ?>
                        <div class="split-list" data-parent="<?= htmlspecialchars($result6['network_cidr'] ?? '') ?>">
                            <button type="button" class="copy-all-btn" data-target="split">Copy All</button>
                            <button type="button" class="ascii-export-btn">Export ASCII</button>
                            <?php foreach ($split_result6['subnets'] as $s) : ?>
                                <div class="split-item" tabindex="0" role="button" data-copy="<?= htmlspecialchars($s) ?>">
                                    <span class="split-subnet-text"><?= htmlspecialchars($s) ?></span>
                                    <button type="button" class="subnet-copy" data-copy="<?= htmlspecialchars($s) ?>" aria-label="Copy <?= htmlspecialchars($s) ?>">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                            <?php
                                $total6   = $split_result6['total'];
                                $showing6 = $split_result6['showing'];
                                $has_more6 = is_numeric($total6) ? ($showing6 < (int)$total6) : true;
                                $more_label6 = is_numeric($total6) ? format_number((int)$total6 - $showing6) : $total6 . ' total';
                            ?>
                            <?php if ($has_more6) : ?>
                                <div class="split-more">+&nbsp;<?= htmlspecialchars($more_label6) ?> more</div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tool-panel" data-tool="ula">
                <div class="overlap-panel">
                    <div class="overlap-title">IPv6 ULA Prefix Generator (RFC 4193)</div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <div class="form-row ula-form-row">
                            <div class="form-group">
                                <label for="ula_global_id">Global ID <span class="label-footnote">(optional 10 hex chars)</span><?= help_bubble('ula-global-id', 'A 40-bit hex value used as the globally unique portion of the ULA prefix (RFC 4193). Leave blank to generate one pseudo-randomly from the current timestamp.') ?></label>
                                <input type="text" id="ula_global_id" name="ula_global_id"
                                       value="<?= htmlspecialchars($ula_global_id_input) ?>"
                                       placeholder="e.g. 1a2b3c4d5e (random if blank)"
                                       autocomplete="off" spellcheck="false" maxlength="10">
                            </div>
                            <div class="ula-generate-wrap">
                                <button type="submit" name="ula_generate" value="1" class="splitter-btn">Generate</button>
                            </div>
                        </div>
                    </form>
                    <?php if ($ula_error) : ?>
                        <div class="error"><?= htmlspecialchars($ula_error) ?></div>
                    <?php elseif ($ula_result !== null) : ?>
                        <div class="ula-result">
                            <div class="overlap-result overlap-contains"><?= htmlspecialchars($ula_result['prefix'] ?? '') ?></div>
                            <div class="ula-meta">
                                <span>Global ID: <code><?= htmlspecialchars($ula_result['global_id'] ?? '') ?></code></span>
                                <span>Available /64s: <strong><?= format_number((int)($ula_result['available_64s'] ?? 0)) ?></strong></span>
                            </div>
                            <?php if (!empty($ula_result['example_64s'])) : ?>
                            <div class="split-list split-list--mt">
                                <button type="button" class="copy-all-btn" data-target="ula">Copy All</button>
                                <?php foreach ($ula_result['example_64s'] as $ex64) : ?>
                                    <div class="split-item" tabindex="0" role="button" data-copy="<?= htmlspecialchars($ex64) ?>">
                                        <span class="split-subnet-text"><?= htmlspecialchars($ex64) ?></span>
                                        <button type="button" class="subnet-copy" data-copy="<?= htmlspecialchars($ex64) ?>" aria-label="Copy <?= htmlspecialchars($ex64) ?>">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
```

- [ ] **Step 2: Run PHP lint and PHPStan**

```bash
php -l Subnet-Calculator/templates/layout.php && phpstan analyse --no-progress --memory-limit=512M 2>&1 | tail -3
```

Expected: `No syntax errors detected` and 0 errors.

- [ ] **Step 3: Commit IPv6 panel**

```bash
git add Subnet-Calculator/templates/layout.php
git commit -m "refactor: move IPv6 sub-tools into tool-drawer"
```

---

### Task 5: PHP — Restructure VLSM Panel

**Files:**
- Modify: `Subnet-Calculator/templates/layout.php` (lines ~738–849)

The VLSM panel has: Session Save/Restore (conditional on `$session_enabled`), Subnet Overlap Checker, Multi-CIDR Overlap Check.

- [ ] **Step 1: Move VLSM sub-tools into drawer**

In `layout.php`, locate the VLSM panel (`#panel-vlsm`). Find the `<?php if ($session_enabled) : ?>` block (Session Save/Restore, lines ~738–778) and the two `div.overlap-panel` blocks that follow (Overlap Checker lines ~780–812, Multi-CIDR lines ~814–849).

Replace from `<?php if ($session_enabled) : ?>` through the closing `</div>` of the Multi-CIDR panel (just before `</div>` closing `#panel-vlsm` at line ~850) with:

```php
        <?php
        $open_tool_vlsm = null;
        if ($session_save_id !== '' || $session_error !== null) { $open_tool_vlsm = 'session'; }
        elseif ($overlap_result !== null || $overlap_error !== null) { $open_tool_vlsm = 'overlap'; }
        elseif ($multi_overlap_result !== null || (isset($multi_overlap_error) && $multi_overlap_error !== null)) { $open_tool_vlsm = 'multi'; }
        ?>
        <div class="tool-toolbar"<?= $open_tool_vlsm ? ' data-open-tool="' . $open_tool_vlsm . '"' : '' ?>>
            <?php if ($session_enabled) : ?>
            <button type="button" class="tool-trigger" data-tool="session" aria-expanded="false">Save Session</button>
            <?php endif; ?>
            <button type="button" class="tool-trigger" data-tool="overlap" aria-expanded="false">Overlap Check</button>
            <button type="button" class="tool-trigger" data-tool="multi" aria-expanded="false">Multi-CIDR Overlap</button>
        </div>

        <div class="tool-drawer" role="dialog" aria-modal="true" aria-labelledby="drawer-title-vlsm">
            <div class="tool-drawer-header">
                <span class="tool-drawer-title" id="drawer-title-vlsm">Tool</span>
                <button type="button" class="tool-drawer-close" aria-label="Close">&times;</button>
            </div>

            <?php if ($session_enabled) : ?>
            <div class="tool-panel" data-tool="session">
                <div class="overlap-panel">
                    <div class="overlap-title">Save &amp; Restore Session<?= help_bubble('vlsm-session', 'Saves your VLSM inputs to the server so you can restore them later via a short link. Sessions expire after the configured TTL. No account required.') ?></div>
                    <p class="session-ttl-notice">Saved sessions expire after <?= (int)$session_ttl_days ?> day<?= (int)$session_ttl_days === 1 ? '' : 's' ?>.</p>
                    <?php if ($session_save_id !== '') : ?>
                        <div class="overlap-result overlap-contains share-bar session-saved-bar">
                            <span class="share-label">Session saved. Share this link:</span>
                            <code class="share-url"><?= htmlspecialchars($share_base_server . $session_save_url) ?></code>
                            <button type="button" class="share-copy"
                                    data-copy="<?= htmlspecialchars($session_save_url) ?>">Copy</button>
                        </div>
                    <?php endif; ?>
                    <?php if ($session_error) : ?>
                        <div class="error"><?= htmlspecialchars($session_error) ?></div>
                    <?php endif; ?>
                    <div class="session-forms">
                        <form method="post" novalidate>
                            <input type="hidden" name="tab" value="vlsm">
                            <input type="hidden" name="vlsm_network" value="<?= htmlspecialchars($vlsm_network) ?>">
                            <input type="hidden" name="vlsm_cidr" value="<?= htmlspecialchars($vlsm_cidr_input) ?>">
                            <?php foreach ($vlsm_requirements as $req) : ?>
                                <input type="hidden" name="vlsm_name[]" value="<?= htmlspecialchars($req['name']) ?>">
                                <input type="hidden" name="vlsm_hosts[]" value="<?= htmlspecialchars((string)$req['hosts']) ?>">
                            <?php endforeach; ?>
                            <button type="submit" name="session_action" value="save" class="splitter-btn">Save Session</button>
                        </form>
                        <form method="get" novalidate>
                            <div class="overlap-inputs">
                                <input type="hidden" name="tab" value="vlsm">
                                <input type="text" name="s"
                                       value="<?= htmlspecialchars($session_load_id) ?>"
                                       placeholder="8-char session ID"
                                       autocomplete="off" spellcheck="false" maxlength="8"
                                       aria-label="Session ID">
                                <button type="submit" class="splitter-btn">Load</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="tool-panel" data-tool="overlap">
                <div class="overlap-panel">
                    <div class="overlap-title">Subnet Overlap Checker<?= help_bubble('overlap-two', 'Compares two CIDRs and reports whether they overlap, one contains the other, are identical, or have no relationship. Supports both IPv4 and IPv6.') ?></div>
                    <form method="post" class="overlap-form" novalidate>
                        <input type="hidden" name="tab" value="vlsm">
                        <div class="overlap-inputs">
                            <input type="text" name="overlap_cidr_a"
                                   value="<?= htmlspecialchars($overlap_cidr_a) ?>"
                                   placeholder="10.0.0.0/24 or 2001:db8::/32" autocomplete="off" spellcheck="false"
                                   aria-label="First subnet CIDR">
                            <span class="overlap-vs">vs</span>
                            <input type="text" name="overlap_cidr_b"
                                   value="<?= htmlspecialchars($overlap_cidr_b) ?>"
                                   placeholder="10.0.0.128/25 or 2001:db8:1::/48" autocomplete="off" spellcheck="false"
                                   aria-label="Second subnet CIDR">
                            <button type="submit" class="splitter-btn">Check</button>
                        </div>
                    </form>
                    <?php if ($overlap_error) : ?>
                        <div class="error"><?= htmlspecialchars($overlap_error) ?></div>
                    <?php elseif ($overlap_result !== null) : ?>
                        <?php
                        $overlap_labels = [
                            'none'         => ['No overlap', 'overlap-none'],
                            'identical'    => ['Identical subnets', 'overlap-identical'],
                            'a_contains_b' => [$overlap_cidr_a . ' contains ' . $overlap_cidr_b, 'overlap-contains'],
                            'b_contains_a' => [$overlap_cidr_b . ' contains ' . $overlap_cidr_a, 'overlap-contains'],
                        ];
                        [$label, $cls] = $overlap_labels[$overlap_result] ?? ['Unknown', ''];
                        ?>
                        <div class="overlap-result <?= htmlspecialchars($cls) ?>"><?= htmlspecialchars($label) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tool-panel" data-tool="multi">
                <div class="overlap-panel multi-overlap-panel">
                    <div class="overlap-title">Multi-CIDR Overlap Check<?= help_bubble('multi-cidr', 'Enter up to 50 IPv4 or IPv6 CIDRs, one per line. The tool reports any pairs that overlap, are identical, or where one contains the other.') ?></div>
                    <form method="post" class="overlap-form" novalidate>
                        <input type="hidden" name="tab" value="vlsm">
                        <textarea name="multi_overlap_input" class="multi-overlap-input"
                                  placeholder="One CIDR per line (max 50):&#10;10.0.0.0/24&#10;10.0.0.128/25&#10;192.168.1.0/24"
                                  rows="4" autocomplete="off" spellcheck="false"><?= htmlspecialchars($multi_overlap_input) ?></textarea>
                        <button type="submit" class="splitter-btn">Check</button>
                    </form>
                    <?php if ($multi_overlap_error) : ?>
                        <div class="error"><?= htmlspecialchars($multi_overlap_error) ?></div>
                    <?php elseif ($multi_overlap_result !== null) : ?>
                        <?php if (count($multi_overlap_result) === 0) : ?>
                            <div class="overlap-result overlap-none">No overlaps detected.</div>
                        <?php else : ?>
                            <ul class="multi-overlap-list">
                                <?php foreach ($multi_overlap_result as $conflict) :
                                    if ($conflict['relation'] === 'identical') {
                                        $rel_label = 'Identical';
                                    } elseif ($conflict['relation'] === 'a_contains_b') {
                                        $rel_label = $conflict['a'] . ' contains ' . $conflict['b'];
                                    } elseif ($conflict['relation'] === 'b_contains_a') {
                                        $rel_label = $conflict['b'] . ' contains ' . $conflict['a'];
                                    } else {
                                        $rel_label = 'Overlap';
                                    }
                                    ?>
                                <li class="overlap-contains">
                                    <code><?= htmlspecialchars($conflict['a']) ?></code> / <code><?= htmlspecialchars($conflict['b']) ?></code>: <?= htmlspecialchars($rel_label) ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
```

- [ ] **Step 2: Run PHP lint and PHPStan**

```bash
php -l Subnet-Calculator/templates/layout.php && phpstan analyse --no-progress --memory-limit=512M 2>&1 | tail -3
```

Expected: no errors.

- [ ] **Step 3: Commit VLSM panel**

```bash
git add Subnet-Calculator/templates/layout.php
git commit -m "refactor: move VLSM sub-tools into tool-drawer"
```

---

### Task 6: JS — toolDrawer Object

**Files:**
- Modify: `Subnet-Calculator/assets/app.js` (append before the service worker block at line ~447)

- [ ] **Step 1: Add the toolDrawer object**

Find the comment `// ── Service Worker registration` (line ~447 in `app.js`). Insert the following block immediately before it:

```javascript
// ── Tool Drawer ────────────────────────────────────────────────────────────
const toolDrawer = {
    _activeTrigger: null,

    init() {
        document.documentElement.classList.add('js-enabled');

        // Hide all tool panels on init (no-JS users see them stacked without this)
        document.querySelectorAll('.tool-panel').forEach(p => { p.hidden = true; });

        // Toolbar button clicks
        document.querySelectorAll('.tool-trigger').forEach(btn => {
            btn.addEventListener('click', () => {
                const panel  = btn.closest('.panel');
                const drawer = panel.querySelector('.tool-drawer');
                const toolId = btn.dataset.tool;
                if (drawer.classList.contains('open') && btn.getAttribute('aria-expanded') === 'true') {
                    this.close(drawer, btn);
                } else if (drawer.classList.contains('open')) {
                    this.swap(drawer, panel, toolId, btn);
                } else {
                    this.open(drawer, panel, toolId, btn);
                }
            });
        });

        // × close button
        document.querySelectorAll('.tool-drawer-close').forEach(btn => {
            btn.addEventListener('click', () => {
                const drawer     = btn.closest('.tool-drawer');
                const activeBtn  = drawer.closest('.panel').querySelector('.tool-trigger[aria-expanded="true"]');
                this.close(drawer, activeBtn);
            });
        });

        // Escape key closes open drawer in the active panel
        document.addEventListener('keydown', e => {
            if (e.key !== 'Escape') return;
            const openDrawer = document.querySelector('.panel.active .tool-drawer.open');
            if (!openDrawer) return;
            const activeBtn = openDrawer.closest('.panel').querySelector('.tool-trigger[aria-expanded="true"]');
            this.close(openDrawer, activeBtn);
        });

        // Tab switch: close any open drawer in the panel being left
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tool-drawer.open').forEach(drawer => {
                    const panel = drawer.closest('.panel');
                    drawer.classList.remove('open');
                    panel.querySelectorAll('.tool-trigger').forEach(t => {
                        t.setAttribute('aria-expanded', 'false');
                        t.classList.remove('active');
                    });
                    drawer.querySelectorAll('.tool-panel').forEach(p => { p.hidden = true; });
                });
            });
        });

        // Auto-open from PHP data-open-tool on page load
        const activePanel = document.querySelector('.panel.active');
        if (activePanel) {
            const toolbar = activePanel.querySelector('.tool-toolbar[data-open-tool]');
            if (toolbar) {
                const toolId  = toolbar.dataset.openTool;
                const trigger = toolbar.querySelector(`.tool-trigger[data-tool="${toolId}"]`);
                const drawer  = activePanel.querySelector('.tool-drawer');
                if (trigger && drawer) this.open(drawer, activePanel, toolId, trigger);
            }
        }
    },

    open(drawer, panel, toolId, trigger) {
        drawer.querySelectorAll('.tool-panel').forEach(p => { p.hidden = true; });
        const target = drawer.querySelector(`.tool-panel[data-tool="${toolId}"]`);
        if (!target) return;
        target.hidden = false;

        const titleEl = drawer.querySelector('.tool-drawer-title');
        if (titleEl) titleEl.textContent = trigger.textContent.trim();

        panel.querySelectorAll('.tool-trigger').forEach(t => {
            t.setAttribute('aria-expanded', 'false');
            t.classList.remove('active');
        });
        trigger.setAttribute('aria-expanded', 'true');
        trigger.classList.add('active');

        drawer.classList.add('open');
        this._activeTrigger = trigger;

        const first = target.querySelector('input, button, textarea, select, [tabindex]:not([tabindex="-1"])');
        if (first) first.focus();
    },

    close(drawer, trigger) {
        drawer.classList.remove('open');
        const panel = drawer.closest('.panel');
        panel.querySelectorAll('.tool-trigger').forEach(t => {
            t.setAttribute('aria-expanded', 'false');
            t.classList.remove('active');
        });
        drawer.querySelectorAll('.tool-panel').forEach(p => { p.hidden = true; });
        if (this._activeTrigger) {
            this._activeTrigger.focus();
            this._activeTrigger = null;
        } else if (trigger) {
            trigger.focus();
        }
    },

    swap(drawer, panel, toolId, trigger) {
        drawer.querySelectorAll('.tool-panel').forEach(p => { p.hidden = true; });
        const target = drawer.querySelector(`.tool-panel[data-tool="${toolId}"]`);
        if (target) target.hidden = false;

        const titleEl = drawer.querySelector('.tool-drawer-title');
        if (titleEl) titleEl.textContent = trigger.textContent.trim();

        panel.querySelectorAll('.tool-trigger').forEach(t => {
            t.setAttribute('aria-expanded', 'false');
            t.classList.remove('active');
        });
        trigger.setAttribute('aria-expanded', 'true');
        trigger.classList.add('active');

        this._activeTrigger = trigger;
        const first = target.querySelector('input, button, textarea, select, [tabindex]:not([tabindex="-1"])');
        if (first) first.focus();
    }
};

toolDrawer.init();
```

- [ ] **Step 2: Run JS lint**

```bash
npm run lint:js 2>&1 | tail -5
```

Expected: 0 errors.

- [ ] **Step 3: Commit JS**

```bash
git add Subnet-Calculator/assets/app.js
git commit -m "feat: add toolDrawer JS object for slide-in sub-tool panel"
```

---

### Task 7: Verify Full Test Suite

**Files:** None modified — verification only.

- [ ] **Step 1: Run the full Docker test suite**

```bash
make test-docker 2>&1 | tail -20
```

Expected: all existing tests pass; all 7 new Tool Drawer test groups pass.

- [ ] **Step 2: If any snapshot tests fail, regenerate baselines**

```bash
rsync -a --delete Subnet-Calculator/ root@192.168.80.15:/opt/container_data/dev.seanmousseau.com/html/claude/subnet-calculator/
scp testing/fixtures/iframe-test.html root@192.168.80.15:/opt/container_data/dev.seanmousseau.com/html/claude/subnet-calculator/
bash -c 'set -a; source ~/.claude/dev-secrets.env; set +a; python3 testing/scripts/playwright_test.py'
```

Then commit updated snapshots:

```bash
git add testing/snapshots/
git commit -m "test: update visual regression baselines for tool drawer layout"
```

- [ ] **Step 3: Run PHP lint suite and static analysis**

```bash
for f in Subnet-Calculator/includes/*.php Subnet-Calculator/api/v1/*.php Subnet-Calculator/api/v1/handlers/*.php; do php -l "$f"; done
phpstan analyse --no-progress --memory-limit=512M 2>&1 | tail -3
```

Expected: all clean.

- [ ] **Step 4: Run JS/CSS lint**

```bash
npm run lint 2>&1 | tail -5
```

Expected: 0 errors.

- [ ] **Step 5: Final commit if any fixes were needed; otherwise done**

The branch is ready for `/release` once all tests are green.
