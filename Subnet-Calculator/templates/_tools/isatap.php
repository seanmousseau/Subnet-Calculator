<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="isatap">
                <div class="overlap-panel">
                    <div class="overlap-title">ISATAP interface-ID helper<?= help_bubble('ipv6-isatap', 'Build and decode the 64-bit ISATAP interface ID (RFC 5214). Encode mode wraps an IPv4 in `0:5efe:V4` (locally-administered) or `200:5efe:V4` (globally-unique, u-bit set); the universal/local bit is auto-detected from the IPv4 (private/reserved → local; public → global) but you can force either side. Decode mode recognises both forms and pulls the embedded IPv4 back out. Append the resulting IID to any /64 prefix to get a complete IPv6 address.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="isatap_mode">Mode<?= help_bubble('isatap-mode', 'Encode: IPv4 → ISATAP IID. Decode: IID → IPv4 + globally-unique flag.') ?></label>
                                <select id="isatap_mode" name="isatap_mode">
                                    <option value="encode"<?= ($isatap_mode ?? 'encode') === 'encode' ? ' selected' : '' ?>>Encode (IPv4 → ISATAP IID)</option>
                                    <option value="decode"<?= ($isatap_mode ?? 'encode') === 'decode' ? ' selected' : '' ?>>Decode (IID → IPv4)</option>
                                </select>
                            </div>
                        </div>
                        <?php if (($isatap_mode ?? 'encode') === 'encode') : ?>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="isatap_ipv4">IPv4 address<?= help_bubble('isatap-ipv4', 'Any IPv4 literal. The encoder packs it into the bottom 32 bits of the IID.') ?></label>
                                    <input type="text" id="isatap_ipv4" name="isatap_ipv4"
                                           value="<?= htmlspecialchars($isatap_ipv4_input ?? '') ?>"
                                           placeholder="192.0.2.1"
                                           autocomplete="off" spellcheck="false"
                                           <?= !empty($isatap['error']) ? 'aria-invalid="true" aria-describedby="isatap-error"' : '' ?>>
                                </div>
                                <div class="form-group">
                                    <label for="isatap_globally_unique">Universal/local bit<?= help_bubble('isatap-gu', 'Auto: infer from the IPv4 (public → globally-unique, private/reserved → local). True: force globally-unique (200:5efe). False: force local (0:5efe).') ?></label>
                                    <select id="isatap_globally_unique" name="isatap_globally_unique">
                                        <option value=""<?= ($isatap_globally_unique ?? '') === '' ? ' selected' : '' ?>>Auto-detect</option>
                                        <option value="true"<?= ($isatap_globally_unique ?? '') === 'true' ? ' selected' : '' ?>>Force globally-unique (0200:5efe)</option>
                                        <option value="false"<?= ($isatap_globally_unique ?? '') === 'false' ? ' selected' : '' ?>>Force locally-administered (0000:5efe)</option>
                                    </select>
                                </div>
                            </div>
                        <?php else : ?>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="isatap_iid">ISATAP IID<?= help_bubble('isatap-iid', 'Four colon-separated hexadectets. Magic must be 0000:5efe (locally-administered) or 0200:5efe (globally-unique). Examples: 0:5efe:c000:201, 200:5efe:c000:201.') ?></label>
                                    <input type="text" id="isatap_iid" name="isatap_iid"
                                           value="<?= htmlspecialchars($isatap_iid_input ?? '') ?>"
                                           placeholder="200:5efe:c000:201"
                                           autocomplete="off" spellcheck="false"
                                           <?= !empty($isatap['error']) ? 'aria-invalid="true" aria-describedby="isatap-error"' : '' ?>>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="splitter-row">
                            <button type="submit" class="splitter-btn">Convert</button>
                        </div>
                    </form>
                    <?php if (!empty($isatap['error'])) : ?>
                        <div class="error" id="isatap-error"><?= htmlspecialchars((string)$isatap['error']) ?></div>
                    <?php elseif (!empty($isatap) && empty($isatap['error'])) : ?>
                        <?php
                        $_isatap_history = 'isatap: ' . (string)($isatap['iid'] ?? $isatap['ipv4'] ?? $isatap['input'] ?? '');
                        ?>
                        <dl class="zoneid-result"
                            data-history-source="isatap"
                            data-history-active="1"
                            data-history-label="<?= htmlspecialchars($_isatap_history) ?>">
                            <?php if (($isatap['mode'] ?? 'encode') === 'encode') : ?>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">IPv4</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars((string)($isatap['ipv4'] ?? '')) ?></code>
                                    </dd>
                                </div>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">ISATAP IID</dt>
                                    <dd class="zoneid-result__value">
                                        <code>::<?= htmlspecialchars((string)($isatap['iid'] ?? '')) ?></code>
                                        <?= copy_button('::' . (string)($isatap['iid'] ?? ''), 'Copy ISATAP IID') ?>
                                    </dd>
                                </div>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">Universal/local bit</dt>
                                    <dd class="zoneid-result__value">
                                        <?= !empty($isatap['globally_unique'])
                                            ? '<span class="badge">globally-unique</span>'
                                            : '<span class="badge">locally-administered</span>' ?>
                                    </dd>
                                </div>
                            <?php else : ?>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">Input IID</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars((string)($isatap['iid'] ?? $isatap['input'] ?? '')) ?></code>
                                    </dd>
                                </div>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">Embedded IPv4</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars((string)($isatap['ipv4'] ?? '')) ?></code>
                                        <?= copy_button((string)($isatap['ipv4'] ?? ''), 'Copy IPv4') ?>
                                    </dd>
                                </div>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">Universal/local bit</dt>
                                    <dd class="zoneid-result__value">
                                        <?= !empty($isatap['globally_unique'])
                                            ? '<span class="badge">globally-unique</span>'
                                            : '<span class="badge">locally-administered</span>' ?>
                                    </dd>
                                </div>
                            <?php endif; ?>
                        </dl>
                        <p><small>ISATAP (RFC 5214) saw limited deployment outside specific enterprise transition scenarios. Provided for analysis of legacy traffic and address audits.</small></p>
                    <?php endif; ?>
                </div>
            </div>
