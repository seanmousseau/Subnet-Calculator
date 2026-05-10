<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="prefix-plan">
                <div class="overlap-panel">
                    <div class="overlap-title">IPv6 prefix-delegation planner<?= help_bubble('ipv6-prefix-plan', 'Slice a delegated parent prefix (e.g. /48) into nibble-aligned child prefixes (e.g. /56) and report how much of the parent space is left over. GMP throughout — counts that overflow signed 64-bit ints (e.g. /32 → /128) print as "2^N".') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="prefix_plan6_parent">Parent IPv6 prefix<?= help_bubble('prefix-plan-parent', 'The delegated parent prefix in CIDR form, e.g. 2001:db8::/48. Host bits are zeroed before slicing.') ?></label>
                                <input type="text" id="prefix_plan6_parent" name="prefix_plan6_parent"
                                       value="<?= htmlspecialchars($prefix_plan6_parent_input ?? '') ?>"
                                       placeholder="2001:db8::/48"
                                       autocomplete="off" spellcheck="false"
                                       <?= !empty($prefix_plan6['error']) ? 'aria-invalid="true" aria-describedby="prefix-plan6-error"' : '' ?>>
                            </div>
                            <div class="form-group form-group--mask">
                                <label for="prefix_plan6_child_length">Child length<?= help_bubble('prefix-plan-child-length', 'Prefix length for each child block (1..128). Must be greater than the parent length. With nibble-align on, non-nibble values snap up to the next multiple of 4.') ?></label>
                                <input type="number" id="prefix_plan6_child_length" name="prefix_plan6_child_length"
                                       value="<?= htmlspecialchars($prefix_plan6_child_length_input ?? '') ?>"
                                       min="1" max="128" placeholder="56"
                                       autocomplete="off" spellcheck="false"
                                       <?= !empty($prefix_plan6['error']) ? 'aria-invalid="true" aria-describedby="prefix-plan6-error"' : '' ?>>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group form-group--mask">
                                <label for="prefix_plan6_count">Count<?= help_bubble('prefix-plan-count', 'How many child prefixes to allocate (≥ 1). Bounded by the parent: start_offset + count must not exceed the total available children.') ?></label>
                                <input type="number" id="prefix_plan6_count" name="prefix_plan6_count"
                                       value="<?= htmlspecialchars($prefix_plan6_count_input ?? '') ?>"
                                       min="1" placeholder="4"
                                       autocomplete="off" spellcheck="false"
                                       <?= !empty($prefix_plan6['error']) ? 'aria-invalid="true" aria-describedby="prefix-plan6-error"' : '' ?>>
                            </div>
                            <div class="form-group form-group--mask">
                                <label for="prefix_plan6_start_offset">Start offset<?= help_bubble('prefix-plan-start-offset', 'Skip the first N child prefixes before allocating. Default 0. Useful for resuming an in-progress delegation plan.') ?></label>
                                <input type="number" id="prefix_plan6_start_offset" name="prefix_plan6_start_offset"
                                       value="<?= htmlspecialchars($prefix_plan6_start_offset_input ?? '') ?>"
                                       min="0" placeholder="0"
                                       autocomplete="off" spellcheck="false">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group form-group--checkbox">
                                <label>
                                    <input type="checkbox" name="prefix_plan6_nibble_align" value="1"
                                           <?= ($prefix_plan6_nibble_align ?? true) ? 'checked' : '' ?>>
                                    Nibble-align child length<?= help_bubble('prefix-plan-nibble-align', 'When on, snap the child prefix length up to the next multiple of 4 so each child is a clean nibble boundary in the IPv6 address (easier for humans to read and DNS reverse-zones).') ?>
                                </label>
                            </div>
                        </div>
                        <div class="splitter-row">
                            <button type="submit" class="splitter-btn">Plan</button>
                        </div>
                    </form>
                    <?php if (!empty($prefix_plan6['error'])) : ?>
                        <div class="error" id="prefix-plan6-error"><?= htmlspecialchars((string)$prefix_plan6['error']) ?></div>
                    <?php elseif (!empty($prefix_plan6['result'])) : ?>
                        <?php
                        $_pp = $prefix_plan6['result'];
                        $_pp_history = 'Prefix plan: ' . (string)($_pp['parent']['prefix'] ?? '')
                            . ' → /' . (string)($_pp['normalized_child_length'] ?? '');
                        ?>
                        <dl class="zoneid-result"
                            data-history-source="prefix-plan6"
                            data-history-active="1"
                            data-history-label="<?= htmlspecialchars($_pp_history) ?>">
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Parent prefix</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars((string)$_pp['parent']['prefix']) ?></code>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Child length</dt>
                                <dd class="zoneid-result__value">
                                    <code>/<?= (int)$_pp['normalized_child_length'] ?></code>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Total children</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars((string)$_pp['parent']['total_children_str']) ?></code>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Free remaining</dt>
                                <dd class="zoneid-result__value">
                                    <code><?= htmlspecialchars((string)$_pp['free']['remaining_str']) ?></code>
                                </dd>
                            </div>
                        </dl>
                        <table class="vlsm-table" data-prefix-plan6-table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Prefix</th>
                                    <th>First</th>
                                    <th>Last</th>
                                    <th>/64s</th>
                                    <th aria-label="Copy"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($_pp['children'] as $_child) : ?>
                                <tr>
                                    <td><?= (int)$_child['index'] ?></td>
                                    <td><code><?= htmlspecialchars((string)$_child['prefix']) ?></code></td>
                                    <td><code><?= htmlspecialchars((string)$_child['first']) ?></code></td>
                                    <td><code><?= htmlspecialchars((string)$_child['last']) ?></code></td>
                                    <td><?= htmlspecialchars((string)$_child['contains_64s']) ?></td>
                                    <td><?= copy_button((string)$_child['prefix'], 'Copy child prefix') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p><small>Prefix-delegation planning is operator math, not auto-detection. The parent prefix and child length come from your delegation policy; nibble-aligned children print cleanly and align with DNS reverse zones.</small></p>
                    <?php endif; ?>
                </div>
            </div>
