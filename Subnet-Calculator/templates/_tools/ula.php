<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="ula">
                <div class="overlap-panel">
                    <div class="overlap-title">IPv6 ULA Prefix Generator (RFC 4193)</div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <div class="form-row ula-form-row">
                            <div class="form-group">
                                <label for="ula_global_id">Global ID <span class="label-footnote">(optional 10 hex chars)</span><?= help_bubble('ula-global-id', 'A 40-bit hex value used as the globally unique portion of the ULA prefix (RFC 4193). Leave blank to generate one pseudo-randomly from the current timestamp.') ?></label>
                                <input type="text" id="ula_global_id" name="ula_global_id"
                                       value="<?= htmlspecialchars($ula_global_id_input) ?>"
                                       placeholder="e.g. 1a2b3c4d5e (random if blank)"
                                       autocomplete="off" spellcheck="false" maxlength="10">
                            </div>
                            <div class="ula-generate-wrap">
                                <button type="submit" name="ula_generate" value="1" class="splitter-btn">Generate</button>
                            </div>
                        </div>
                    </form>
                    <?php if (!empty($ula['error'])) : ?>
                        <div class="error"><?= htmlspecialchars($ula['error']) ?></div>
                    <?php elseif (isset($ula['result'])) : ?>
                        <?php $_ula_label = 'ULA: ' . (string)($ula['result']['prefix'] ?? ''); ?>
                        <div class="ula-result"
                             data-history-source="ula"
                             data-history-active="1"
                             data-history-label="<?= htmlspecialchars($_ula_label) ?>">
                            <div class="overlap-result overlap-contains"><?= htmlspecialchars($ula['result']['prefix'] ?? '') ?></div>
                            <div class="ula-meta">
                                <span>Global ID: <code><?= htmlspecialchars($ula['result']['global_id'] ?? '') ?></code></span>
                                <span>Available /64s: <strong><?= format_number((int)($ula['result']['available_64s'] ?? 0)) ?></strong></span>
                            </div>
                            <?php if (!empty($ula['result']['example_64s'])) : ?>
                            <div class="split-list split-list--mt">
                                <button type="button" class="copy-all-btn" data-target="ula">Copy All</button>
                                <?php foreach ($ula['result']['example_64s'] as $ex64) : ?>
                                    <div class="split-item" tabindex="0" role="button" data-copy="<?= htmlspecialchars($ex64) ?>">
                                        <span class="split-subnet-text"><?= htmlspecialchars($ex64) ?></span>
                                        <?= copy_button($ex64, 'Copy ' . $ex64) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
