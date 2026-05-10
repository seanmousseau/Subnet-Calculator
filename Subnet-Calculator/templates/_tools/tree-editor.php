<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="tree-editor">
                <?php
                $tree_editor_initial_cidr = $result['cidr'] ?? '';
                require __DIR__ . '/../_tree_editor.php';
                ?>
            </div>
