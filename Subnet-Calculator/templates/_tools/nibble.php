<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="nibble">
                <div class="overlap-panel">
                    <div class="overlap-title">IPv6 nibble-boundary helper<?= help_bubble('ipv6-nibble', 'For any IPv6 prefix, surface the nibble-aligned neighbours: above (≤ input length, less specific) and below (≥ input length, more specific). Useful for ip6.arpa reverse-zone delegation planning where non-nibble lengths require an explicit nibble-boundary decision.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="nibble6_prefix">IPv6 prefix<?= help_bubble('nibble-prefix', 'Any IPv6 prefix in CIDR form, e.g. 2001:db8::/49. Host bits beyond the prefix length are zeroed before the neighbours are computed.') ?></label>
                                <input type="text" id="nibble6_prefix" name="nibble6_prefix"
                                       value="<?= htmlspecialchars($nibble6_prefix_input ?? '') ?>"
                                       placeholder="2001:db8::/49"
                                       autocomplete="off" spellcheck="false"
                                       <?= !empty($nibble6['error']) ? 'aria-invalid="true" aria-describedby="nibble6-error"' : '' ?>>
                            </div>
                        </div>
                        <div class="splitter-row">
                            <button type="submit" class="splitter-btn">Find neighbours</button>
                        </div>
                    </form>
                    <?php if (!empty($nibble6['error'])) : ?>
                        <div class="error" id="nibble6-error"><?= htmlspecialchars((string)$nibble6['error']) ?></div>
                    <?php elseif (!empty($nibble6['result'])) : ?>
                        <?php $_n = $nibble6['result']; ?>
                        <dl class="zoneid-result"
                            data-history-source="nibble6"
                            data-history-active="1"
                            data-history-label="<?= htmlspecialchars('Nibble neighbours: ' . (string)$_n['input']['prefix']) ?>">
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Input prefix</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars((string)$_n['input']['prefix']) ?></code>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Above (less specific)</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars((string)$_n['above']['prefix']) ?></code>
                                    <?= copy_button((string)$_n['above']['prefix'], 'Copy above prefix') ?>
                                    <?php $_c = (string)$_n['above']['contains_64s']; ?>
                                    <small><?php
                                        if ($_c === '1') {
                                            echo 'is itself a /64';
                                        } elseif ($_c === 'subset of /64') {
                                            echo 'smaller than a /64';
                                        } else {
                                            echo 'contains ' . htmlspecialchars($_c, ENT_QUOTES, 'UTF-8') . ' /64s';
                                        }
                                    ?></small>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Below (more specific)</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars((string)$_n['below']['prefix']) ?></code>
                                    <?= copy_button((string)$_n['below']['prefix'], 'Copy below prefix') ?>
                                    <?php $_c = (string)$_n['below']['contains_64s']; ?>
                                    <small><?php
                                        if ($_c === '1') {
                                            echo 'is itself a /64';
                                        } elseif ($_c === 'subset of /64') {
                                            echo 'smaller than a /64';
                                        } else {
                                            echo 'contains ' . htmlspecialchars($_c, ENT_QUOTES, 'UTF-8') . ' /64s';
                                        }
                                    ?></small>
                                </dd>
                            </div>
                        </dl>
                        <p><small>Nibble boundaries (multiples of 4) align with the per-nibble <code>ip6.arpa</code> reverse-DNS hierarchy. <em>Above</em> is the nearest nibble at-or-shorter than your input; <em>below</em> is the nearest nibble at-or-longer (capped at /128).</small></p>
                    <?php endif; ?>
                </div>
            </div>
