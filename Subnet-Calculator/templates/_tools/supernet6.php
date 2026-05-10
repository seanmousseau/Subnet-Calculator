<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="supernet6">
                <div class="overlap-panel">
                    <div class="overlap-title">IPv6 Supernet &amp; Route Summarisation<?= help_bubble('ipv6-supernet', 'IPv6 counterpart to the IPv4 Supernet tool. Find returns the smallest CIDR enclosing all listed IPv6 prefixes; Summarise reduces the list to the minimal covering IPv6 prefixes. Pure GMP — works for /128-wide inputs. Maximum 50 CIDRs per check.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <label for="supernet6_input" class="sr-only">IPv6 CIDR list (one per line)</label>
                        <textarea id="supernet6_input" name="supernet6_input" rows="4" class="multi-overlap-input"
                                  placeholder="One IPv6 CIDR per line (max 50):&#10;2001:db8::/64&#10;2001:db8:0:1::/64"
                                  autocomplete="off" spellcheck="false"><?= htmlspecialchars($supernet6_input) ?></textarea>
                        <div class="splitter-row supernet-action-row">
                            <button type="submit" name="supernet6_action" value="find" class="splitter-btn">Find Supernet</button><?= help_bubble('supernet6-find', 'Finds the smallest single IPv6 CIDR block that contains all of the listed CIDRs. Useful for aggregating IPv6 routes into a single summary route.') ?>
                            <button type="submit" name="supernet6_action" value="summarise" class="splitter-btn">Summarise Routes</button><?= help_bubble('supernet6-summarise', 'Computes the minimal set of non-overlapping IPv6 CIDRs that exactly covers the listed networks. Unlike Find Supernet, this avoids including addresses outside the input ranges.') ?>
                        </div>
                    </form>
                    <?php if (!empty($supernet6['error'])) : ?>
                        <div class="error"><?= htmlspecialchars($supernet6['error']) ?></div>
                    <?php elseif (isset($supernet6['result'])) : ?>
                        <?php
                        $_supernet6_inputs = count(array_filter(array_map('trim', explode("\n", $supernet6_input))));
                        if ($supernet6_action === 'find') {
                            $_supernet6_label = 'Supernet6: ' . $_supernet6_inputs . ' CIDR' . ($_supernet6_inputs !== 1 ? 's' : '');
                        } else {
                            $_supernet6_outs  = count($supernet6['result']['summaries'] ?? []);
                            $_supernet6_label = 'Summarise6: ' . $_supernet6_inputs . ' → ' . $_supernet6_outs;
                        }
                        ?>
                        <?php if ($supernet6_action === 'find') : ?>
                            <div class="overlap-result overlap-contains"
                                 data-history-source="supernet6"
                                 data-history-active="1"
                                 data-history-label="<?= htmlspecialchars($_supernet6_label) ?>">
                                <?= htmlspecialchars($supernet6['result']['supernet'] ?? '') ?>
                            </div>
                        <?php else : ?>
                            <div class="split-list split-list--mt"
                                 data-history-source="supernet6"
                                 data-history-active="1"
                                 data-history-label="<?= htmlspecialchars($_supernet6_label) ?>">
                                <button type="button" class="copy-all-btn" data-target="supernet6">Copy All</button>
                                <?php foreach ($supernet6['result']['summaries'] ?? [] as $s6) : ?>
                                    <div class="split-item" tabindex="0" role="button" data-copy="<?= htmlspecialchars($s6) ?>">
                                        <span class="split-subnet-text"><?= htmlspecialchars($s6) ?></span>
                                        <?= copy_button($s6, 'Copy ' . $s6) ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php $s6_count = count($supernet6['result']['summaries'] ?? []);
                                      $i6_count = count(array_filter(explode("\n", $supernet6_input))); ?>
                                <div class="split-more"><?= $s6_count ?> prefix<?= $s6_count !== 1 ? 'es' : '' ?> from <?= $i6_count ?> input<?= $i6_count !== 1 ? 's' : '' ?></div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
