<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="ssm">
                <div class="overlap-panel">
                    <div class="overlap-title">SSM / unicast-prefix-based multicast (RFC 3306)<?= help_bubble('ipv6-ssm', 'Build and decode FF3x::/12 multicast group addresses with an embedded unicast prefix. RFC 3306 § 4 / RFC 4607. Encode mode composes a group address from a unicast prefix (0..64), scope (1..15), and 32-bit group ID. Decode mode reverses the process; it requires the flag nibble to be exactly 0x3 (P+T) — embedded-RP groups (R+P+T = 0x7) belong in the embedded-RP tool.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="ssm6_mode">Mode<?= help_bubble('ssm6-mode', 'Encode: unicast prefix + scope + group ID → FF3x:: SSM group. Decode: SSM group → unicast prefix + scope + group ID.') ?></label>
                                <select id="ssm6_mode" name="ssm6_mode">
                                    <option value="encode"<?= ($ssm6_mode ?? 'encode') === 'encode' ? ' selected' : '' ?>>Encode (parts → SSM group)</option>
                                    <option value="decode"<?= ($ssm6_mode ?? 'encode') === 'decode' ? ' selected' : '' ?>>Decode (SSM group → parts)</option>
                                </select>
                            </div>
                        </div>
                        <?php if (($ssm6_mode ?? 'encode') === 'encode') : ?>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="ssm6_unicast_prefix">Unicast prefix<?= help_bubble('ssm6-unicast-prefix', 'IPv6 unicast prefix to embed, in <code>address/length</code> form. Length must be 0..64 — only the upper 64 bits of the prefix fit in an SSM group address. Host bits are masked automatically. Examples: <code>2001:db8::/32</code>, <code>fd00::/8</code>, <code>::/0</code>.') ?></label>
                                    <input type="text" id="ssm6_unicast_prefix" name="ssm6_unicast_prefix"
                                           value="<?= htmlspecialchars($ssm6_unicast_prefix_input ?? '') ?>"
                                           placeholder="2001:db8::/32"
                                           autocomplete="off" spellcheck="false">
                                </div>
                                <div class="form-group form-group--mask">
                                    <label for="ssm6_scope">Scope<?= help_bubble('ssm6-scope', 'Multicast scope (1..15). Common values: 2 (link-local), 5 (site-local), 8 (organization-local), 14 / 0xE (global). RFC 4291 §2.7 + RFC 7346.') ?></label>
                                    <input type="number" id="ssm6_scope" name="ssm6_scope"
                                           value="<?= htmlspecialchars($ssm6_scope_input ?? '14') ?>"
                                           min="1" max="15"
                                           autocomplete="off">
                                </div>
                                <div class="form-group">
                                    <label for="ssm6_group_id">Group ID<?= help_bubble('ssm6-group-id', '32-bit unsigned group ID (0..4294967295). Accepts decimal or hex (<code>0x12345678</code>). RFC 3306 §4 places this in the low 32 bits of the multicast address.') ?></label>
                                    <input type="text" id="ssm6_group_id" name="ssm6_group_id"
                                           value="<?= htmlspecialchars($ssm6_group_id_input ?? '') ?>"
                                           placeholder="0x12345678 or 305419896"
                                           autocomplete="off" spellcheck="false">
                                </div>
                            </div>
                        <?php else : ?>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="ssm6_ipv6">SSM group address<?= help_bubble('ssm6-ipv6', 'An IPv6 multicast literal in FF3x::/12 with the P+T flags set (flag nibble 0x3). Example: <code>FF3E::1234:5678</code> (zero-prefix global SSM), <code>FF3E:20:2001:db8::1234:5678</code> (RFC 3306 §6 worked example).') ?></label>
                                    <input type="text" id="ssm6_ipv6" name="ssm6_ipv6"
                                           value="<?= htmlspecialchars($ssm6_ipv6_input ?? '') ?>"
                                           placeholder="FF3E:20:2001:db8::1234:5678"
                                           autocomplete="off" spellcheck="false"
                                           <?= !empty($ssm6['error']) ? 'aria-invalid="true" aria-describedby="ssm6-error"' : '' ?>>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="splitter-row">
                            <button type="submit" class="splitter-btn"><?= ($ssm6_mode ?? 'encode') === 'encode' ? 'Build group' : 'Decode group' ?></button>
                        </div>
                    </form>
                    <?php if (!empty($ssm6['error'])) : ?>
                        <div class="error" id="ssm6-error"><?= htmlspecialchars((string)$ssm6['error']) ?></div>
                    <?php elseif (!empty($ssm6) && isset($ssm6['address'])) : ?>
                        <?php
                        $_ssm_history = 'ssm: ' . (string)($ssm6['address'] ?? '');
                        ?>
                        <dl class="zoneid-result"
                            data-history-source="ssm"
                            data-history-active="1"
                            data-history-label="<?= htmlspecialchars($_ssm_history) ?>">
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">SSM group address</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars((string)($ssm6['address'] ?? '')) ?></code>
                                    <?= copy_button((string)($ssm6['address'] ?? ''), 'Copy SSM group address') ?>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Scope</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= (int)($ssm6['scope'] ?? 0) ?></code>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Prefix length</dt>
                                <dd class="zoneid-result__value">
                                    <code>/<?= (int)($ssm6['prefix_length'] ?? 0) ?></code>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Unicast prefix</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars((string)($ssm6['unicast_prefix'] ?? '')) ?></code>
                                    <?= copy_button((string)($ssm6['unicast_prefix'] ?? ''), 'Copy unicast prefix') ?>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Group ID</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars(sprintf('0x%08x (%d)', (int)($ssm6['group_id'] ?? 0), (int)($ssm6['group_id'] ?? 0))) ?></code>
                                </dd>
                            </div>
                        </dl>
                        <p><small>RFC 3306 §4 / RFC 4607. The flag nibble is fixed at 0x3 (P+T); embedded-RP groups (R+P+T = 0x7) belong in the embedded-RP tool.</small></p>
                    <?php endif; ?>
                </div>
            </div>
