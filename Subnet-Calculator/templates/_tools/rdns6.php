<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="rdns6">
                <div class="overlap-panel">
                    <div class="overlap-title">Reverse DNS (rdns6)<?= help_bubble('ipv6-rdns6', 'Generates the ip6.arpa zone-delegation name for an IPv6 address per RFC 3596. With no prefix (or /128) returns the full reverse name. The prefix length must be a multiple of 4 — that is the nibble boundary required for delegation.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="rdns6_address">IPv6 address<?= help_bubble('rdns6-address', 'Any valid IPv6 literal (compressed or expanded form). Example: 2001:db8::1 or fe80::1.') ?></label>
                                <input type="text" id="rdns6_address" name="rdns6_address"
                                       value="<?= htmlspecialchars($rdns6_address_input ?? '') ?>"
                                       placeholder="2001:db8::1"
                                       autocomplete="off" spellcheck="false"
                                       <?= !empty($rdns6['error']) ? 'aria-invalid="true" aria-describedby="rdns6-error"' : '' ?>>
                            </div>
                            <div class="form-group form-group--mask">
                                <label for="rdns6_prefix">Prefix <span class="label-footnote">(optional, /4 boundary)</span><?= help_bubble('rdns6-prefix', 'Optional prefix length for zone-delegation form. Must be a multiple of 4 (0, 4, 8, …, 128). Common choices: /48 for ISP delegation, /56 for sub-allocation, /64 for a single LAN. Leave blank or set to 128 for the full reverse name.') ?></label>
                                <input type="number" id="rdns6_prefix" name="rdns6_prefix" min="0" max="128" step="4"
                                       value="<?= htmlspecialchars($rdns6_prefix_input ?? '') ?>"
                                       placeholder="64"
                                       autocomplete="off">
                            </div>
                        </div>
                        <div class="splitter-row">
                            <button type="submit" class="splitter-btn">Generate</button>
                        </div>
                    </form>
                    <?php if (!empty($rdns6['error'])) : ?>
                        <div class="error" id="rdns6-error"><?= htmlspecialchars($rdns6['error']) ?></div>
                    <?php elseif (isset($rdns6['arpa'])) : ?>
                        <?php $_rdns6_label = 'rdns6: ' . $rdns6['address'] . '/' . $rdns6['prefix']; ?>
                        <dl class="zoneid-result"
                            data-history-source="rdns6"
                            data-history-active="1"
                            data-history-label="<?= htmlspecialchars($_rdns6_label) ?>">
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Address</dt>
                                <dd class="zoneid-result__value"><code><?= htmlspecialchars((string)$rdns6['address']) ?></code></dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Prefix</dt>
                                <dd class="zoneid-result__value"><code>/<?= htmlspecialchars((string)$rdns6['prefix']) ?></code></dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">ip6.arpa zone</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars((string)$rdns6['arpa']) ?></code>
                                    <?= copy_button((string)$rdns6['arpa'], 'Copy ip6.arpa zone name') ?>
                                </dd>
                            </div>
                        </dl>
                    <?php endif; ?>
                </div>
            </div>
