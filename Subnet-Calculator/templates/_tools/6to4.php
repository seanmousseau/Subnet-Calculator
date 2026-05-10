<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="6to4">
                <div class="overlap-panel">
                    <div class="overlap-title">6to4 address tool<?= help_bubble('ipv6-6to4', 'Bidirectional translation between public IPv4 addresses and 2002::/16 6to4 prefixes (RFC 3056). Encode mode produces 2002:WWXX:YYZZ::/48 from W.X.Y.Z; decode mode extracts the embedded IPv4, 16-bit Subnet/SLA ID, and 64-bit Interface ID from a 6to4 address. RFC 7526 deprecated the 6to4 anycast relay in 2015 — use this tool for analysis and migration audits, not for greenfield deployments.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="sixtofour_mode">Mode<?= help_bubble('sixtofour-mode', 'Encode: IPv4 → 2002:WWXX:YYZZ::/48. Decode: 6to4 IPv6 → embedded IPv4, Subnet ID, Interface ID.') ?></label>
                                <select id="sixtofour_mode" name="sixtofour_mode">
                                    <option value="encode"<?= ($sixtofour_mode ?? 'encode') === 'encode' ? ' selected' : '' ?>>Encode (IPv4 → 6to4 prefix)</option>
                                    <option value="decode"<?= ($sixtofour_mode ?? 'encode') === 'decode' ? ' selected' : '' ?>>Decode (6to4 IPv6 → IPv4)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="sixtofour_input">Address<?= help_bubble('sixtofour-input', 'Encode: a public IPv4 (e.g. 192.0.2.1). Private (RFC 1918), reserved, multicast, and 0.0.0.0 are rejected. Decode: any IPv6 literal under 2002::/16 (e.g. 2002:c000:0201::1).') ?></label>
                                <input type="text" id="sixtofour_input" name="sixtofour_input"
                                       value="<?= htmlspecialchars($sixtofour_input ?? '') ?>"
                                       placeholder="192.0.2.1 or 2002:c000:0201::1"
                                       autocomplete="off" spellcheck="false"
                                       <?= !empty($sixtofour['error']) ? 'aria-invalid="true" aria-describedby="sixtofour-error"' : '' ?>>
                            </div>
                        </div>
                        <div class="splitter-row">
                            <button type="submit" class="splitter-btn">Convert</button>
                        </div>
                    </form>
                    <?php if (!empty($sixtofour['error'])) : ?>
                        <div class="error" id="sixtofour-error"><?= htmlspecialchars((string)$sixtofour['error']) ?></div>
                    <?php elseif (!empty($sixtofour) && empty($sixtofour['error'])) : ?>
                        <?php
                        $_s2f_history = '6to4: ' . (string)($sixtofour['input'] ?? '');
                        ?>
                        <dl class="zoneid-result"
                            data-history-source="6to4"
                            data-history-active="1"
                            data-history-label="<?= htmlspecialchars($_s2f_history) ?>">
                            <?php if (($sixtofour['mode'] ?? 'encode') === 'encode') : ?>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">Input IPv4</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars((string)($sixtofour['input'] ?? '')) ?></code>
                                    </dd>
                                </div>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">6to4 prefix</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars((string)($sixtofour['prefix'] ?? '')) ?></code>
                                        <?= copy_button((string)($sixtofour['prefix'] ?? ''), 'Copy 6to4 prefix') ?>
                                    </dd>
                                </div>
                            <?php else : ?>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">Input IPv6</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars((string)($sixtofour['input'] ?? '')) ?></code>
                                    </dd>
                                </div>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">Embedded IPv4</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars((string)($sixtofour['ipv4'] ?? '')) ?></code>
                                        <?= copy_button((string)($sixtofour['ipv4'] ?? ''), 'Copy embedded IPv4') ?>
                                    </dd>
                                </div>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">Subnet ID</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars(sprintf('0x%04x (%d)', (int)($sixtofour['subnet_id'] ?? 0), (int)($sixtofour['subnet_id'] ?? 0))) ?></code>
                                    </dd>
                                </div>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">Interface ID</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars((string)($sixtofour['interface_id'] ?? '')) ?></code>
                                        <?= copy_button((string)($sixtofour['interface_id'] ?? ''), 'Copy Interface ID') ?>
                                    </dd>
                                </div>
                            <?php endif; ?>
                        </dl>
                        <p><small>RFC 7526 deprecated the 6to4 anycast relay (192.88.99.1) in 2015. This tool is provided for analysis and migration audits.</small></p>
                    <?php endif; ?>
                </div>
            </div>
