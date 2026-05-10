<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="diff">
                <div class="overlap-panel">
                    <div class="overlap-title">Subnet Diff<?= help_bubble('ipv4-diff', 'Compares two CIDR lists. Inputs are canonicalised (host bits zeroed, IPv6 lowercased) before comparison. Reports added, removed, unchanged, and changed (same network address but different prefix length). Mixed IPv4/IPv6 inputs are allowed. Cap: 1000 entries per side.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv4">
                        <div class="lookup-form-grid">
                            <label for="diff_before_v4" class="lookup-form-label">Before <span class="lookup-form-hint">(one CIDR per line)</span></label>
                            <textarea id="diff_before_v4" name="diff_before" rows="4" class="multi-overlap-input"
                                      placeholder="10.0.0.0/24&#10;10.0.1.0/24&#10;10.0.2.0/24"
                                      autocomplete="off" spellcheck="false"><?= htmlspecialchars($active_tab === 'ipv4' ? $diff_before_input : '') ?></textarea>
                            <label for="diff_after_v4" class="lookup-form-label">After <span class="lookup-form-hint">(one CIDR per line)</span></label>
                            <textarea id="diff_after_v4" name="diff_after" rows="4" class="multi-overlap-input"
                                      placeholder="10.0.0.0/23&#10;10.0.2.0/24&#10;10.0.3.0/24"
                                      autocomplete="off" spellcheck="false"><?= htmlspecialchars($active_tab === 'ipv4' ? $diff_after_input : '') ?></textarea>
                        </div>
                        <div class="splitter-row">
                            <button type="submit" class="splitter-btn">Diff</button>
                        </div>
                    </form>
                    <?php if ($active_tab === 'ipv4' && !empty($diff['error'])) : ?>
                        <div class="error"><?= htmlspecialchars($diff['error']) ?></div>
                    <?php elseif ($active_tab === 'ipv4' && isset($diff['result'])) : ?>
                        <?php $diff_result = $diff['result']; include __DIR__ . '/../_diff_result.php'; ?>
                    <?php endif; ?>
                </div>
            </div>
