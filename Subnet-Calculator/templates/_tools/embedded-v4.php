<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="embedded-v4">
                <div class="overlap-panel">
                    <div class="overlap-title">Embedded IPv4 detector<?= help_bubble('ipv6-embedded-v4', 'Front door for v3.5.0 transition tools. Detects whether an IPv6 address embeds an IPv4 via IPv4-mapped, IPv4-compatible (deprecated), 6to4, Teredo, NAT64 well-known, or ISATAP — and extracts the embedded IPv4. Per-scheme deep-link buttons activate as their drawers ship.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="embedded_v4_input">IPv6 address<?= help_bubble('embedded-v4-input', 'Any IPv6 literal. Examples: ::ffff:192.0.2.1 (mapped), 2002:c000:0201:: (6to4), 64:ff9b::192.0.2.1 (NAT64 WKP), 2001:db8::200:5efe:c000:201 (ISATAP).') ?></label>
                                <input type="text" id="embedded_v4_input" name="embedded_v4_input"
                                       value="<?= htmlspecialchars($embedded_v4_input ?? '') ?>"
                                       placeholder="::ffff:192.0.2.1"
                                       autocomplete="off" spellcheck="false"
                                       <?= !empty($embedded_v4['error']) ? 'aria-invalid="true" aria-describedby="embedded-v4-error"' : '' ?>>
                            </div>
                        </div>
                        <div class="splitter-row">
                            <button type="submit" class="splitter-btn">Detect</button>
                        </div>
                    </form>
                    <?php if (!empty($embedded_v4['error'])) : ?>
                        <div class="error" id="embedded-v4-error"><?= htmlspecialchars((string)$embedded_v4['error']) ?></div>
                    <?php elseif (array_key_exists('scheme', $embedded_v4) && $embedded_v4['scheme'] !== null) : ?>
                        <?php
                        $_ev4_scheme_labels = [
                            'mapped'     => 'IPv4-mapped IPv6 (RFC 4291)',
                            'compatible' => 'IPv4-compatible IPv6 (RFC 4291, DEPRECATED)',
                            '6to4'       => '6to4 (RFC 3056)',
                            'teredo'     => 'Teredo (RFC 4380)',
                            'nat64-wkp'  => 'NAT64 well-known prefix (RFC 6052)',
                            'isatap'     => 'ISATAP (RFC 5214)',
                        ];
                        $_ev4_scheme    = (string)($embedded_v4['scheme'] ?? '');
                        $_ev4_label     = $_ev4_scheme === ''
                            ? 'No embedded IPv4 detected'
                            : ($_ev4_scheme_labels[$_ev4_scheme] ?? $_ev4_scheme);
                        $_ev4_history   = 'embedded-v4: ' . (string)$embedded_v4['input'];
                        $_ev4_route     = $embedded_v4['detail_route'] ?? null;
                        ?>
                        <dl class="zoneid-result"
                            data-history-source="embedded-v4"
                            data-history-active="1"
                            data-history-label="<?= htmlspecialchars($_ev4_history) ?>">
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Scheme</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars($_ev4_label) ?></code>
                                    <?php if (!empty($embedded_v4['deprecated'])) : ?>
                                        <span class="badge badge-private">deprecated</span>
                                    <?php endif; ?>
                                </dd>
                            </div>
                            <?php if (!empty($embedded_v4['ipv4'])) : ?>
                                <div class="zoneid-result__row">
                                    <dt class="zoneid-result__label">Embedded IPv4</dt>
                                    <dd class="zoneid-result__value">
                                        <code><?= htmlspecialchars((string)$embedded_v4['ipv4']) ?></code>
                                        <?= copy_button((string)$embedded_v4['ipv4'], 'Copy embedded IPv4') ?>
                                    </dd>
                                </div>
                            <?php endif; ?>
                            <?php if (is_array($embedded_v4['extra'] ?? null) && $embedded_v4['extra'] !== []) : ?>
                                <?php foreach ($embedded_v4['extra'] as $_ev4_key => $_ev4_val) : ?>
                                    <div class="zoneid-result__row">
                                        <dt class="zoneid-result__label"><?= htmlspecialchars((string)$_ev4_key) ?></dt>
                                        <dd class="zoneid-result__value">
                                            <code><?= htmlspecialchars(is_scalar($_ev4_val) ? (string)$_ev4_val : ((string)(json_encode($_ev4_val) ?: ''))) ?></code>
                                        </dd>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Open in tool</dt>
                                <dd class="zoneid-result__value">
                                    <?php if ($_ev4_route !== null && $_ev4_route !== '') : ?>
                                        <a class="splitter-btn" href="<?= htmlspecialchars((string)$_ev4_route) ?>">Open <?= htmlspecialchars($_ev4_scheme) ?> tool</a>
                                    <?php else : ?>
                                        <button type="button" class="splitter-btn" disabled aria-disabled="true" title="Per-scheme tool not yet available — lands in subsequent v3.5.0 PRs.">Open <?= htmlspecialchars($_ev4_scheme) ?> tool</button>
                                    <?php endif; ?>
                                </dd>
                            </div>
                        </dl>
                    <?php elseif (array_key_exists('scheme', $embedded_v4) && $embedded_v4['scheme'] === null) : ?>
                        <dl class="zoneid-result">
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Result</dt>
                                <dd class="zoneid-result__value"><code>No embedded IPv4 detected</code></dd>
                            </div>
                        </dl>
                    <?php endif; ?>
                </div>
            </div>
