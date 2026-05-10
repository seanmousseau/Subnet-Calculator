<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="pmtu">
                <div class="overlap-panel">
                    <div class="overlap-title">IPv6 PMTU helper<?= help_bubble('ipv6-pmtu', 'Compute the effective payload and per-fragment breakdown for an IPv6 packet over a path with the given MTU and extension-header overhead. Surfaces RFC 8200 §5 minimum link MTU (1280) warnings, the rule that routers do not fragment IPv6, and the RFC 8200 §3 fixed 40-byte header. Per-fragment payload is rounded down to an 8-byte boundary; the last fragment carries the remainder with M=0.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <div class="form-row">
                            <div class="form-group form-group--mask">
                                <label for="pmtu6_path_mtu">Path MTU (bytes)<?= help_bubble('pmtu6-path-mtu', 'Path MTU in bytes. The IPv6 minimum link MTU is 1280 (RFC 8200 §5); values below that are flagged but still computed. Common values: 1280 (IPv6 minimum), 1500 (Ethernet), 9000 (jumbo frame).') ?></label>
                                <input type="number" id="pmtu6_path_mtu" name="pmtu6_path_mtu"
                                       value="<?= htmlspecialchars($pmtu6_path_mtu_input ?? '') ?>"
                                       min="1"
                                       placeholder="1500"
                                       autocomplete="off"
                                       <?= !empty($pmtu6['error']) ? 'aria-invalid="true" aria-describedby="pmtu6-error"' : '' ?>>
                            </div>
                            <div class="form-group form-group--mask">
                                <label for="pmtu6_payload_size">Payload size (bytes)<?= help_bubble('pmtu6-payload-size', 'Upper-layer payload to send (bytes). Zero is allowed. If the payload exceeds the effective per-packet payload, the helper computes the per-fragment breakdown.') ?></label>
                                <input type="number" id="pmtu6_payload_size" name="pmtu6_payload_size"
                                       value="<?= htmlspecialchars($pmtu6_payload_size_input ?? '') ?>"
                                       min="0"
                                       placeholder="3000"
                                       autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label for="pmtu6_extension_headers">Extension headers (bytes, comma-separated)<?= help_bubble('pmtu6-extension-headers', 'Optional list of extension-header sizes in bytes, separated by commas. Each value must be a positive multiple of 8. Example: <code>8,8</code> for Hop-by-Hop + Routing. The 8-byte Fragment header (RFC 8200 §4.5) is added automatically per fragment when fragmentation is needed.') ?></label>
                                <input type="text" id="pmtu6_extension_headers" name="pmtu6_extension_headers"
                                       value="<?= htmlspecialchars($pmtu6_extension_headers_input ?? '') ?>"
                                       placeholder="e.g. 8,8 for HBH+routing"
                                       autocomplete="off" spellcheck="false">
                            </div>
                        </div>
                        <div class="splitter-row">
                            <button type="submit" class="splitter-btn">Compute</button>
                        </div>
                    </form>
                    <?php if (!empty($pmtu6['error'])) : ?>
                        <div class="error" id="pmtu6-error"><?= htmlspecialchars((string)$pmtu6['error']) ?></div>
                    <?php elseif (!empty($pmtu6['result'])) : ?>
                        <?php
                        $_pmtu_r       = $pmtu6['result'];
                        $_pmtu_history = sprintf(
                            'pmtu: %d / %d',
                            (int)$_pmtu_r['path_mtu'],
                            (int)$_pmtu_r['payload_size']
                        );
                        ?>
                        <dl class="zoneid-result"
                            data-history-source="pmtu"
                            data-history-active="1"
                            data-history-label="<?= htmlspecialchars($_pmtu_history) ?>">
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Path MTU</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= (int)$_pmtu_r['path_mtu'] ?> bytes</code>
                                    <?php if (!$_pmtu_r['meets_minimum']) : ?>
                                        <small> (below IPv6 minimum 1280)</small>
                                    <?php endif; ?>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Fixed header</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= (int)$_pmtu_r['fixed_header'] ?> bytes</code>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Extension overhead</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= (int)$_pmtu_r['extension_overhead'] ?> bytes</code>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Total overhead</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= (int)$_pmtu_r['total_overhead'] ?> bytes</code>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Effective payload</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= (int)$_pmtu_r['effective_payload'] ?> bytes</code>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Payload size</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= (int)$_pmtu_r['payload_size'] ?> bytes</code>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Needs fragmentation</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= $_pmtu_r['needs_fragmentation'] ? 'yes' : 'no' ?></code>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Fragment count</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= (int)$_pmtu_r['fragment_count'] ?></code>
                                </dd>
                            </div>
                        </dl>
                        <?php if ($_pmtu_r['needs_fragmentation']) : ?>
                            <table class="vlsm-table" style="margin-top:1rem;">
                                <thead>
                                    <tr>
                                        <th scope="col">#</th>
                                        <th scope="col">Offset (8-byte units)</th>
                                        <th scope="col">M-bit</th>
                                        <th scope="col">Payload bytes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($_pmtu_r['fragments'] as $_frag_i => $_frag) : ?>
                                        <tr>
                                            <td><?= (int)$_frag_i + 1 ?></td>
                                            <td><code><?= (int)$_frag['offset'] ?></code></td>
                                            <td><code><?= (int)$_frag['m_bit'] ?></code></td>
                                            <td><code><?= (int)$_frag['payload_bytes'] ?></code></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                        <?php if (!empty($_pmtu_r['notes'])) : ?>
                            <ul class="zoneid-notes" style="margin-top:1rem;">
                                <?php foreach ($_pmtu_r['notes'] as $_pmtu_note) : ?>
                                    <li><?= htmlspecialchars((string)$_pmtu_note) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
