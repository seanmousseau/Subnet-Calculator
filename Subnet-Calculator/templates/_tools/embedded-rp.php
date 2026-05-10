<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="embedded-rp">
                <div class="overlap-panel">
                    <div class="overlap-title">Embedded-RP multicast (RFC 3956)<?= help_bubble('ipv6-embedded-rp', 'Build and decode FF7x::/12 multicast group addresses with the Rendezvous Point address embedded directly in the group address. RFC 3956. Encode mode composes a group from an RP address (whose host suffix is replaced by the explicit RIID), RP prefix length (0..64), 4-bit RIID, scope (1..15), and 32-bit group ID. Decode mode reverses the process; it requires the flag nibble to be exactly 0x7 (R+P+T) — SSM groups (P+T = 0x3) belong in the SSM tool.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="embedded_rp6_mode">Mode<?= help_bubble('embedded-rp6-mode', 'Encode: RP parts → FF7x:: embedded-RP group. Decode: FF7x:: group → RP address, prefix length, RIID, scope, and group ID.') ?></label>
                                <select id="embedded_rp6_mode" name="embedded_rp6_mode">
                                    <option value="encode"<?= ($embedded_rp6_mode ?? 'encode') === 'encode' ? ' selected' : '' ?>>Encode (parts → embedded-RP group)</option>
                                    <option value="decode"<?= ($embedded_rp6_mode ?? 'encode') === 'decode' ? ' selected' : '' ?>>Decode (group → parts)</option>
                                </select>
                            </div>
                        </div>
                        <?php if (($embedded_rp6_mode ?? 'encode') === 'encode') : ?>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="embedded_rp6_rp_address">RP address<?= help_bubble('embedded-rp6-rp-address', 'Rendezvous Point IPv6 address. Only the upper bits selected by the prefix length are encoded into the multicast group; the host suffix is ignored and replaced by the explicit RIID. Example: <code>2001:db8:cafe::1</code>.') ?></label>
                                    <input type="text" id="embedded_rp6_rp_address" name="embedded_rp6_rp_address"
                                           value="<?= htmlspecialchars($embedded_rp6_rp_address_input ?? '') ?>"
                                           placeholder="2001:db8:cafe::1"
                                           autocomplete="off" spellcheck="false">
                                </div>
                                <div class="form-group form-group--mask">
                                    <label for="embedded_rp6_rp_prefix_length">RP prefix length<?= help_bubble('embedded-rp6-rp-prefix-length', 'Length of the RP prefix in bits (0..64). RFC 3956 caps this at 64 because only the upper 64 bits of the RP prefix fit in the multicast address. Typical values: 32 (provider prefix), 48 (site prefix), 56 / 64 (subnet).') ?></label>
                                    <input type="number" id="embedded_rp6_rp_prefix_length" name="embedded_rp6_rp_prefix_length"
                                           value="<?= htmlspecialchars($embedded_rp6_rp_prefix_length_input ?? '') ?>"
                                           min="0" max="64"
                                           placeholder="48"
                                           autocomplete="off">
                                </div>
                                <div class="form-group form-group--mask">
                                    <label for="embedded_rp6_riid">RIID<?= help_bubble('embedded-rp6-riid', '4-bit RP interface ID (0..15). Selects which interface on the RP terminates the shared tree. The canonical RP address is reconstructed as <code>&lt;rp-prefix&gt;::&lt;riid&gt;</code>.') ?></label>
                                    <input type="number" id="embedded_rp6_riid" name="embedded_rp6_riid"
                                           value="<?= htmlspecialchars($embedded_rp6_riid_input ?? '') ?>"
                                           min="0" max="15"
                                           placeholder="1"
                                           autocomplete="off">
                                </div>
                                <div class="form-group form-group--mask">
                                    <label for="embedded_rp6_scope">Scope<?= help_bubble('embedded-rp6-scope', 'Multicast scope (1..15). Common values: 2 (link-local), 5 (site-local), 8 (organization-local), 14 / 0xE (global). RFC 4291 §2.7 + RFC 7346.') ?></label>
                                    <input type="number" id="embedded_rp6_scope" name="embedded_rp6_scope"
                                           value="<?= htmlspecialchars($embedded_rp6_scope_input ?? '14') ?>"
                                           min="1" max="15"
                                           autocomplete="off">
                                </div>
                                <div class="form-group">
                                    <label for="embedded_rp6_group_id">Group ID<?= help_bubble('embedded-rp6-group-id', '32-bit unsigned group ID (0..4294967295). Accepts decimal or hex (<code>0x12345678</code>). RFC 3956 places this in the low 32 bits of the multicast address.') ?></label>
                                    <input type="text" id="embedded_rp6_group_id" name="embedded_rp6_group_id"
                                           value="<?= htmlspecialchars($embedded_rp6_group_id_input ?? '') ?>"
                                           placeholder="0x12345678 or 305419896"
                                           autocomplete="off" spellcheck="false">
                                </div>
                            </div>
                        <?php else : ?>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="embedded_rp6_ipv6">Embedded-RP group address<?= help_bubble('embedded-rp6-ipv6', 'An IPv6 multicast literal in FF7x::/12 with the R+P+T flags set (flag nibble 0x7). Example: <code>ff7e:130:2001:db8:cafe:0:1234:5678</code> (RP = 2001:db8:cafe::1, RIID = 1, scope = 0xE, group = 0x12345678).') ?></label>
                                    <input type="text" id="embedded_rp6_ipv6" name="embedded_rp6_ipv6"
                                           value="<?= htmlspecialchars($embedded_rp6_ipv6_input ?? '') ?>"
                                           placeholder="ff7e:130:2001:db8:cafe:0:1234:5678"
                                           autocomplete="off" spellcheck="false"
                                           <?= !empty($embedded_rp6['error']) ? 'aria-invalid="true" aria-describedby="embedded-rp6-error"' : '' ?>>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="splitter-row">
                            <button type="submit" class="splitter-btn"><?= ($embedded_rp6_mode ?? 'encode') === 'encode' ? 'Build group' : 'Decode group' ?></button>
                        </div>
                    </form>
                    <?php if (!empty($embedded_rp6['error'])) : ?>
                        <div class="error" id="embedded-rp6-error"><?= htmlspecialchars((string)$embedded_rp6['error']) ?></div>
                    <?php elseif (!empty($embedded_rp6) && isset($embedded_rp6['address'])) : ?>
                        <?php
                        $_erp_history = 'embedded-rp: ' . (string)($embedded_rp6['address'] ?? '');
                        ?>
                        <dl class="zoneid-result"
                            data-history-source="embedded-rp"
                            data-history-active="1"
                            data-history-label="<?= htmlspecialchars($_erp_history) ?>">
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Embedded-RP group address</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars((string)($embedded_rp6['address'] ?? '')) ?></code>
                                    <?= copy_button((string)($embedded_rp6['address'] ?? ''), 'Copy embedded-RP group address') ?>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Scope</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= (int)($embedded_rp6['scope'] ?? 0) ?></code>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">RP prefix length</dt>
                                <dd class="zoneid-result__value">
                                    <code>/<?= (int)($embedded_rp6['rp_prefix_length'] ?? 0) ?></code>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">RP prefix</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars((string)($embedded_rp6['rp_prefix'] ?? '')) ?></code>
                                    <?= copy_button((string)($embedded_rp6['rp_prefix'] ?? ''), 'Copy RP prefix') ?>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">RP address</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars((string)($embedded_rp6['rp_address'] ?? '')) ?></code>
                                    <?= copy_button((string)($embedded_rp6['rp_address'] ?? ''), 'Copy RP address') ?>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">RIID</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= (int)($embedded_rp6['riid'] ?? 0) ?></code>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Group ID</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars(sprintf('0x%08x (%d)', (int)($embedded_rp6['group_id'] ?? 0), (int)($embedded_rp6['group_id'] ?? 0))) ?></code>
                                </dd>
                            </div>
                        </dl>
                        <p><small>RFC 3956. The flag nibble is fixed at 0x7 (R+P+T); SSM-only groups (P+T = 0x3) belong in the SSM tool.</small></p>
                    <?php endif; ?>
                </div>
            </div>
