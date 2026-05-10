<?php declare(strict_types=1); ?>
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
                                   <?= !empty($splitter['error']) ? 'aria-invalid="true" aria-describedby="split-error-ipv4"' : '' ?>>
                            <button type="submit" class="splitter-btn">Split</button>
                        </div>
                    </form>
                    <?php if (!empty($splitter['error'])) : ?>
                        <div class="error" id="split-error-ipv4"><?= htmlspecialchars($splitter['error']) ?></div>
                    <?php elseif (isset($splitter['result']) && $splitter['result']['showing'] > 0) : ?>
                        <div class="split-list" data-parent="<?= htmlspecialchars($result['cidr'] ?? '') ?>">
                            <button type="button" class="copy-all-btn" data-target="split">Copy All</button>
                            <button type="button" class="copy-all-btn copy-md-btn" data-target="split4">Copy as Markdown</button>
                            <button type="button" class="copy-all-btn copy-cisco-btn" data-target="split4">Copy as Cisco</button><?= help_bubble('copy-cisco-split4', 'Cisco output is generic IOS-style — one interface stanza per split subnet. Vendor-specific tweaks may be required.') ?>
                            <button type="button" class="ascii-export-btn">Export ASCII</button>
                            <?php foreach ($splitter['result']['subnets'] as $s) : ?>
                                <div class="split-item" tabindex="0" role="button" data-copy="<?= htmlspecialchars($s) ?>">
                                    <span class="split-subnet-text"><?= htmlspecialchars($s) ?></span>
                                    <?= copy_button($s, 'Copy ' . $s) ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($splitter['result']['total'] > $splitter['result']['showing']) : ?>
                                <div class="split-more">+&nbsp;<?= format_number($splitter['result']['total'] - $splitter['result']['showing']) ?> more</div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
