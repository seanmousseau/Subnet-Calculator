<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="6rd">
                <div class="overlap-panel">
                    <div class="overlap-title">6rd address tool<?= help_bubble('ipv6-6rd', 'Compute the customer IPv6 prefix delegated by a 6rd service provider per RFC 5969. Encode mode takes the SP IPv6 prefix, the SP IPv4 mask length (bits stripped from the high end of the customer v4), and the customer IPv4, and emits the delegated IPv6 prefix. Decode mode reverses: SP params + a 6rd IPv6 address → the customer IPv4 (the masked-off region is treated as zero since it is SP-side configuration).') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="sixrd_mode">Mode<?= help_bubble('6rd-mode', 'Encode: customer IPv4 → delegated IPv6 prefix. Decode: 6rd IPv6 address → customer IPv4.') ?></label>
                                <select id="sixrd_mode" name="sixrd_mode">
                                    <option value="encode"<?= ($sixrd_mode ?? 'encode') === 'encode' ? ' selected' : '' ?>>Encode (IPv4 → delegated prefix)</option>
                                    <option value="decode"<?= ($sixrd_mode ?? 'encode') === 'decode' ? ' selected' : '' ?>>Decode (6rd address → IPv4)</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="sixrd_sp_prefix">SP IPv6 prefix<?= help_bubble('6rd-sp-prefix', 'The service provider\'s globally-routed 6rd IPv6 prefix in CIDR form, e.g. 2001:db8::/32. Provided by the SP via DHCPv6 OPTION_6RD or out-of-band configuration.') ?></label>
                                <input type="text" id="sixrd_sp_prefix" name="sixrd_sp_prefix"
                                       value="<?= htmlspecialchars($sixrd_sp_prefix_input ?? '') ?>"
                                       placeholder="2001:db8::/32"
                                       autocomplete="off" spellcheck="false"
                                       <?= !empty($sixrd['error']) ? 'aria-invalid="true" aria-describedby="sixrd-error"' : '' ?>>
                            </div>
                            <div class="form-group form-group--mask">
                                <label for="sixrd_mask_len">SP IPv4 mask length<?= help_bubble('6rd-mask-len', 'How many leading bits of the customer IPv4 the SP discards before embedding (0..32). Typically 0 to embed the full v4. The remaining (32 − mask_len) bits are appended to the SP prefix.') ?></label>
                                <input type="number" id="sixrd_mask_len" name="sixrd_mask_len"
                                       value="<?= htmlspecialchars($sixrd_mask_len_input ?? '') ?>"
                                       min="0" max="32" placeholder="0"
                                       autocomplete="off" spellcheck="false"
                                       <?= !empty($sixrd['error']) ? 'aria-invalid="true" aria-describedby="sixrd-error"' : '' ?>>
                            </div>
                        </div>
                        <?php if (($sixrd_mode ?? 'encode') === 'encode') : ?>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="sixrd_ipv4">Customer IPv4<?= help_bubble('6rd-ipv4', 'The customer\'s WAN IPv4 address. After stripping the top mask-length bits, the remaining bits are appended to the SP prefix to form the delegated IPv6 prefix.') ?></label>
                                    <input type="text" id="sixrd_ipv4" name="sixrd_ipv4"
                                           value="<?= htmlspecialchars($sixrd_ipv4_input ?? '') ?>"
                                           placeholder="192.0.2.1"
                                           autocomplete="off" spellcheck="false"
                                           <?= !empty($sixrd['error']) ? 'aria-invalid="true" aria-describedby="sixrd-error"' : '' ?>>
                                </div>
                            </div>
                        <?php else : ?>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="sixrd_ipv6">6rd IPv6 address<?= help_bubble('6rd-ipv6', 'Any IPv6 address (with or without /prefix) within the SP\'s 6rd domain. The decoder verifies the address starts with the SP prefix and extracts the customer-contributed bits.') ?></label>
                                    <input type="text" id="sixrd_ipv6" name="sixrd_ipv6"
                                           value="<?= htmlspecialchars($sixrd_ipv6_input ?? '') ?>"
                                           placeholder="2001:db8:c000:201::1"
                                           autocomplete="off" spellcheck="false"
                                           <?= !empty($sixrd['error']) ? 'aria-invalid="true" aria-describedby="sixrd-error"' : '' ?>>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="splitter-row">
                            <button type="submit" class="splitter-btn">Convert</button>
                        </div>
                    </form>
                    <?php if (!empty($sixrd['error'])) : ?>
                        <div class="error" id="sixrd-error"><?= htmlspecialchars((string)$sixrd['error']) ?></div>
                    <?php elseif (!empty($sixrd) && empty($sixrd['error'])) : ?>
                        <?php
                        $_sixrd_history = '6rd: ' . (string)($sixrd['prefix'] ?? $sixrd['ipv4'] ?? '');
                        ?>
                        <dl class="zoneid-result"
                            data-history-source="6rd"
                            data-history-active="1"
                            data-history-label="<?= htmlspecialchars($_sixrd_history) ?>">
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">SP IPv6 prefix</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars((string)($sixrd['sp_ipv6_prefix'] ?? '')) ?></code>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">SP IPv4 mask length</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars((string)($sixrd['sp_ipv4_mask_len'] ?? 0)) ?></code>
                                </dd>
                            </div>
                            <?php if (($sixrd['mode'] ?? 'encode') === 'encode') : ?>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">Customer IPv4</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars((string)($sixrd['ipv4'] ?? '')) ?></code>
                                    </dd>
                                </div>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">Delegated IPv6 prefix</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars((string)($sixrd['prefix'] ?? '')) ?></code>
                                        <?= copy_button((string)($sixrd['prefix'] ?? ''), 'Copy delegated prefix') ?>
                                    </dd>
                                </div>
                            <?php else : ?>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">6rd IPv6 address</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars((string)($sixrd['ipv6'] ?? '')) ?></code>
                                    </dd>
                                </div>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">Customer IPv4</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars((string)($sixrd['ipv4'] ?? '')) ?></code>
                                        <?= copy_button((string)($sixrd['ipv4'] ?? ''), 'Copy IPv4') ?>
                                    </dd>
                                </div>
                            <?php endif; ?>
                        </dl>
                        <p><small>6rd (RFC 5969) is a service-provider-configured tunneling scheme; the SP parameters above must come from your provider. The masked-off portion of the customer IPv4 cannot be recovered from the 6rd address alone — it is treated as zero on decode.</small></p>
                    <?php endif; ?>
                </div>
            </div>
