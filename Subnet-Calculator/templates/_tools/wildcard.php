<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="wildcard">
                <div class="overlap-panel">
                    <div class="overlap-title">Wildcard &harr; CIDR<?= help_bubble('wildcard-cidr', 'Bidirectional Cisco-style converter. Enter a wildcard mask (e.g. 0.0.0.255) to get its CIDR prefix, or a prefix (/24 or 24) to get the wildcard. Non-contiguous masks are rejected.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv4">
                        <div class="overlap-inputs">
                            <input type="text" id="wildcard_input" name="wildcard_input"
                                   value="<?= htmlspecialchars($wildcard_input) ?>"
                                   placeholder="/24  or  0.0.0.255"
                                   autocomplete="off" spellcheck="false"
                                   aria-label="CIDR prefix or wildcard mask">
                            <button type="submit" class="splitter-btn">Convert</button>
                        </div>
                    </form>
                    <?php if (!empty($wildcard['error'])) : ?>
                        <div class="error wildcard-error"><?= htmlspecialchars($wildcard['error']) ?></div>
                    <?php elseif (isset($wildcard['result'])) : ?>
                        <?php $_wildcard_label = 'Wildcard: ' . $wildcard_input; ?>
                        <div class="split-list split-list--mt"
                             data-history-source="wildcard"
                             data-history-active="1"
                             data-history-label="<?= htmlspecialchars($_wildcard_label) ?>">
                            <div class="split-item" tabindex="0" role="button"
                                 data-copy="<?= htmlspecialchars($wildcard['result']['cidr']) ?>">
                                <span class="split-subnet-text" id="wildcard-result-cidr">CIDR: <?= htmlspecialchars($wildcard['result']['cidr']) ?></span>
                                <?= copy_button($wildcard['result']['cidr'], 'Copy CIDR ' . $wildcard['result']['cidr']) ?>
                            </div>
                            <div class="split-item" tabindex="0" role="button"
                                 data-copy="<?= htmlspecialchars($wildcard['result']['wildcard']) ?>">
                                <span class="split-subnet-text" id="wildcard-result-mask">Wildcard: <?= htmlspecialchars($wildcard['result']['wildcard']) ?></span>
                                <?= copy_button($wildcard['result']['wildcard'], 'Copy wildcard ' . $wildcard['result']['wildcard']) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
