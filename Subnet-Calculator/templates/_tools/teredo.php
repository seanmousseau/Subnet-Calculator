<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="teredo">
                <div class="overlap-panel">
                    <div class="overlap-title">Teredo address decoder<?= help_bubble('ipv6-teredo', 'Decode and encode IPv6 addresses in the 2001:0::/32 Teredo prefix (RFC 4380). Decode mode extracts the server IPv4 (raw), 16-bit flags (cone bit = 0x8000), de-XOR\'d UDP port (XOR 0xFFFF), and de-XOR\'d client IPv4 (each byte XOR 0xFF). Encode mode rebuilds the address from those parts. Microsoft retired the public Teredo service for consumer Windows in 2019; this tool is provided for analysis of legacy traffic captures and address audits.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="teredo_mode">Mode<?= help_bubble('teredo-mode', 'Decode: Teredo IPv6 → server v4, client v4, port, flags. Encode: parts → Teredo IPv6 in 2001:0::/32.') ?></label>
                                <select id="teredo_mode" name="teredo_mode">
                                    <option value="decode"<?= ($teredo_mode ?? 'decode') === 'decode' ? ' selected' : '' ?>>Decode (Teredo IPv6 → parts)</option>
                                    <option value="encode"<?= ($teredo_mode ?? 'decode') === 'encode' ? ' selected' : '' ?>>Encode (parts → Teredo IPv6)</option>
                                </select>
                            </div>
                        </div>
                        <?php if (($teredo_mode ?? 'decode') === 'decode') : ?>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="teredo_input">Teredo IPv6 address<?= help_bubble('teredo-input', 'Any IPv6 literal under 2001:0::/32. Example: 2001:0:4136:e378:8000:63bf:3fff:fdd2 (RFC 4380 §4 worked example).') ?></label>
                                    <input type="text" id="teredo_input" name="teredo_input"
                                           value="<?= htmlspecialchars($teredo_input ?? '') ?>"
                                           placeholder="2001:0:4136:e378:8000:63bf:3fff:fdd2"
                                           autocomplete="off" spellcheck="false"
                                           <?= !empty($teredo['error']) ? 'aria-invalid="true" aria-describedby="teredo-error"' : '' ?>>
                                </div>
                            </div>
                        <?php else : ?>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="teredo_server">Server IPv4<?= help_bubble('teredo-server', 'The Teredo server\'s public IPv4 address. Stored raw in the address (no XOR obfuscation).') ?></label>
                                    <input type="text" id="teredo_server" name="teredo_server"
                                           value="<?= htmlspecialchars($teredo_server_input ?? '') ?>"
                                           placeholder="65.54.227.120"
                                           autocomplete="off" spellcheck="false">
                                </div>
                                <div class="form-group">
                                    <label for="teredo_client">Client IPv4<?= help_bubble('teredo-client', 'The Teredo client\'s public IPv4 address. Obfuscated with XOR 0xFF byte-by-byte before insertion into the IPv6 address.') ?></label>
                                    <input type="text" id="teredo_client" name="teredo_client"
                                           value="<?= htmlspecialchars($teredo_client_input ?? '') ?>"
                                           placeholder="192.0.2.45"
                                           autocomplete="off" spellcheck="false">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="teredo_port">UDP port<?= help_bubble('teredo-port', 'The client\'s mapped UDP port (0..65535). Obfuscated with XOR 0xFFFF before insertion.') ?></label>
                                    <input type="text" id="teredo_port" name="teredo_port"
                                           value="<?= htmlspecialchars($teredo_port_input ?? '') ?>"
                                           placeholder="40000"
                                           inputmode="numeric"
                                           autocomplete="off" spellcheck="false">
                                </div>
                                <div class="form-group">
                                    <label for="teredo_flags">Flags<?= help_bubble('teredo-flags', '16-bit flags field. Decimal or 0xNNNN. The high bit (0x8000) is the "cone" indicator. Leave blank to use the cone toggle below.') ?></label>
                                    <input type="text" id="teredo_flags" name="teredo_flags"
                                           value="<?= htmlspecialchars($teredo_flags_input ?? '') ?>"
                                           placeholder="0x8000"
                                           autocomplete="off" spellcheck="false">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label><input type="checkbox" name="teredo_cone" value="1"<?= !empty($teredo_cone_flag) ? ' checked' : '' ?>> Cone NAT<?= help_bubble('teredo-cone', 'When set, the Teredo client is behind a cone NAT (flags = 0x8000). When unset, flags = 0x0000. Ignored if you supply a flags value above.') ?></label>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="splitter-row">
                            <button type="submit" class="splitter-btn">Convert</button>
                        </div>
                    </form>
                    <?php if (!empty($teredo['error'])) : ?>
                        <div class="error" id="teredo-error"><?= htmlspecialchars((string)$teredo['error']) ?></div>
                    <?php elseif (!empty($teredo) && empty($teredo['error'])) : ?>
                        <?php
                        $_teredo_history = 'teredo: ' . (string)($teredo['ipv6'] ?? $teredo['input'] ?? '');
                        ?>
                        <dl class="zoneid-result"
                            data-history-source="teredo"
                            data-history-active="1"
                            data-history-label="<?= htmlspecialchars($_teredo_history) ?>">
                            <?php if (($teredo['mode'] ?? 'decode') === 'decode') : ?>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">Input IPv6</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars((string)($teredo['input'] ?? '')) ?></code>
                                    </dd>
                                </div>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">Server IPv4</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars((string)($teredo['server_ipv4'] ?? '')) ?></code>
                                        <?= copy_button((string)($teredo['server_ipv4'] ?? ''), 'Copy server IPv4') ?>
                                    </dd>
                                </div>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">Client IPv4</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars((string)($teredo['client_ipv4'] ?? '')) ?></code>
                                        <?= copy_button((string)($teredo['client_ipv4'] ?? ''), 'Copy client IPv4') ?>
                                    </dd>
                                </div>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">UDP port</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars((string)(int)($teredo['port'] ?? 0)) ?></code>
                                    </dd>
                                </div>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">Flags</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars(sprintf('0x%04x', (int)($teredo['flags'] ?? 0))) ?></code>
                                        <?= !empty($teredo['cone']) ? ' <span class="badge">cone</span>' : '' ?>
                                    </dd>
                                </div>
                            <?php else : ?>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">Server IPv4</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars((string)($teredo['server_ipv4'] ?? '')) ?></code>
                                    </dd>
                                </div>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">Client IPv4</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars((string)($teredo['client_ipv4'] ?? '')) ?></code>
                                    </dd>
                                </div>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">UDP port</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars((string)(int)($teredo['port'] ?? 0)) ?></code>
                                    </dd>
                                </div>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">Flags</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars(sprintf('0x%04x', (int)($teredo['flags'] ?? 0))) ?></code>
                                        <?= !empty($teredo['cone']) ? ' <span class="badge">cone</span>' : '' ?>
                                    </dd>
                                </div>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">Teredo IPv6</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars((string)($teredo['ipv6'] ?? '')) ?></code>
                                        <?= copy_button((string)($teredo['ipv6'] ?? ''), 'Copy Teredo IPv6') ?>
                                    </dd>
                                </div>
                            <?php endif; ?>
                        </dl>
                        <p><small>Microsoft retired the public Teredo service for consumer Windows in 2019. This tool is provided for analysis of legacy traffic captures and address audits.</small></p>
                    <?php endif; ?>
                </div>
            </div>
