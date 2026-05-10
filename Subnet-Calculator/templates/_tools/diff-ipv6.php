<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="diff">
                <div class="overlap-panel">
                    <div class="overlap-title">Subnet Diff<?= help_bubble('ipv6-diff', 'Compares two CIDR lists. Inputs are canonicalised (host bits zeroed, IPv6 lowercased) before comparison. Reports added, removed, unchanged, and changed (same network address but different prefix length). Mixed IPv4/IPv6 inputs are allowed. Cap: 1000 entries per side.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <div class="lookup-form-grid">
                            <label for="diff_before_v6" class="lookup-form-label">Before <span class="lookup-form-hint">(one CIDR per line)</span></label>
                            <textarea id="diff_before_v6" name="diff_before" rows="4" class="multi-overlap-input"
                                      placeholder="2001:db8::/48&#10;2001:db8:1::/48&#10;2001:db8:2::/48"
                                      autocomplete="off" spellcheck="false"><?= htmlspecialchars($active_tab === 'ipv6' ? $diff_before_input : '') ?></textarea>
                            <label for="diff_after_v6" class="lookup-form-label">After <span class="lookup-form-hint">(one CIDR per line)</span></label>
                            <textarea id="diff_after_v6" name="diff_after" rows="4" class="multi-overlap-input"
                                      placeholder="2001:db8::/47&#10;2001:db8:2::/48&#10;2001:db8:3::/48"
                                      autocomplete="off" spellcheck="false"><?= htmlspecialchars($active_tab === 'ipv6' ? $diff_after_input : '') ?></textarea>
                        </div>
                        <div class="splitter-row">
                            <button type="submit" class="splitter-btn">Diff</button>
                        </div>
                    </form>
                    <?php if ($active_tab === 'ipv6' && !empty($diff['error'])) : ?>
                        <div class="error"><?= htmlspecialchars($diff['error']) ?></div>
                    <?php elseif ($active_tab === 'ipv6' && isset($diff['result'])) : ?>
                        <?php $diff_result = $diff['result']; include __DIR__ . '/../_diff_result.php'; ?>
                    <?php endif; ?>
                </div>
            </div>
