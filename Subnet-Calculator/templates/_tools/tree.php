<?php declare(strict_types=1); ?>
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
                    <?php if (!empty($tree['error'])) : ?>
                        <div class="error"><?= htmlspecialchars($tree['error']) ?></div>
                    <?php elseif (isset($tree['result'])) : ?>
                        <?php $_tree_label = 'Tree: ' . (string)($tree['result']['cidr'] ?? $tree_parent); ?>
                        <div class="tree-view"
                             data-history-source="tree"
                             data-history-active="1"
                             data-history-label="<?= htmlspecialchars($_tree_label) ?>">
                            <?php
                            /**
                             * @param array<string, mixed> $node
                             * @param int $depth
                             */
                            function render_tree_node(array $node, int $depth = 0): void
                            {
                                $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $depth);
                                $cidr   = htmlspecialchars((string)($node['cidr'] ?? ''));
                                echo '<div class="tree-node">';
                                echo $indent . '<span class="tree-cidr" tabindex="0" role="button" data-copy="' . $cidr . '" title="Click to copy">';
                                echo '<code>' . $cidr . '</code>';
                                echo '</span>';
                                echo '</div>';
                                $gaps = $node['gaps'] ?? [];
                                foreach ((array)($node['children'] ?? []) as $child) {
                                    if (is_array($child)) {
                                        render_tree_node($child, $depth + 1);
                                    }
                                }
                                foreach ((array)$gaps as $gap) {
                                    $gap_safe = htmlspecialchars((string)$gap);
                                    echo '<div class="tree-node tree-gap">';
                                    echo $indent . '&nbsp;&nbsp;&nbsp;&nbsp;<code>' . $gap_safe . '</code> <span class="tree-free-label">(free)</span>';
                                    echo '</div>';
                                }
                            }
                            render_tree_node($tree['result']);
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
