<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="supernet">
                <div class="overlap-panel">
                    <div class="overlap-title">Supernet &amp; Route Summarisation</div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv4">
                        <textarea name="supernet_input" rows="4" class="multi-overlap-input"
                                  placeholder="One IPv4 CIDR per line (max 50):&#10;10.0.0.0/24&#10;10.0.1.0/24"
                                  autocomplete="off" spellcheck="false"><?= htmlspecialchars($supernet_input) ?></textarea>
                        <div class="splitter-row supernet-action-row">
                            <button type="submit" name="supernet_action" value="find" class="splitter-btn">Find Supernet</button><?= help_bubble('supernet-find', 'Finds the smallest single CIDR block that contains all of the listed CIDRs. Useful for aggregating routes into a single summary route.') ?>
                            <button type="submit" name="supernet_action" value="summarise" class="splitter-btn">Summarise Routes</button><?= help_bubble('supernet-summarise', 'Computes the minimal set of non-overlapping CIDRs that exactly covers the listed networks. Unlike Find Supernet, this avoids including addresses outside the input ranges.') ?>
                        </div>
                    </form>
                    <?php if (!empty($supernet['error'])) : ?>
                        <div class="error"><?= htmlspecialchars($supernet['error']) ?></div>
                    <?php elseif (isset($supernet['result'])) : ?>
                        <?php
                        $_supernet_inputs = count(array_filter(array_map('trim', explode("\n", $supernet_input))));
                        if ($supernet_action === 'find') {
                            $_supernet_label = 'Supernet: ' . $_supernet_inputs . ' CIDR' . ($_supernet_inputs !== 1 ? 's' : '');
                        } else {
                            $_supernet_outs  = count($supernet['result']['summaries'] ?? []);
                            $_supernet_label = 'Summarise: ' . $_supernet_inputs . ' → ' . $_supernet_outs;
                        }
                        ?>
                        <?php if ($supernet_action === 'find') : ?>
                            <div class="overlap-result overlap-contains"
                                 data-history-source="supernet"
                                 data-history-active="1"
                                 data-history-label="<?= htmlspecialchars($_supernet_label) ?>">
                                <?= htmlspecialchars($supernet['result']['supernet'] ?? '') ?>
                            </div>
                        <?php else : ?>
                            <div class="split-list split-list--mt"
                                 data-history-source="supernet"
                                 data-history-active="1"
                                 data-history-label="<?= htmlspecialchars($_supernet_label) ?>">
                                <button type="button" class="copy-all-btn" data-target="supernet">Copy All</button>
                                <?php foreach ($supernet['result']['summaries'] ?? [] as $s) : ?>
                                    <div class="split-item" tabindex="0" role="button" data-copy="<?= htmlspecialchars($s) ?>">
                                        <span class="split-subnet-text"><?= htmlspecialchars($s) ?></span>
                                        <?= copy_button($s, 'Copy ' . $s) ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php $s_count = count($supernet['result']['summaries'] ?? []);
                                      $i_count = count(array_filter(explode("\n", $supernet_input))); ?>
                                <div class="split-more"><?= $s_count ?> prefix<?= $s_count !== 1 ? 'es' : '' ?> from <?= $i_count ?> input<?= $i_count !== 1 ? 's' : '' ?></div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
