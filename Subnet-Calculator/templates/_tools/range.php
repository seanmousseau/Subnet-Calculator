<?php declare(strict_types=1); ?>
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
                    <?php if (!empty($range['error'])) : ?>
                        <div class="error"><?= htmlspecialchars($range['error']) ?></div>
                    <?php elseif (isset($range['result'])) : ?>
                        <?php $_range_label = 'Range: ' . $range_start . ' → ' . $range_end; ?>
                        <div class="split-list split-list--mt"
                             data-history-source="range"
                             data-history-active="1"
                             data-history-label="<?= htmlspecialchars($_range_label) ?>">
                            <button type="button" class="copy-all-btn" data-target="range">Copy All</button>
                            <?php foreach ($range['result'] as $r_cidr) : ?>
                                <div class="split-item" tabindex="0" role="button" data-copy="<?= htmlspecialchars($r_cidr) ?>">
                                    <span class="split-subnet-text"><?= htmlspecialchars($r_cidr) ?></span>
                                    <?= copy_button($r_cidr, 'Copy ' . $r_cidr) ?>
                                </div>
                            <?php endforeach; ?>
                            <div class="split-more"><?= count($range['result']) ?> CIDR block<?= count($range['result']) !== 1 ? 's' : '' ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
