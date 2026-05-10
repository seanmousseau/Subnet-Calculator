<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="session">
                <div class="overlap-panel">
                    <div class="overlap-title">Save &amp; Restore Session<?= help_bubble('vlsm-session', 'Saves your VLSM inputs to the server so you can restore them later via a short link. Sessions expire after the configured TTL. No account required.') ?></div>
                    <p class="session-ttl-notice">Saved sessions expire after <?= (int)$session_ttl_days ?> day<?= (int)$session_ttl_days === 1 ? '' : 's' ?>.</p>
                    <?php if ($session_save_id !== '') : ?>
                        <div class="overlap-result overlap-contains share-bar session-saved-bar">
                            <span class="share-label">Session saved. Share this link:</span>
                            <code class="share-url"><?= htmlspecialchars($share_base_server . $session_save_url) ?></code>
                            <button type="button" class="share-copy"
                                    data-copy="<?= htmlspecialchars($session_save_url) ?>">Copy</button>
                        </div>
                    <?php endif; ?>
                    <?php if ($session_error) : ?>
                        <div class="error"><?= htmlspecialchars($session_error) ?></div>
                    <?php endif; ?>
                    <div class="session-forms">
                        <form method="post" novalidate>
                            <input type="hidden" name="tab" value="vlsm">
                            <input type="hidden" name="vlsm_network" value="<?= htmlspecialchars($vlsm_network) ?>">
                            <input type="hidden" name="vlsm_cidr" value="<?= htmlspecialchars($vlsm_cidr_input) ?>">
                            <?php foreach ($vlsm_requirements as $req) : ?>
                                <input type="hidden" name="vlsm_name[]" value="<?= htmlspecialchars($req['name']) ?>">
                                <input type="hidden" name="vlsm_hosts[]" value="<?= htmlspecialchars((string)$req['hosts']) ?>">
                            <?php endforeach; ?>
                            <button type="submit" name="session_action" value="save" class="splitter-btn">Save Session</button>
                        </form>
                        <form method="get" novalidate>
                            <div class="overlap-inputs">
                                <input type="hidden" name="tab" value="vlsm">
                                <input type="text" name="s"
                                       value="<?= htmlspecialchars($session_load_id) ?>"
                                       placeholder="8-char session ID"
                                       autocomplete="off" spellcheck="false" maxlength="8"
                                       aria-label="Session ID">
                                <button type="submit" class="splitter-btn">Load</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
