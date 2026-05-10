<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="derive">
                <div class="overlap-panel">
                    <div class="overlap-title">Derive Address<?= help_bubble('ipv6-derive', 'Derives the IPv6 forms generated from a 48-bit MAC address per RFC 4291 §2.5.1: the modified EUI-64 interface identifier (with the U/L bit flipped), the link-local address (fe80:: + EUI-64), and the solicited-node multicast address (ff02::1:ff + the low 24 bits of the unicast address). Accepts colon, hyphen, Cisco dotted, or bare-hex MAC formats.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <label for="derive_mac" class="sr-only">MAC address</label>
                        <div class="splitter-row">
                            <input type="text" id="derive_mac" name="derive_mac" class="splitter-input"
                                   placeholder="00:24:b9:7e:ab:cd"
                                   value="<?= htmlspecialchars($derive_input) ?>"
                                   autocomplete="off" spellcheck="false"
                                   <?= !empty($derive['error']) ? 'aria-invalid="true" aria-describedby="derive-error"' : '' ?>>
                            <button type="submit" class="splitter-btn">Derive</button>
                        </div>
                    </form>
                    <?php if (!empty($derive['error'])) : ?>
                        <div class="error" id="derive-error"><?= htmlspecialchars($derive['error']) ?></div>
                    <?php elseif (isset($derive['eui64'])) : ?>
                        <?php if (!empty($derive['warning'])) : ?>
                            <div class="warning"><?= htmlspecialchars($derive['warning']) ?></div>
                        <?php endif; ?>
                        <?php $_derive_label = 'Derive: ' . ($derive['mac_canonical'] ?? ''); ?>
                        <dl class="derive-result"
                            data-history-source="derive"
                            data-history-active="1"
                            data-history-label="<?= htmlspecialchars($_derive_label) ?>">
                            <div class="derive-result__row">
                                <dt class="derive-result__label">MAC (canonical)</dt>
                                <dd class="derive-result__value">
                                    <code><?= htmlspecialchars((string)($derive['mac_canonical'] ?? '')) ?></code>
                                </dd>
                            </div>
                            <div class="derive-result__row">
                                <dt class="derive-result__label">EUI-64<?= help_bubble('ipv6-derive-eui64', 'Modified EUI-64 interface identifier — the U/L (universal/local) bit in the first MAC byte is inverted, then the 16-bit value 0xFFFE is inserted between the OUI and the NIC half (RFC 4291 §2.5.1).') ?></dt>
                                <dd class="derive-result__value">
                                    <code><?= htmlspecialchars((string)($derive['eui64'] ?? '')) ?></code>
                                    <?= copy_button((string)($derive['eui64'] ?? ''), 'Copy EUI-64 interface ID') ?>
                                </dd>
                            </div>
                            <div class="derive-result__row">
                                <dt class="derive-result__label">Link-local<?= help_bubble('ipv6-derive-ll', 'Link-local address — the fe80::/64 prefix concatenated with the EUI-64 interface identifier. Always assigned automatically to every IPv6-enabled interface (RFC 4291 §2.5.6).') ?></dt>
                                <dd class="derive-result__value">
                                    <code><?= htmlspecialchars((string)($derive['link_local'] ?? '')) ?></code>
                                    <?= copy_button((string)($derive['link_local'] ?? ''), 'Copy link-local address') ?>
                                </dd>
                            </div>
                            <div class="derive-result__row">
                                <dt class="derive-result__label">Solicited-node<?= help_bubble('ipv6-derive-sn', 'Solicited-node multicast address — ff02::1:ff followed by the low 24 bits of the unicast address. Used by IPv6 Neighbor Discovery so a host only listens for resolution requests targeted at its own address (RFC 4291 §2.7.1).') ?></dt>
                                <dd class="derive-result__value">
                                    <code><?= htmlspecialchars((string)($derive['solicited_node'] ?? '')) ?></code>
                                    <?= copy_button((string)($derive['solicited_node'] ?? ''), 'Copy solicited-node multicast address') ?>
                                </dd>
                            </div>
                        </dl>
                    <?php endif; ?>
                </div>
            </div>
