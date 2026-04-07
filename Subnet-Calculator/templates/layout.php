?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= htmlspecialchars($page_description) ?>">
    <meta property="og:title"       content="<?= htmlspecialchars($page_title) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($page_description) ?>">
    <meta property="og:type"        content="website">
    <meta property="og:url"         content="<?= $canonical_url ?>">
    <link rel="canonical" href="<?= $canonical_url ?>">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link rel="icon" type="image/webp" href="logo/favicon-32.webp">
    <link rel="icon" type="image/png"  href="logo/favicon-32.png">
    <meta property="og:image" content="<?= $canonical_url ?>/logo/logo.webp">
    <meta name="twitter:card" content="summary">
    <?php if ($turnstile_curl_missing): ?>
    <!-- sc-warning: Turnstile is configured but the PHP cURL extension is not loaded.
         Captcha verification is being skipped. Install php-curl to enable it. -->
    <?php endif; ?>
    <script nonce="<?= htmlspecialchars($csp_nonce) ?>">(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="assets/app.css">
    <?php if ($bg_override_style) echo '<style nonce="' . htmlspecialchars($csp_nonce) . '">' . $bg_override_style . '</style>'; ?>
    <?php if ($turnstile_active): ?>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>
</head>
<body>
<div class="card">
    <div class="title-row">
        <picture>
            <source srcset="logo/logo.webp" type="image/webp">
            <img src="logo/logo.png" alt="Subnet Calculator logo" class="logo">
        </picture>
        <h1><?= htmlspecialchars($page_title) ?></h1>
        <span class="version">v1.0.1</span>
        <button id="theme-toggle" class="theme-toggle" title="Toggle light/dark mode" aria-label="Switch to light mode">
            <svg class="icon-sun" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
            <svg class="icon-moon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        </button>
    </div>

    <div class="tabs" role="tablist" aria-label="IP version">
        <button class="tab-btn<?= $active_tab === 'ipv4' ? ' active' : '' ?>"
                role="tab" id="tab-ipv4"
                aria-selected="<?= $active_tab === 'ipv4' ? 'true' : 'false' ?>"
                aria-controls="panel-ipv4"
                data-tab="ipv4">IPv4</button>
        <button class="tab-btn<?= $active_tab === 'ipv6' ? ' active' : '' ?>"
                role="tab" id="tab-ipv6"
                aria-selected="<?= $active_tab === 'ipv6' ? 'true' : 'false' ?>"
                aria-controls="panel-ipv6"
                data-tab="ipv6">IPv6</button>
    </div>

    <!-- IPv4 Panel -->
    <div id="panel-ipv4" class="panel<?= $active_tab === 'ipv4' ? ' active' : '' ?>"
         role="tabpanel" aria-labelledby="tab-ipv4" tabindex="-1">
        <form method="post" novalidate>
            <input type="hidden" name="tab" value="ipv4">
            <div class="form-row">
                <div class="form-group">
                    <label for="ip">IP Address</label>
                    <input type="text" id="ip" name="ip"
                           placeholder="192.168.1.0 or 192.168.1.0/24"
                           value="<?= htmlspecialchars($input_ip) ?>"
                           autocomplete="off" spellcheck="false"
                           <?= $error ? 'aria-invalid="true" aria-describedby="ipv4-error"' : '' ?>>
                </div>
                <div class="form-group">
                    <label for="mask">Netmask</label>
                    <input type="text" id="mask" name="mask"
                           placeholder="/24 or 255.255.255.0"
                           value="<?= htmlspecialchars($input_mask) ?>"
                           autocomplete="off" spellcheck="false"
                           <?= $error ? 'aria-invalid="true" aria-describedby="ipv4-error"' : '' ?>>
                </div>
            </div>
            <div class="btn-row">
                <button type="submit">Calculate</button>
                <a href="?" class="btn reset">Reset</a>
            </div>
            <?php if ($form_protection === 'honeypot'): ?>
                <input type="text" name="url" class="sc-honeypot" tabindex="-1" autocomplete="off" value="">
            <?php endif; ?>
            <?php if ($turnstile_active): ?>
                <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($turnstile_site_key) ?>"></div>
            <?php endif; ?>
        </form>

        <?php if ($error): ?>
            <div class="error" id="ipv4-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($result): ?>
            <div class="results">
                <div class="results-header">Results</div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Subnet (CIDR)</span>
                    <span class="result-value"><?= htmlspecialchars($result['network_cidr']) ?></span>
                </div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Netmask (CIDR)</span>
                    <span class="result-value"><?= htmlspecialchars($result['netmask_cidr']) ?></span>
                </div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Netmask (Octet)</span>
                    <span class="result-value"><?= htmlspecialchars($result['netmask_octet']) ?></span>
                </div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Wildcard Mask</span>
                    <span class="result-value"><?= htmlspecialchars($result['wildcard']) ?></span>
                </div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">First Usable IP</span>
                    <span class="result-value"><?= htmlspecialchars($result['first_usable']) ?></span>
                </div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Last Usable IP</span>
                    <span class="result-value"><?= htmlspecialchars($result['last_usable']) ?></span>
                </div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Broadcast IP</span>
                    <span class="result-value"><?= htmlspecialchars($result['broadcast']) ?></span>
                </div>
                <div class="result-row hosts-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Usable IPs</span>
                    <span class="result-value"><?= number_format($result['usable_hosts']) ?></span>
                </div>
                <?php $ip4type = get_ipv4_type($input_ip); ?>
                <div class="result-row">
                    <span class="result-label">Address Type</span>
                    <span class="result-value"><span class="badge badge-<?= type_badge_class($ip4type) ?>"><?= htmlspecialchars($ip4type) ?></span></span>
                </div>
            </div>
            <?php if ($show_share_bar): ?>
            <div class="share-bar">
                <span class="share-label">Share</span>
                <code class="share-url"><?= htmlspecialchars($share_url_abs) ?></code>
                <button type="button" class="share-copy" data-copy="<?= htmlspecialchars($share_url) ?>">Copy</button>
            </div>
            <?php endif; ?>
            <div class="splitter">
                <div class="splitter-title">Split Subnet</div>
                <form method="post" class="splitter-form">
                    <input type="hidden" name="tab" value="ipv4">
                    <input type="hidden" name="ip" value="<?= htmlspecialchars($input_ip) ?>">
                    <input type="hidden" name="mask" value="<?= htmlspecialchars($input_mask) ?>">
                    <div class="splitter-row">
                        <span class="splitter-label">Split into</span>
                        <input type="text" name="split_prefix" class="splitter-input"
                               placeholder="/25" value="<?= htmlspecialchars($input_split_prefix) ?>"
                               autocomplete="off" spellcheck="false"
                               <?= $split_error ? 'aria-invalid="true" aria-describedby="split-error-ipv4"' : '' ?>>
                        <button type="submit" class="splitter-btn">Split</button>
                    </div>
                </form>
                <?php if ($split_error): ?>
                    <div class="error" id="split-error-ipv4"><?= htmlspecialchars($split_error) ?></div>
                <?php elseif ($split_result && $split_result['showing'] > 0): ?>
                    <div class="split-list">
                        <?php foreach ($split_result['subnets'] as $s): ?>
                            <div class="split-item" tabindex="0" role="button" data-copy="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></div>
                        <?php endforeach; ?>
                        <?php if ($split_result['total'] > $split_result['showing']): ?>
                            <div class="split-more">+&nbsp;<?= number_format($split_result['total'] - $split_result['showing']) ?> more</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- IPv6 Panel -->
    <div id="panel-ipv6" class="panel<?= $active_tab === 'ipv6' ? ' active' : '' ?>"
         role="tabpanel" aria-labelledby="tab-ipv6" tabindex="-1">
        <form method="post" novalidate>
            <input type="hidden" name="tab" value="ipv6">
            <div class="form-row">
                <div class="form-group">
                    <label for="ipv6">IPv6 Address</label>
                    <input type="text" id="ipv6" name="ipv6"
                           placeholder="2001:db8::1 or 2001:db8::/32"
                           value="<?= htmlspecialchars($input_ipv6) ?>"
                           autocomplete="off" spellcheck="false"
                           <?= $error6 ? 'aria-invalid="true" aria-describedby="ipv6-error"' : '' ?>>
                </div>
                <div class="form-group form-group-narrow">
                    <label for="prefix">Prefix</label>
                    <input type="text" id="prefix" name="prefix"
                           placeholder="/64"
                           value="<?= htmlspecialchars($input_prefix) ?>"
                           autocomplete="off" spellcheck="false"
                           <?= $error6 ? 'aria-invalid="true" aria-describedby="ipv6-error"' : '' ?>>
                </div>
            </div>
            <div class="btn-row">
                <button type="submit">Calculate</button>
                <a href="?tab=ipv6" class="btn reset">Reset</a>
            </div>
            <?php if ($form_protection === 'honeypot'): ?>
                <input type="text" name="url" class="sc-honeypot" tabindex="-1" autocomplete="off" value="">
            <?php endif; ?>
            <?php if ($turnstile_active): ?>
                <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($turnstile_site_key) ?>"></div>
            <?php endif; ?>
        </form>

        <?php if ($error6): ?>
            <div class="error" id="ipv6-error"><?= htmlspecialchars($error6) ?></div>
        <?php endif; ?>

        <?php if ($result6): ?>
            <div class="results">
                <div class="results-header">Results</div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Network (CIDR)</span>
                    <span class="result-value"><?= htmlspecialchars($result6['network_cidr']) ?></span>
                </div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Prefix Length</span>
                    <span class="result-value"><?= htmlspecialchars($result6['prefix']) ?></span>
                </div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">First IP</span>
                    <span class="result-value"><?= htmlspecialchars($result6['first_ip']) ?></span>
                </div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Last IP</span>
                    <span class="result-value"><?= htmlspecialchars($result6['last_ip']) ?></span>
                </div>
                <div class="result-row hosts-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Total Addresses</span>
                    <span class="result-value"><?= htmlspecialchars($result6['total']) ?></span>
                </div>
                <?php $ip6type = get_ipv6_type($input_ipv6); ?>
                <div class="result-row">
                    <span class="result-label">Address Type</span>
                    <span class="result-value"><span class="badge badge-<?= type_badge_class($ip6type) ?>"><?= htmlspecialchars($ip6type) ?></span></span>
                </div>
            </div>
            <?php if ($show_share_bar): ?>
            <div class="share-bar">
                <span class="share-label">Share</span>
                <code class="share-url"><?= htmlspecialchars($share_url_abs) ?></code>
                <button type="button" class="share-copy" data-copy="<?= htmlspecialchars($share_url) ?>">Copy</button>
            </div>
            <?php endif; ?>
            <div class="splitter">
                <div class="splitter-title">Split Subnet</div>
                <form method="post" class="splitter-form">
                    <input type="hidden" name="tab" value="ipv6">
                    <input type="hidden" name="ipv6" value="<?= htmlspecialchars($input_ipv6) ?>">
                    <input type="hidden" name="prefix" value="<?= htmlspecialchars($input_prefix) ?>">
                    <div class="splitter-row">
                        <span class="splitter-label">Split into</span>
                        <input type="text" name="split_prefix6" class="splitter-input"
                               placeholder="/65" value="<?= htmlspecialchars($input_split_prefix6) ?>"
                               autocomplete="off" spellcheck="false"
                               <?= $split_error6 ? 'aria-invalid="true" aria-describedby="split-error-ipv6"' : '' ?>>
                        <button type="submit" class="splitter-btn">Split</button>
                    </div>
                </form>
                <?php if ($split_error6): ?>
                    <div class="error" id="split-error-ipv6"><?= htmlspecialchars($split_error6) ?></div>
                <?php elseif ($split_result6 && $split_result6['showing'] > 0): ?>
                    <div class="split-list">
                        <?php foreach ($split_result6['subnets'] as $s): ?>
                            <div class="split-item" tabindex="0" role="button" data-copy="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></div>
                        <?php endforeach; ?>
                        <?php
                            $total6   = $split_result6['total'];
                            $showing6 = $split_result6['showing'];
                            $has_more6 = is_numeric($total6) ? ($showing6 < (int)$total6) : true;
                            $more_label6 = is_numeric($total6) ? number_format((int)$total6 - $showing6) : $total6 . ' total';
                        ?>
                        <?php if ($has_more6): ?>
                            <div class="split-more">+&nbsp;<?= $more_label6 ?> more</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <footer>
        <a href="https://github.com/seanmousseau/Subnet-Calculator" target="_blank" rel="noopener noreferrer">github.com/seanmousseau/Subnet-Calculator</a>
    </footer>
</div>

<div id="toast" class="toast" role="status" aria-live="polite" aria-atomic="true">Copied!</div>

<script src="assets/app.js"></script>
</body>
</html>
