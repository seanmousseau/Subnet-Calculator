<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="slaac">
                <div class="overlap-panel">
                    <div class="overlap-title">SLAAC Privacy<?= help_bubble('ipv6-slaac', 'Generates an RFC 8981 privacy interface identifier inside the supplied /64 prefix. The 64-bit interface ID is cryptographically random (PHP random_bytes), with the U/L bit cleared so it cannot be confused with an EUI-64 address derived from a real MAC. Production hosts should leave the seed blank; the seed input is provided only for reproducible documentation/testing output.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <label for="slaac_prefix" class="sr-only">IPv6 /64 prefix</label>
                        <div class="splitter-row">
                            <input type="text" id="slaac_prefix" name="slaac_prefix" class="splitter-input"
                                   placeholder="2001:db8:1:2::/64"
                                   value="<?= htmlspecialchars($slaac_prefix_input) ?>"
                                   autocomplete="off" spellcheck="false"
                                   required
                                   <?= !empty($slaac['error']) ? 'aria-invalid="true" aria-describedby="slaac-error"' : '' ?>>
                            <button type="submit" class="splitter-btn">Generate</button>
                            <button type="reset" class="splitter-btn-ghost">Reset</button>
                        </div>
                        <details class="slaac-advanced"<?= !empty($slaac['seed_was_provided']) ? ' open' : '' ?>>
                            <summary>Advanced (seed for reproducibility)</summary>
                            <p class="slaac-advanced__hint">Optional 16-hex seed for reproducible output (RFC 8981 §3.3.1). Leave blank in production &mdash; every fresh generation should be cryptographically random.</p>
                            <label for="slaac_seed" class="sr-only">Seed (16 hex characters)</label>
                            <input type="text" id="slaac_seed" name="slaac_seed" class="splitter-input"
                                   placeholder="a8d3f4e10c529837"
                                   value="<?= htmlspecialchars($slaac_seed_input) ?>"
                                   pattern="[0-9a-fA-F]{16}"
                                   maxlength="16"
                                   autocomplete="off" spellcheck="false">
                            <?= help_bubble('ipv6-slaac-seed', 'A 16-character hexadecimal seed (64 bits) makes the generated interface ID deterministic. Useful for reproducing examples in documentation or tests; never use a fixed seed in production because it defeats the privacy purpose of RFC 8981.') ?>
                        </details>
                    </form>
                    <?php if (!empty($slaac['error'])) : ?>
                        <div class="error" id="slaac-error"><?= htmlspecialchars($slaac['error']) ?></div>
                    <?php elseif (isset($slaac['address'])) : ?>
                        <?php if (!empty($slaac['warning'])) : ?>
                            <div class="warning"><?= htmlspecialchars($slaac['warning']) ?></div>
                        <?php endif; ?>
                        <?php $_slaac_label = 'SLAAC: ' . ($slaac['prefix'] ?? ''); ?>
                        <dl class="slaac-result"
                            data-history-source="slaac"
                            data-history-active="1"
                            data-history-label="<?= htmlspecialchars($_slaac_label) ?>">
                            <div class="slaac-result__row">
                                <dt class="slaac-result__label">Prefix (canonical)</dt>
                                <dd class="slaac-result__value">
                                    <code><?= htmlspecialchars((string)($slaac['prefix'] ?? '')) ?></code>
                                </dd>
                            </div>
                            <div class="slaac-result__row">
                                <dt class="slaac-result__label">Address<?= help_bubble('ipv6-slaac-addr', 'The full 128-bit IPv6 address: the supplied /64 prefix concatenated with the random 64-bit privacy interface identifier. This is what would be assigned to the host as a temporary SLAAC address per RFC 8981.') ?></dt>
                                <dd class="slaac-result__value">
                                    <code><?= htmlspecialchars((string)($slaac['address'] ?? '')) ?></code>
                                    <?= copy_button((string)($slaac['address'] ?? ''), 'Copy SLAAC privacy address') ?>
                                </dd>
                            </div>
                            <div class="slaac-result__row">
                                <dt class="slaac-result__label">Interface ID<?= help_bubble('ipv6-slaac-iid', 'The 64-bit random interface identifier in colon-separated hextet form. The U/L bit (second-lowest bit of the first byte) is cleared per RFC 4291 §2.5.1 so the address cannot be mistaken for an EUI-64 derived from a hardware MAC.') ?></dt>
                                <dd class="slaac-result__value">
                                    <code><?= htmlspecialchars((string)($slaac['interface_id'] ?? '')) ?></code>
                                    <?= copy_button((string)($slaac['interface_id'] ?? ''), 'Copy interface ID') ?>
                                </dd>
                            </div>
                            <div class="slaac-result__row">
                                <dt class="slaac-result__label">Seed used<?= help_bubble('ipv6-slaac-seed-used', 'The 16-hex seed value that produced this address. If you supplied a seed it is echoed here; otherwise the random seed used internally is shown so you can reproduce the result later (e.g. by pasting it back into the Advanced field).') ?></dt>
                                <dd class="slaac-result__value">
                                    <code><?= htmlspecialchars((string)($slaac['seed_used'] ?? '')) ?></code>
                                    <?= copy_button((string)($slaac['seed_used'] ?? ''), 'Copy seed') ?>
                                </dd>
                            </div>
                        </dl>
                    <?php endif; ?>
                </div>
            </div>
