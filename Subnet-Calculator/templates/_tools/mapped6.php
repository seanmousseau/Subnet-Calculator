<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="mapped6">
                <div class="overlap-panel">
                    <div class="overlap-title">IPv4-mapped / NAT64 (mapped6)<?= help_bubble('ipv6-mapped6', 'Bidirectional convert between IPv4, IPv4-mapped IPv6 (::ffff:0:0/96, RFC 4291) and NAT64 IPv6 (64:ff9b::/96 default, RFC 6052). Accepts any of the three forms; renders all four representations. Custom NAT64 prefix supported via the Advanced disclosure (must be /96).') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="mapped6_input">Address<?= help_bubble('mapped6-input', 'Any one of: an IPv4 dotted-quad (192.0.2.1), an IPv4-mapped IPv6 (::ffff:192.0.2.1), or a NAT64 IPv6 within the supplied prefix (64:ff9b::c000:201).') ?></label>
                                <input type="text" id="mapped6_input" name="mapped6_input"
                                       value="<?= htmlspecialchars($mapped6_input ?? '') ?>"
                                       placeholder="192.0.2.1"
                                       autocomplete="off" spellcheck="false"
                                       <?= !empty($mapped6['error']) ? 'aria-invalid="true" aria-describedby="mapped6-error"' : '' ?>>
                            </div>
                        </div>
                        <details class="slaac-advanced"<?= ($mapped6_prefix_input ?? '') !== '' ? ' open' : '' ?>>
                            <summary>Advanced — custom NAT64 prefix</summary>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="mapped6_nat64_prefix">NAT64 prefix <span class="label-footnote">(/96 only)</span><?= help_bubble('mapped6-nat64-prefix', 'Optional NAT64 prefix in /96 form. Defaults to the well-known 64:ff9b::/96 (RFC 6052). Operator-supplied custom prefixes such as 2001:db8:1::/96 are accepted. Non-/96 prefixes are rejected.') ?></label>
                                    <input type="text" id="mapped6_nat64_prefix" name="mapped6_nat64_prefix"
                                           value="<?= htmlspecialchars($mapped6_prefix_input ?? '') ?>"
                                           placeholder="64:ff9b::/96"
                                           autocomplete="off" spellcheck="false">
                                </div>
                            </div>
                        </details>
                        <div class="splitter-row">
                            <button type="submit" class="splitter-btn">Convert</button>
                        </div>
                    </form>
                    <?php
                    // v3.5.0 Task 7 — NAT64 / DNS64 (RFC 6052 / 6147).
                    // The existing v3.4.0 mapped6 form (above) handles the
                    // /96-only IPv4 ↔ IPv4-mapped ↔ NAT64 conversions and
                    // is unchanged. The form below adds prefix-length-aware
                    // NAT64 (any of /32, /40, /48, /56, /64, /96) and DNS64
                    // AAAA synthesis as a separate <form>; submitting it
                    // posts $_POST['nat64_mode'] which sc_run_nat64() reads
                    // independently of $mapped6.
                    $_nat64_mode = $nat64_mode ?? 'encode';
                    $_nat64_pl   = ($nat64_pl_input ?? '') !== '' ? (int)$nat64_pl_input : 96;
                    ?>
                    <details class="slaac-advanced nat64-advanced"<?= !empty($nat64) ? ' open' : '' ?>>
                        <summary>NAT64 / DNS64 (any RFC 6052 prefix length)<?= help_bubble('nat64-toggle', 'Open for prefix-length-aware NAT64 (RFC 6052 §2.2 — /32, /40, /48, /56, /64, /96) and DNS64 AAAA synthesis (RFC 6147). The well-known prefix 64:ff9b::/96 is rejected for non-globally-unique IPv4 per RFC 6052 §3.1.') ?></summary>
                        <form method="post" novalidate>
                            <input type="hidden" name="tab" value="ipv6">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="nat64_mode">Mode<?= help_bubble('nat64-mode', 'encode embeds an IPv4 into a NAT64 prefix; decode extracts the IPv4 from a NAT64 IPv6; dns64 synthesises an AAAA record for an A record (RFC 6147).') ?></label>
                                    <select id="nat64_mode" name="nat64_mode">
                                        <option value="encode" <?= $_nat64_mode === 'encode' ? 'selected' : '' ?>>encode (IPv4 → NAT64)</option>
                                        <option value="decode" <?= $_nat64_mode === 'decode' ? 'selected' : '' ?>>decode (NAT64 → IPv4)</option>
                                        <option value="dns64"  <?= $_nat64_mode === 'dns64'  ? 'selected' : '' ?>>dns64 (A → AAAA)</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="nat64_pl">Prefix length<?= help_bubble('nat64-pl', 'One of 32, 40, 48, 56, 64, 96 per RFC 6052 §2.2. Defaults to /96 (matches DNS64 well-known prefix usage).') ?></label>
                                    <select id="nat64_pl" name="nat64_pl">
                                        <?php foreach ([32, 40, 48, 56, 64, 96] as $_pl) : ?>
                                            <option value="<?= $_pl ?>" <?= $_nat64_pl === $_pl ? 'selected' : '' ?>>/<?= $_pl ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="nat64_prefix">NAT64 prefix<?= help_bubble('nat64-prefix', 'IPv6 literal of the NAT64 prefix without the /PL suffix. Examples: 64:ff9b:: (well-known), 2001:db8::, 2001:db8:122::. Bits beyond the declared prefix length must be zero.') ?></label>
                                    <input type="text" id="nat64_prefix" name="nat64_prefix"
                                           value="<?= htmlspecialchars($nat64_prefix_input ?? '') ?>"
                                           placeholder="64:ff9b::"
                                           autocomplete="off" spellcheck="false">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="nat64_ipv4">IPv4 (encode)<?= help_bubble('nat64-ipv4-input', 'Dotted-quad IPv4 to embed. Globally-unique addresses only when using the well-known prefix 64:ff9b::/96 (RFC 6052 §3.1).') ?></label>
                                    <input type="text" id="nat64_ipv4" name="nat64_ipv4"
                                           value="<?= htmlspecialchars($nat64_ipv4_input ?? '') ?>"
                                           placeholder="192.0.2.33"
                                           autocomplete="off" spellcheck="false">
                                </div>
                                <div class="form-group">
                                    <label for="nat64_ipv6">NAT64 IPv6 (decode)<?= help_bubble('nat64-ipv6-input', 'IPv6 literal whose top bits match the NAT64 prefix. Example for /32: 2001:db8:c000:221::') ?></label>
                                    <input type="text" id="nat64_ipv6" name="nat64_ipv6"
                                           value="<?= htmlspecialchars($nat64_ipv6_input ?? '') ?>"
                                           placeholder="2001:db8:c000:221::"
                                           autocomplete="off" spellcheck="false">
                                </div>
                                <div class="form-group">
                                    <label for="nat64_a_record">A record (dns64)<?= help_bubble('nat64-a-record', 'IPv4 address from a DNS A record. DNS64 (RFC 6147) synthesises an AAAA by embedding it into the supplied NAT64 prefix.') ?></label>
                                    <input type="text" id="nat64_a_record" name="nat64_a_record"
                                           value="<?= htmlspecialchars($nat64_a_record_input ?? '') ?>"
                                           placeholder="192.0.2.33"
                                           autocomplete="off" spellcheck="false">
                                </div>
                            </div>
                            <div class="splitter-row">
                                <button type="submit" class="splitter-btn nat64-submit">Run NAT64 / DNS64</button>
                            </div>
                        </form>
                        <?php if (!empty($nat64['error'])) : ?>
                            <div class="error" id="nat64-error"><?= htmlspecialchars((string)$nat64['error']) ?></div>
                        <?php elseif (!empty($nat64) && (isset($nat64['ipv6']) || isset($nat64['ipv4']))) : ?>
                            <?php $_nat64_history = 'nat64: ' . (string)($nat64['ipv4'] ?? $nat64['ipv6'] ?? ''); ?>
                            <dl class="zoneid-result"
                                data-history-source="nat64"
                                data-history-active="1"
                                data-history-label="<?= htmlspecialchars($_nat64_history) ?>">
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">Mode</dt>
                                    <dd class="zoneid-result__value"><code><?= htmlspecialchars((string)($nat64['mode'] ?? '')) ?></code></dd>
                                </div>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">NAT64 prefix</dt>
                                    <dd class="zoneid-result__value"><code><?= htmlspecialchars((string)($nat64['nat64_prefix'] ?? '')) ?>/<?= (int)($nat64['prefix_length'] ?? 96) ?></code></dd>
                                </div>
                                <?php if (isset($nat64['ipv4'])) : ?>
                                    <div class="zoneid-result__row">
                                        <dt class="zoneid-result__label">IPv4</dt>
                                        <dd class="zoneid-result__value">
                                            <code><?= htmlspecialchars((string)$nat64['ipv4']) ?></code>
                                            <?= copy_button((string)$nat64['ipv4'], 'Copy IPv4 address') ?>
                                        </dd>
                                    </div>
                                <?php endif; ?>
                                <?php if (isset($nat64['ipv6'])) : ?>
                                    <div class="zoneid-result__row">
                                        <dt class="zoneid-result__label">IPv6 (NAT64 / AAAA)</dt>
                                        <dd class="zoneid-result__value">
                                            <code><?= htmlspecialchars((string)$nat64['ipv6']) ?></code>
                                            <?= copy_button((string)$nat64['ipv6'], 'Copy NAT64 IPv6') ?>
                                        </dd>
                                    </div>
                                <?php endif; ?>
                            </dl>
                        <?php endif; ?>
                    </details>
                    <?php if (!empty($mapped6['error'])) : ?>
                        <div class="error" id="mapped6-error"><?= htmlspecialchars((string)$mapped6['error']) ?></div>
                    <?php elseif (isset($mapped6['ipv4'])) : ?>
                        <?php $_mapped6_label = 'mapped6: ' . $mapped6['input']; ?>
                        <dl class="zoneid-result"
                            data-history-source="mapped6"
                            data-history-active="1"
                            data-history-label="<?= htmlspecialchars($_mapped6_label) ?>">
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">IPv4</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars((string)$mapped6['ipv4']) ?></code>
                                    <?= copy_button((string)$mapped6['ipv4'], 'Copy IPv4 address') ?>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">IPv4-mapped IPv6</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars((string)$mapped6['ipv4_mapped']) ?></code>
                                    <?= copy_button((string)$mapped6['ipv4_mapped'], 'Copy IPv4-mapped IPv6') ?>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">NAT64 IPv6</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars((string)$mapped6['nat64']) ?></code>
                                    <?= copy_button((string)$mapped6['nat64'], 'Copy NAT64 IPv6') ?>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">NAT64 prefix</dt>
                                <dd class="zoneid-result__value"><code><?= htmlspecialchars((string)$mapped6['nat64_prefix']) ?></code></dd>
                            </div>
                        </dl>
                    <?php endif; ?>
                </div>
            </div>
