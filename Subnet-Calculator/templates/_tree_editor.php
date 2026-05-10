<?php
/**
 * Tree-editor partial (#302, v3.0.0 PR3b).
 *
 * Rendered inside the IPv4 Tools drawer as a `<div class="tool-panel"
 * data-tool="tree-editor">`.  All editing state lives client-side; this
 * partial provides only the static markup that app.js attaches behaviour to.
 *
 * Variables expected from request.php / layout.php:
 *   $tree_editor_initial_cidr (string, may be empty)
 *
 * @var string $tree_editor_initial_cidr
 */
?>
<div class="overlap-panel tree-editor-panel">
    <div class="overlap-title">Subnet Tree Editor<?= help_bubble('tree-editor', 'Interactive subnet planner. Click a node to split it. Drag onto a sibling to merge. Edits autosave to your browser; use Save Session to share across devices.') ?></div>

    <form id="tree-editor-init" class="tree-editor-init">
        <label for="tree_editor_cidr" class="tree-parent-label">Root CIDR</label>
        <input type="text" id="tree_editor_cidr" name="tree_editor_cidr"
               value="<?= htmlspecialchars($tree_editor_initial_cidr ?? '') ?>"
               placeholder="10.0.0.0/24" autocomplete="off" spellcheck="false">
        <button type="submit" class="splitter-btn">Start Editing</button>
    </form>

    <form class="tree-editor-init" data-role="tree-load-form">
        <label for="tree_editor_session_id" class="tree-parent-label">Or load saved session</label>
        <input type="text" id="tree_editor_session_id" data-role="tree-load-id"
               placeholder="16-char session ID" autocomplete="off" spellcheck="false"
               pattern="[0-9a-f]{16}" maxlength="16">
        <button type="submit" class="splitter-btn">Load Session</button>
    </form>

    <div class="tree-editor" hidden aria-live="polite">
        <div class="tree-editor-toolbar" role="toolbar" aria-label="Tree editor controls">
            <button type="button" class="tree-editor-btn" data-action="undo" aria-label="Undo (Ctrl+Z)" title="Undo (Ctrl+Z)" disabled>Undo</button>
            <button type="button" class="tree-editor-btn" data-action="redo" aria-label="Redo (Ctrl+Shift+Z)" title="Redo (Ctrl+Shift+Z)" disabled>Redo</button>
            <span class="tree-editor-spacer"></span>
            <button type="button" class="tree-editor-btn" data-action="save-session">Save Session</button>
            <button type="button" class="tree-editor-btn" data-action="copy-cidr">Copy CIDR</button>
            <button type="button" class="tree-editor-btn" data-action="copy-md">Copy Markdown</button>
            <button type="button" class="tree-editor-btn" data-action="copy-cisco">Copy Cisco</button>
            <button type="button" class="tree-editor-btn" data-action="download-csv">CSV</button>
            <button type="button" class="tree-editor-btn" data-action="download-json">JSON</button>
            <button type="button" class="tree-editor-btn" data-action="share-url">Share URL</button>
            <button type="button" class="tree-editor-btn" data-action="diff">Diff</button>
            <button type="button" class="tree-editor-btn" data-action="apply-template">Apply Template</button>
            <span class="tree-editor-spacer"></span>
            <button type="button" class="tree-editor-btn tree-editor-btn-danger" data-action="reset">Reset</button>
        </div>

        <div class="tree-editor-status" data-role="status" aria-live="polite"></div>
        <div class="tree-editor-canvas" data-role="canvas"></div>
        <div class="tree-editor-share" data-role="share" hidden>
            <span class="tree-editor-share-label">Share</span>
            <code class="tree-editor-share-url" data-role="share-url"></code>
            <button type="button" class="tree-editor-share-copy" data-action="copy-share">Copy</button>
        </div>
    </div>

    <!-- Split picker modal -->
    <div class="tree-modal" data-role="split-modal" role="dialog" aria-modal="true" aria-labelledby="tree-split-title" hidden>
        <div class="tree-modal-backdrop" data-role="split-cancel"></div>
        <div class="tree-modal-card">
            <h3 id="tree-split-title">Split <span data-role="split-cidr"></span></h3>
            <p class="tree-modal-help">Choose how many equal children to create.</p>
            <div class="tree-modal-actions">
                <button type="button" class="splitter-btn" data-split-into="2">2</button>
                <button type="button" class="splitter-btn" data-split-into="4">4</button>
                <button type="button" class="splitter-btn" data-split-into="8">8</button>
                <button type="button" class="splitter-btn" data-split-into="16">16</button>
            </div>
            <button type="button" class="tree-modal-cancel" data-role="split-cancel">Cancel</button>
        </div>
    </div>

    <!-- Rename / notes modal -->
    <div class="tree-modal" data-role="rename-modal" role="dialog" aria-modal="true" aria-labelledby="tree-rename-title" hidden>
        <div class="tree-modal-backdrop" data-role="rename-cancel"></div>
        <div class="tree-modal-card">
            <h3 id="tree-rename-title">Edit <span data-role="rename-cidr"></span></h3>
            <label class="tree-modal-label">
                Name
                <input type="text" data-role="rename-name" maxlength="128" autocomplete="off" spellcheck="false">
            </label>
            <label class="tree-modal-label">
                Notes
                <textarea data-role="rename-notes" maxlength="1024" rows="3" autocomplete="off" spellcheck="false"></textarea>
            </label>
            <div class="tree-modal-actions">
                <button type="button" class="splitter-btn" data-role="rename-save">Save</button>
                <button type="button" class="tree-modal-cancel" data-role="rename-cancel">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Multi-tree diff modal (#322, v3.1.0) -->
    <div class="tree-modal" data-role="diff-modal" role="dialog" aria-modal="true" aria-labelledby="tree-diff-title" hidden>
        <div class="tree-modal-backdrop" data-role="diff-cancel"></div>
        <div class="tree-modal-card tree-diff-card">
            <h3 id="tree-diff-title">Compare two trees</h3>
            <p class="tree-modal-help">Pick two saved trees and compare added, removed, and changed subnets.</p>
            <div class="tree-diff-error" data-role="diff-error" role="alert" hidden></div>
            <div class="tree-diff-inputs" data-role="diff-inputs">
                <?php foreach (['a' => 'Tree A (before)', 'b' => 'Tree B (after)'] as $side => $label): ?>
                <fieldset class="tree-diff-source" data-side="<?= htmlspecialchars($side) ?>">
                    <legend><?= htmlspecialchars($label) ?></legend>
                    <div class="tree-diff-source-tabs" role="tablist" aria-label="<?= htmlspecialchars($label) ?> source">
                        <button type="button" class="tree-editor-btn" role="tab" aria-selected="true" data-source-tab="paste">Paste JSON</button>
                        <button type="button" class="tree-editor-btn" role="tab" aria-selected="false" data-source-tab="url">Share URL</button>
                        <button type="button" class="tree-editor-btn" role="tab" aria-selected="false" data-source-tab="draft">Current draft</button>
                    </div>
                    <textarea class="tree-diff-source-paste" data-source-pane="paste" rows="6" placeholder='{"cidr":"10.0.0.0/24","children":[…]}' autocomplete="off" spellcheck="false"></textarea>
                    <input type="text" class="tree-diff-source-url" data-source-pane="url" placeholder="Paste a Share URL or ?tree=… fragment" autocomplete="off" spellcheck="false" hidden>
                    <div class="tree-diff-source-draft" data-source-pane="draft" hidden>Use the autosaved draft for the current root CIDR.</div>
                </fieldset>
                <?php endforeach; ?>
            </div>
            <div class="tree-modal-actions" data-role="diff-actions">
                <button type="button" class="splitter-btn" data-role="diff-compare">Compare</button>
                <button type="button" class="tree-modal-cancel" data-role="diff-cancel">Cancel</button>
            </div>

            <div class="tree-diff-result" data-role="diff-result" hidden>
                <div class="tree-diff-result-toolbar">
                    <button type="button" class="tree-editor-btn" data-role="diff-back">&larr; Back</button>
                    <button type="button" class="tree-editor-btn" data-role="diff-copy-md">Copy diff as Markdown</button>
                    <span class="tree-diff-summary" data-role="diff-summary"></span>
                </div>
                <div class="tree-diff-canvas" data-role="diff-canvas"></div>
                <div class="tree-diff-legend" role="note">
                    <span class="tree-diff-legend-item" data-diff="added">added</span>
                    <span class="tree-diff-legend-item" data-diff="removed">removed</span>
                    <span class="tree-diff-legend-item" data-diff="changed">changed</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Apply Template picker modal (#323, v3.1.0) -->
    <div class="tree-modal" data-role="preset-modal" role="dialog" aria-modal="true" aria-labelledby="tree-preset-title" hidden>
        <div class="tree-modal-backdrop" data-role="preset-cancel"></div>
        <div class="tree-modal-card tree-preset-card">
            <h3 id="tree-preset-title">Apply Template</h3>
            <p class="tree-modal-help">Pick a preset, then confirm or customise the root CIDR before it's applied to the editor.</p>
            <div class="tree-preset-error" data-role="preset-error" role="alert" hidden></div>

            <!-- Step 1: list of presets (role=listbox) -->
            <div class="tree-preset-list" data-role="preset-list" role="listbox" aria-label="Subnet templates"></div>

            <!-- Step 2: confirm root CIDR -->
            <div class="tree-preset-confirm" data-role="preset-confirm" hidden>
                <div class="tree-preset-confirm-meta" data-role="preset-confirm-meta"></div>
                <label class="tree-modal-label">
                    Root CIDR
                    <input type="text" data-role="preset-root-cidr" autocomplete="off" spellcheck="false">
                </label>
                <div class="tree-modal-actions">
                    <button type="button" class="splitter-btn" data-role="preset-apply">Apply</button>
                    <button type="button" class="tree-editor-btn" data-role="preset-back">&larr; Back</button>
                    <button type="button" class="tree-modal-cancel" data-role="preset-cancel">Cancel</button>
                </div>
            </div>

            <p class="tree-modal-help tree-preset-extend" data-role="preset-list-footer">
                Drop a JSON file into <code>data/tree-presets/</code> to add your own.
                <a href="https://docs.subnetcalculator.app/tree/#templates" target="_blank" rel="noopener">Schema reference</a>.
            </p>
            <div class="tree-modal-actions" data-role="preset-list-actions">
                <button type="button" class="tree-modal-cancel" data-role="preset-cancel">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Mobile action sheet (touch only via @media (hover: none)) -->
    <div class="tree-action-sheet" data-role="action-sheet" role="dialog" aria-modal="true" aria-labelledby="tree-sheet-title" hidden>
        <div class="tree-modal-backdrop" data-role="sheet-cancel"></div>
        <div class="tree-action-sheet-card">
            <h3 id="tree-sheet-title">Node <span data-role="sheet-cidr"></span></h3>
            <button type="button" class="tree-action-sheet-btn" data-sheet-action="split">Split</button>
            <button type="button" class="tree-action-sheet-btn" data-sheet-action="merge">Merge with sibling</button>
            <button type="button" class="tree-action-sheet-btn" data-sheet-action="rename">Rename / Notes</button>
            <button type="button" class="tree-action-sheet-btn tree-action-sheet-btn-cancel" data-role="sheet-cancel">Cancel</button>
        </div>
    </div>
</div>
