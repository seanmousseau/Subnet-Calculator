<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="split6">
                <div class="splitter">
                    <div class="splitter-title">Split Subnet</div>
                    <form method="post" class="splitter-form">
                        <input type="hidden" name="tab" value="ipv6">
                        <input type="hidden" name="ipv6" value="<?= htmlspecialchars($input_ipv6) ?>">
                        <input type="hidden" name="prefix" value="<?= htmlspecialchars($input_prefix) ?>">
                        <div class="splitter-row">
                            <span class="splitter-label">Split into<?= help_bubble('ipv6-split', 'Enter a prefix length larger than the parent (e.g. /65 splits a /64 into two /65 subnets). The result is capped at the configured maximum.') ?></span>
                            <input type="text" name="split_prefix6" class="splitter-input"
                                   placeholder="/65" value="<?= htmlspecialchars($input_split_prefix6) ?>"
                                   autocomplete="off" spellcheck="false"
                                   <?= !empty($splitter6['error']) ? 'aria-invalid="true" aria-describedby="split-error-ipv6"' : '' ?>>
                            <button type="submit" class="splitter-btn">Split</button>
                        </div>
                    </form>
                    <?php if (!empty($splitter6['error'])) : ?>
                        <div class="error" id="split-error-ipv6"><?= htmlspecialchars($splitter6['error']) ?></div>
                    <?php elseif (isset($splitter6['result']) && $splitter6['result']['showing'] > 0) : ?>
                        <div class="split-list" data-parent="<?= htmlspecialchars($result6['network_cidr'] ?? '') ?>">
                            <button type="button" class="copy-all-btn" data-target="split">Copy All</button>
                            <button type="button" class="copy-all-btn copy-md-btn" data-target="split6">Copy as Markdown</button>
                            <button type="button" class="copy-all-btn copy-cisco-btn" data-target="split6">Copy as Cisco</button><?= help_bubble('copy-cisco-split6', 'Cisco output is generic IOS-style — one interface stanza per split IPv6 subnet using ipv6 address. Vendor-specific tweaks may be required.') ?>
                            <button type="button" class="ascii-export-btn">Export ASCII</button>
                            <?php foreach ($splitter6['result']['subnets'] as $s) : ?>
                                <div class="split-item" tabindex="0" role="button" data-copy="<?= htmlspecialchars($s) ?>">
                                    <span class="split-subnet-text"><?= htmlspecialchars($s) ?></span>
                                    <?= copy_button($s, 'Copy ' . $s) ?>
                                </div>
                            <?php endforeach; ?>
                            <?php
                                $total6   = $splitter6['result']['total'];
                                $showing6 = $splitter6['result']['showing'];
                                $has_more6 = is_numeric($total6) ? ($showing6 < (int)$total6) : true;
                                $more_label6 = is_numeric($total6) ? format_number((int)$total6 - $showing6) . ' more' : $total6 . ' more';
                            ?>
                            <?php if ($has_more6) : ?>
                                <div class="split-more">+&nbsp;<?= htmlspecialchars($more_label6) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
