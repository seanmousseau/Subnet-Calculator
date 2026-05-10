<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="rfc3531">
                <div class="overlap-panel">
                    <div class="overlap-title">RFC 3531 sparse allocation<?= help_bubble('ipv6-rfc3531', 'Apply an RFC 3531 bit-reservation strategy (centermost / leftmost / rightmost) to a parent IPv6 prefix. Centermost bisects outward from the middle so future growth has room either side; leftmost is dense sequential; rightmost mirrors leftmost from the top down.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="rfc3531_parent">Parent IPv6 prefix<?= help_bubble('rfc3531-parent', 'The parent prefix in CIDR form, e.g. 2001:db8::/48. Host bits are zeroed before allocation.') ?></label>
                                <input type="text" id="rfc3531_parent" name="rfc3531_parent"
                                       value="<?= htmlspecialchars($rfc3531_parent_input ?? '') ?>"
                                       placeholder="2001:db8::/48"
                                       autocomplete="off" spellcheck="false"
                                       <?= !empty($rfc3531['error']) ? 'aria-invalid="true" aria-describedby="rfc3531-error"' : '' ?>>
                            </div>
                            <div class="form-group form-group--mask">
                                <label for="rfc3531_reservation_bits">Reservation bits<?= help_bubble('rfc3531-reservation-bits', 'Number of bits in the reservation field (1..8 by default; operators can raise to 12). Each child block has prefix length parent_length + reservation_bits.') ?></label>
                                <input type="number" id="rfc3531_reservation_bits" name="rfc3531_reservation_bits"
                                       value="<?= htmlspecialchars($rfc3531_reservation_bits_input ?? '') ?>"
                                       min="1" max="12" placeholder="4"
                                       autocomplete="off" spellcheck="false"
                                       <?= !empty($rfc3531['error']) ? 'aria-invalid="true" aria-describedby="rfc3531-error"' : '' ?>>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="rfc3531_strategy">Strategy<?= help_bubble('rfc3531-strategy', 'Centermost: bisect outward from the middle (RFC 3531 §3 — best for growth). Leftmost: monotonic 0..N. Rightmost: reverse of leftmost.') ?></label>
                                <select id="rfc3531_strategy" name="rfc3531_strategy">
                                    <?php $_strat = (string)($rfc3531_strategy_input ?? 'centermost'); ?>
                                    <option value="centermost" <?= $_strat === 'centermost' ? 'selected' : '' ?>>Centermost</option>
                                    <option value="leftmost"   <?= $_strat === 'leftmost'   ? 'selected' : '' ?>>Leftmost</option>
                                    <option value="rightmost"  <?= $_strat === 'rightmost'  ? 'selected' : '' ?>>Rightmost</option>
                                </select>
                            </div>
                        </div>
                        <div class="splitter-row">
                            <button type="submit" class="splitter-btn">Apply</button>
                        </div>
                    </form>
                    <?php if (!empty($rfc3531['error'])) : ?>
                        <div class="error" id="rfc3531-error"><?= htmlspecialchars((string)$rfc3531['error']) ?></div>
                    <?php elseif (!empty($rfc3531['result'])) : ?>
                        <?php
                        $_r3 = $rfc3531['result'];
                        $_r3_history = 'RFC 3531: ' . (string)($_r3['parent']['prefix'] ?? '')
                            . ' / ' . (string)$_r3['strategy']
                            . ' / ' . (int)$_r3['reservation_bits'] . ' bits';
                        ?>
                        <dl class="zoneid-result"
                            data-history-source="rfc3531"
                            data-history-active="1"
                            data-history-label="<?= htmlspecialchars($_r3_history) ?>">
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Parent prefix</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars((string)$_r3['parent']['prefix']) ?></code>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Strategy</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars((string)$_r3['strategy']) ?></code>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Reservation bits</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= (int)$_r3['reservation_bits'] ?></code>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Children</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= count($_r3['children']) ?></code>
                                </dd>
                            </div>
                        </dl>
                        <table class="vlsm-table" data-rfc3531-table>
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Value</th>
                                    <th>Prefix</th>
                                    <th aria-label="Copy"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($_r3['children'] as $_child) : ?>
                                <tr>
                                    <td><?= (int)$_child['order'] ?></td>
                                    <td><?= (int)$_child['value'] ?></td>
                                    <td><code><?= htmlspecialchars((string)$_child['prefix']) ?></code></td>
                                    <td><?= copy_button((string)$_child['prefix'], 'Copy child prefix') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p><small>RFC 3531 sparse allocation orders the reservation field for growth-friendly prefix assignment. Centermost is the canonical strategy from §3 — the centre value is allocated first, then halves bisect outward, leaving room either side for future expansion.</small></p>
                    <?php endif; ?>
                </div>
            </div>
