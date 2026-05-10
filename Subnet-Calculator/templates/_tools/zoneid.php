<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="zoneid">
                <div class="overlap-panel">
                    <div class="overlap-title">Zone ID Parser<?= help_bubble('ipv6-zoneid', 'Zone identifiers (RFC 4007) scope an IPv6 address to a specific interface. They are written after a percent sign — e.g. fe80::1%eth0 — and are only meaningful on link-local (fe80::/10) addresses; most operating systems ignore zones supplied on global addresses.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <label for="zoneid_input" class="sr-only">IPv6 address with optional zone identifier</label>
                        <div class="splitter-row">
                            <input type="text" id="zoneid_input" name="zoneid_input" class="splitter-input"
                                   placeholder="fe80::1%eth0"
                                   value="<?= htmlspecialchars($zoneid_input) ?>"
                                   autocomplete="off" spellcheck="false"
                                   <?= !empty($zoneid['error']) ? 'aria-invalid="true" aria-describedby="zoneid-error"' : '' ?>>
                            <button type="submit" class="splitter-btn">Parse</button>
                        </div>
                    </form>
                    <?php if (!empty($zoneid['error'])) : ?>
                        <div class="error" id="zoneid-error"><?= htmlspecialchars($zoneid['error']) ?></div>
                    <?php elseif (isset($zoneid['address'])) : ?>
                        <?php if (!empty($zoneid['warning'])) : ?>
                            <div class="warning"><?= htmlspecialchars($zoneid['warning']) ?></div>
                        <?php endif; ?>
                        <?php $_zoneid_label = 'Zone ID: ' . $zoneid['address'] . (($zoneid['zone_id'] ?? null) !== null ? '%' . $zoneid['zone_id'] : ''); ?>
                        <dl class="zoneid-result"
                            data-history-source="zoneid"
                            data-history-active="1"
                            data-history-label="<?= htmlspecialchars($_zoneid_label) ?>">
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Address</dt>
                                <dd class="zoneid-result__value"><code><?= htmlspecialchars($zoneid['address']) ?></code></dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Zone ID</dt>
                                <dd class="zoneid-result__value">
                                    <?php if (($zoneid['zone_id'] ?? null) !== null) : ?>
                                        <code><?= htmlspecialchars($zoneid['zone_id']) ?></code>
                                    <?php else : ?>
                                        <span class="zoneid-result__empty" aria-label="no zone identifier">&mdash;</span>
                                    <?php endif; ?>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Link-local</dt>
                                <dd class="zoneid-result__value"><?= !empty($zoneid['is_link_local']) ? 'Yes' : 'No' ?></dd>
                            </div>
                        </dl>
                    <?php endif; ?>
                </div>
            </div>
