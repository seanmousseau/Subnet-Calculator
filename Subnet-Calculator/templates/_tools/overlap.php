<?php declare(strict_types=1); ?>
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
                    <?php if (!empty($overlap['error'])) : ?>
                        <div class="error"><?= htmlspecialchars($overlap['error']) ?></div>
                    <?php elseif (isset($overlap['result'])) : ?>
                        <?php
                        $overlap_labels = [
                            'none'         => ['No overlap', 'overlap-none'],
                            'identical'    => ['Identical subnets', 'overlap-identical'],
                            'a_contains_b' => [$overlap_cidr_a . ' contains ' . $overlap_cidr_b, 'overlap-contains'],
                            'b_contains_a' => [$overlap_cidr_b . ' contains ' . $overlap_cidr_a, 'overlap-contains'],
                        ];
                        [$label, $cls] = $overlap_labels[$overlap['result']] ?? ['Unknown', ''];
                        ?>
                        <div class="overlap-result <?= htmlspecialchars($cls) ?>"><?= htmlspecialchars($label) ?></div>
                    <?php endif; ?>
                </div>
            </div>
