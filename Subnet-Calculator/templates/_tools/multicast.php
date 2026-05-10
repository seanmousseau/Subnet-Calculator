<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="multicast">
                <div class="overlap-panel">
                    <div class="overlap-title">IPv6 multicast scope decoder<?= help_bubble('ipv6-multicast', 'Front door for the v3.6.0 multicast tools. Decodes any FF00::/8 address into scope, flags, scheme hint, and 112-bit group ID. Looks up well-known groups (RFC 4291 / 7761 / 5905). Deep-links to the SSM and embedded-RP tools when the P or R flag is set.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="multicast6_input">IPv6 multicast address<?= help_bubble('multicast6-input', 'Any IPv6 multicast literal (FF00::/8). Examples: FF02::1 (all nodes), FF02::1:FF12:3456 (solicited-node), FF3E::1234:5678 (SSM global), FF7E:140:2001:db8:cafe::1234 (embedded-RP).') ?></label>
                                <input type="text" id="multicast6_input" name="multicast6_input"
                                       value="<?= htmlspecialchars($multicast6_input ?? '') ?>"
                                       placeholder="FF02::1"
                                       autocomplete="off" spellcheck="false"
                                       <?= !empty($multicast6['error']) ? 'aria-invalid="true" aria-describedby="multicast6-error"' : '' ?>>
                            </div>
                        </div>
                        <div class="splitter-row">
                            <button type="submit" class="splitter-btn">Decode</button>
                        </div>
                    </form>
                    <?php if (!empty($multicast6['error'])) : ?>
                        <div class="error" id="multicast6-error"><?= htmlspecialchars((string)$multicast6['error']) ?></div>
                    <?php elseif (!empty($multicast6) && isset($multicast6['scheme'])) : ?>
                        <?php
                        $_mc_scheme  = (string)$multicast6['scheme'];
                        $_mc_route   = $multicast6['detail_route'] ?? null;
                        $_mc_flags   = (int)($multicast6['flags'] ?? 0);
                        $_mc_history = 'multicast: ' . (string)($multicast6['input'] ?? '');
                        $_mc_flag_bits = sprintf(
                            '0b%s%s%s%s (R=%d P=%d T=%d)',
                            (($_mc_flags & 0x8) !== 0) ? '1' : '0',
                            (($_mc_flags & 0x4) !== 0) ? '1' : '0',
                            (($_mc_flags & 0x2) !== 0) ? '1' : '0',
                            (($_mc_flags & 0x1) !== 0) ? '1' : '0',
                            (($_mc_flags & 0x4) !== 0) ? 1 : 0,
                            (($_mc_flags & 0x2) !== 0) ? 1 : 0,
                            (($_mc_flags & 0x1) !== 0) ? 1 : 0
                        );
                        ?>
                        <dl class="zoneid-result"
                            data-history-source="multicast"
                            data-history-active="1"
                            data-history-label="<?= htmlspecialchars($_mc_history) ?>">
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Address (canonical)</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars((string)($multicast6['address'] ?? '')) ?></code>
                                    <?= copy_button((string)($multicast6['address'] ?? ''), 'Copy canonical address') ?>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Scope</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= (int)($multicast6['scope'] ?? 0) ?> (<?= htmlspecialchars((string)($multicast6['scope_name'] ?? '')) ?>)</code>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Flags</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars($_mc_flag_bits) ?></code>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Scheme</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars($_mc_scheme) ?></code>
                                </dd>
                            </div>
                            <?php if (!empty($multicast6['well_known']) && is_array($multicast6['well_known'])) : ?>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">Well-known group</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars((string)$multicast6['well_known']['name']) ?></code>
                                        <small> (<?= htmlspecialchars((string)$multicast6['well_known']['rfc']) ?>)</small>
                                    </dd>
                                </div>
                            <?php endif; ?>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Group ID</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars((string)($multicast6['group_id'] ?? '')) ?></code>
                                </dd>
                            </div>
                            <?php if ($_mc_route !== null && $_mc_route !== '') : ?>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">Open in tool</dt>
                                    <dd class="zoneid-result__value">
                                        <a class="splitter-btn" href="<?= htmlspecialchars((string)$_mc_route) ?>">Open <?= htmlspecialchars($_mc_scheme) ?> tool</a>
                                    </dd>
                                </div>
                            <?php endif; ?>
                        </dl>
                    <?php endif; ?>
                </div>
            </div>
