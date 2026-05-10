<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="range6">
                <div class="overlap-panel">
                    <div class="overlap-title">IPv6 Range &rarr; CIDR<?= help_bubble('ipv6-range-cidr', 'Enter a start and end IPv6 address to get the minimal set of CIDR blocks that exactly covers that range. Uses GMP arithmetic so /128-wide ranges work without overflow. Output is capped (default 256 blocks); the cap is configurable via $range_max_cidrs.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <div class="overlap-inputs">
                            <input type="text" name="range6_start"
                                   value="<?= htmlspecialchars($range6_start) ?>"
                                   placeholder="Start IPv6 (e.g. 2001:db8::)"
                                   autocomplete="off" spellcheck="false"
                                   aria-label="Start IPv6 address">
                            <span class="overlap-vs">to</span>
                            <input type="text" name="range6_end"
                                   value="<?= htmlspecialchars($range6_end) ?>"
                                   placeholder="End IPv6 (e.g. 2001:db8::ffff)"
                                   autocomplete="off" spellcheck="false"
                                   aria-label="End IPv6 address">
                            <button type="submit" class="splitter-btn">Convert</button>
                        </div>
                    </form>
                    <?php if (!empty($range6['error'])) : ?>
                        <div class="error"><?= htmlspecialchars($range6['error']) ?></div>
                    <?php elseif (isset($range6['result'])) : ?>
                        <?php if (!empty($range6['warning'])) : ?>
                            <div class="warning"><?= htmlspecialchars($range6['warning']) ?></div>
                        <?php endif; ?>
                        <?php $_range6_label = 'Range: ' . $range6_start . ' → ' . $range6_end; ?>
                        <div class="split-list split-list--mt"
                             data-history-source="range6"
                             data-history-active="1"
                             data-history-label="<?= htmlspecialchars($_range6_label) ?>">
                            <button type="button" class="copy-all-btn" data-target="range6">Copy All</button>
                            <?php foreach ($range6['result'] as $r6_cidr) : ?>
                                <div class="split-item" tabindex="0" role="button" data-copy="<?= htmlspecialchars($r6_cidr) ?>">
                                    <span class="split-subnet-text"><?= htmlspecialchars($r6_cidr) ?></span>
                                    <?= copy_button($r6_cidr, 'Copy ' . $r6_cidr) ?>
                                </div>
                            <?php endforeach; ?>
                            <div class="split-more"><?= count($range6['result']) ?> CIDR block<?= count($range6['result']) !== 1 ? 's' : '' ?><?php if (($range6['total'] ?? null) !== null) : ?> · <?= htmlspecialchars(is_string($range6['total']) ? $range6['total'] : (string)$range6['total']) ?> addresses<?php endif; ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
