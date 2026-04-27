<?php
/**
 * Shared diff result renderer.
 *
 * Expects $diff_result to be in scope as:
 *   array{
 *     added: list<string>,
 *     removed: list<string>,
 *     unchanged: list<string>,
 *     changed: list<array{from: string, to: string, reason: string}>
 *   }
 */
$_diff_added     = $diff_result['added']     ?? [];
$_diff_removed   = $diff_result['removed']   ?? [];
$_diff_unchanged = $diff_result['unchanged'] ?? [];
$_diff_changed   = $diff_result['changed']   ?? [];
$_diff_total     = count($_diff_added) + count($_diff_removed) + count($_diff_unchanged) + count($_diff_changed);
?>
<div class="diff-results">
    <div class="diff-summary">
        <span class="diff-summary-pill diff-summary-pill--added"><?= count($_diff_added) ?> added</span>
        <span class="diff-summary-pill diff-summary-pill--removed"><?= count($_diff_removed) ?> removed</span>
        <span class="diff-summary-pill diff-summary-pill--changed"><?= count($_diff_changed) ?> changed</span>
        <span class="diff-summary-pill diff-summary-pill--unchanged"><?= count($_diff_unchanged) ?> unchanged</span>
    </div>

    <div class="diff-group diff-group--added<?= $_diff_added === [] ? ' diff-group--empty' : '' ?>">
        <div class="diff-group-title">Added (<?= count($_diff_added) ?>)</div>
        <?php if ($_diff_added === []) : ?>
            <div class="diff-empty">None</div>
        <?php else : ?>
            <ul class="diff-list">
                <?php foreach ($_diff_added as $cidr) : ?>
                    <li class="diff-item"><span class="diff-marker" aria-hidden="true">+</span><code><?= htmlspecialchars($cidr) ?></code></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="diff-group diff-group--removed<?= $_diff_removed === [] ? ' diff-group--empty' : '' ?>">
        <div class="diff-group-title">Removed (<?= count($_diff_removed) ?>)</div>
        <?php if ($_diff_removed === []) : ?>
            <div class="diff-empty">None</div>
        <?php else : ?>
            <ul class="diff-list">
                <?php foreach ($_diff_removed as $cidr) : ?>
                    <li class="diff-item"><span class="diff-marker" aria-hidden="true">&minus;</span><code><?= htmlspecialchars($cidr) ?></code></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="diff-group diff-group--changed<?= $_diff_changed === [] ? ' diff-group--empty' : '' ?>">
        <div class="diff-group-title">Changed (<?= count($_diff_changed) ?>)</div>
        <?php if ($_diff_changed === []) : ?>
            <div class="diff-empty">None</div>
        <?php else : ?>
            <ul class="diff-list">
                <?php foreach ($_diff_changed as $row) : ?>
                    <li class="diff-item">
                        <span class="diff-marker" aria-hidden="true">~</span>
                        <code><?= htmlspecialchars($row['from']) ?></code>
                        <span class="diff-arrow" aria-hidden="true">&rarr;</span>
                        <code><?= htmlspecialchars($row['to']) ?></code>
                        <span class="diff-reason"><?= htmlspecialchars($row['reason']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="diff-group diff-group--unchanged<?= $_diff_unchanged === [] ? ' diff-group--empty' : '' ?>">
        <div class="diff-group-title">Unchanged (<?= count($_diff_unchanged) ?>)</div>
        <?php if ($_diff_unchanged === []) : ?>
            <div class="diff-empty">None</div>
        <?php else : ?>
            <ul class="diff-list">
                <?php foreach ($_diff_unchanged as $cidr) : ?>
                    <li class="diff-item"><span class="diff-marker" aria-hidden="true">=</span><code><?= htmlspecialchars($cidr) ?></code></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <?php if ($_diff_total === 0) : ?>
        <div class="split-more">No CIDRs to diff.</div>
    <?php endif; ?>
</div>
