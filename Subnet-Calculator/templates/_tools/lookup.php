<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="lookup">
                <div class="overlap-panel">
                    <div class="overlap-title">IP Lookup<?= help_bubble('ipv4-lookup', 'For each IP, finds every CIDR that contains it. The "Deepest" column is the longest-prefix (most specific) match. Mixed IPv4/IPv6 inputs are allowed; CIDRs only match IPs of the same family. Caps: 100 CIDRs, 1000 IPs.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv4">
                        <div class="lookup-form-grid">
                            <label for="lookup_cidrs_v4" class="lookup-form-label">CIDRs <span class="lookup-form-hint">(one per line, max 100)</span></label>
                            <textarea id="lookup_cidrs_v4" name="lookup_cidrs" rows="4" class="multi-overlap-input"
                                      placeholder="10.0.0.0/8&#10;10.1.0.0/16&#10;2001:db8::/32"
                                      autocomplete="off" spellcheck="false"><?= htmlspecialchars($active_tab === 'ipv4' ? $lookup_cidrs_input : '') ?></textarea>
                            <label for="lookup_ips_v4" class="lookup-form-label">IPs <span class="lookup-form-hint">(one per line, max 1000)</span></label>
                            <textarea id="lookup_ips_v4" name="lookup_ips" rows="4" class="multi-overlap-input"
                                      placeholder="10.1.2.3&#10;8.8.8.8&#10;2001:db8::1"
                                      autocomplete="off" spellcheck="false"><?= htmlspecialchars($active_tab === 'ipv4' ? $lookup_ips_input : '') ?></textarea>
                        </div>
                        <div class="splitter-row">
                            <button type="submit" class="splitter-btn">Lookup</button>
                        </div>
                    </form>
                    <?php if ($active_tab === 'ipv4' && !empty($lookup['error'])) : ?>
                        <div class="error"><?= htmlspecialchars($lookup['error']) ?></div>
                    <?php elseif ($active_tab === 'ipv4' && isset($lookup['result'])) : ?>
                        <?php
                        $_lookup_ips_count   = count(array_filter(array_map('trim', explode("\n", $lookup_ips_input))));
                        $_lookup_cidrs_count = count(array_filter(array_map('trim', explode("\n", $lookup_cidrs_input))));
                        $_lookup_label = sprintf(
                            'Lookup: %d IP%s in %d CIDR%s',
                            $_lookup_ips_count,
                            $_lookup_ips_count !== 1 ? 's' : '',
                            $_lookup_cidrs_count,
                            $_lookup_cidrs_count !== 1 ? 's' : ''
                        );
                        ?>
                        <div class="lookup-results"
                             data-history-source="lookup"
                             data-history-active="1"
                             data-history-label="<?= htmlspecialchars($_lookup_label) ?>">
                            <button type="button" class="copy-all-btn" data-target="lookup">Copy All</button>
                            <div class="lookup-table-wrap">
                                <table class="lookup-table" aria-label="IP lookup results">
                                    <thead>
                                        <tr>
                                            <th scope="col">IP</th>
                                            <th scope="col">Deepest match</th>
                                            <th scope="col">All matches</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($lookup['result'] as $row) : ?>
                                            <tr>
                                                <td class="lookup-table__cell" data-label="IP"><code><?= htmlspecialchars($row['ip']) ?></code></td>
                                                <td class="lookup-table__cell" data-label="Deepest match">
                                                    <?php if ($row['deepest'] !== null) : ?>
                                                        <code><?= htmlspecialchars($row['deepest']) ?></code>
                                                    <?php else : ?>
                                                        <span class="lookup-table__empty" aria-label="no match">&mdash;</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="lookup-table__cell" data-label="All matches">
                                                    <?php if ($row['matches'] !== []) : ?>
                                                        <code><?= htmlspecialchars(implode(', ', $row['matches'])) ?></code>
                                                    <?php else : ?>
                                                        <span class="lookup-table__empty" aria-label="no matches">&mdash;</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="split-more"><?= count($lookup['result']) ?> IP<?= count($lookup['result']) !== 1 ? 's' : '' ?> looked up</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
