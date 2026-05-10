<?php declare(strict_types=1); ?>
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
                    <?php if (!empty($multi_overlap['error'])) : ?>
                        <div class="error"><?= htmlspecialchars($multi_overlap['error']) ?></div>
                    <?php elseif (isset($multi_overlap['result'])) : ?>
                        <?php if (count($multi_overlap['result']) === 0) : ?>
                            <div class="overlap-result overlap-none">No overlaps detected.</div>
                        <?php else : ?>
                            <ul class="multi-overlap-list">
                                <?php foreach ($multi_overlap['result'] as $conflict) :
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
