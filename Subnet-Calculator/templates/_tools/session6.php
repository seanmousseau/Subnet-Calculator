<?php declare(strict_types=1); ?>
            <div class="tool-panel" data-tool="session6">
                <div class="overlap-panel">
                    <div class="overlap-title">Save &amp; Restore IPv6 Session<?= help_bubble('vlsm6-session', 'Saves your IPv6 VLSM inputs to the server so you can restore them later via a short link. Sessions expire after the configured TTL.') ?></div>
                    <p class="session-ttl-notice">Saved sessions expire after <?= (int)$session_ttl_days ?> day<?= (int)$session_ttl_days === 1 ? '' : 's' ?>.</p>
                    <?php if ($session_save_id !== '' && $active_tab === 'vlsm6') : ?>
                        <div class="overlap-result overlap-contains share-bar session-saved-bar">
                            <span class="share-label">Session saved. Share this link:</span>
                            <code class="share-url"><?= htmlspecialchars($share_base_server . $session_save_url) ?></code>
                            <button type="button" class="share-copy"
                                    data-copy="<?= htmlspecialchars($session_save_url) ?>">Copy</button>
                        </div>
                    <?php endif; ?>
                    <?php if ($session_error && $active_tab === 'vlsm6') : ?>
                        <div class="error"><?= htmlspecialchars($session_error) ?></div>
                    <?php endif; ?>
                    <div class="session-forms">
                        <?php if ($vlsm6_requirements !== []) : ?>
                        <form method="post" novalidate>
                            <input type="hidden" name="tab" value="vlsm6">
                            <input type="hidden" name="session_type" value="ipv6">
                            <input type="hidden" name="vlsm6_network" value="<?= htmlspecialchars($vlsm6_network) ?>">
                            <input type="hidden" name="vlsm6_cidr" value="<?= htmlspecialchars($vlsm6_cidr_input) ?>">
                            <?php foreach ($vlsm6_requirements as $req6s) : ?>
                                <input type="hidden" name="vlsm6_name[]"  value="<?= htmlspecialchars($req6s['name']) ?>">
                                <input type="hidden" name="vlsm6_hosts[]" value="<?= htmlspecialchars((string)$req6s['hosts']) ?>">
                            <?php endforeach; ?>
                            <button type="submit" name="session_action" value="save" class="splitter-btn">Save Session</button>
                        </form>
                        <?php endif; ?>
                        <form method="get" novalidate>
                            <div class="overlap-inputs">
                                <input type="hidden" name="tab" value="vlsm6">
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
