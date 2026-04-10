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
    <link rel="icon" type="image/webp" href="assets/favicon-32.webp">
    <link rel="icon" type="image/png"  href="assets/favicon-32.png">
    <meta property="og:image" content="<?= $canonical_url ?>/assets/logo.webp">
    <meta name="twitter:card" content="summary">
    <?php if ($turnstile_curl_missing) : ?>
    <!-- sc-warning: Turnstile is configured but the PHP cURL extension is not loaded.
         Captcha verification is being skipped. Install php-curl to enable it. -->
    <?php endif; ?>
    <script nonce="<?= htmlspecialchars($csp_nonce) ?>">(function(){var t=localStorage.getItem('theme')||(window.matchMedia('(prefers-color-scheme: light)').matches?'light':null);if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="assets/app.css?v=<?= $app_version ?>">
    <?php if ($bg_override_style) {
        echo '<style nonce="' . htmlspecialchars($csp_nonce) . '">' . $bg_override_style . '</style>';
    } ?>
    <?php if ($turnstile_active) : ?>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>
</head>
<body>
<div class="card">
    <div class="title-row">
        <picture>
            <source srcset="assets/logo.webp" type="image/webp">
            <img src="assets/logo.png" alt="Subnet Calculator logo" class="logo">
        </picture>
        <h1><?= htmlspecialchars($page_title) ?></h1>
        <span class="version">v<?= htmlspecialchars($app_version) ?></span>
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
        <button class="tab-btn<?= $active_tab === 'vlsm' ? ' active' : '' ?>"
                role="tab" id="tab-vlsm"
                aria-selected="<?= $active_tab === 'vlsm' ? 'true' : 'false' ?>"
                aria-controls="panel-vlsm"
                data-tab="vlsm">VLSM</button>
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
            <?php if ($form_protection === 'honeypot') : ?>
                <input type="text" name="url" class="sc-honeypot" tabindex="-1" autocomplete="off" value="">
            <?php endif; ?>
            <?php if ($turnstile_active) : ?>
                <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($turnstile_site_key) ?>"></div>
            <?php endif; ?>
        </form>

        <?php if ($error) : ?>
            <div class="error" id="ipv4-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($result) : ?>
            <div class="results" aria-live="polite" aria-atomic="false">
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
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Address Type</span>
                    <span class="result-value"><span class="badge badge-<?= type_badge_class($ip4type) ?>"><?= htmlspecialchars($ip4type) ?></span></span>
                </div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Reverse DNS Zone</span>
                    <span class="result-value"><?= htmlspecialchars($result['ptr_zone']) ?></span>
                </div>
            </div>
            <?php
            $bin_cidr  = (int)ltrim($result['netmask_cidr'], '/');
            $bin_net   = array_map(fn($o) => sprintf('%08b', (int)$o), explode('.', explode('/', $result['network_cidr'])[0]));
            $bin_mask  = array_map(fn($o) => sprintf('%08b', (int)$o), explode('.', $result['netmask_octet']));
            ?>
            <details class="binary-details">
                <summary>Binary Representation</summary>
                <div class="binary-grid">
                    <span class="bin-label">Network</span>
                    <code class="bin-value"><?php
                    foreach ($bin_net as $i => $octet) :
                        $net_bits = max(0, min(8, $bin_cidr - $i * 8));
                        ?><span class="bin-net"><?= substr($octet, 0, $net_bits) ?></span><span class="bin-host"><?= substr($octet, $net_bits) ?></span><?php
if ($i < 3) {
    echo '.';
}
                    endforeach;
                    ?></code>
                    <span class="bin-label">Mask</span>
                    <code class="bin-value"><?= implode('.', $bin_mask) ?></code>
                </div>
                <div class="bin-boundary">Network: <?= $bin_cidr ?> bits &nbsp;|&nbsp; Host: <?= 32 - $bin_cidr ?> bits</div>
            </details>
            <?php if ($show_share_bar) : ?>
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
                <?php if ($split_error) : ?>
                    <div class="error" id="split-error-ipv4"><?= htmlspecialchars($split_error) ?></div>
                <?php elseif ($split_result && $split_result['showing'] > 0) : ?>
                    <div class="split-list">
                        <button type="button" class="copy-all-btn" data-target="split">Copy All</button>
                        <?php foreach ($split_result['subnets'] as $s) : ?>
                            <div class="split-item" tabindex="0" role="button" data-copy="<?= htmlspecialchars($s) ?>">
                                <span class="split-subnet-text"><?= htmlspecialchars($s) ?></span>
                                <button type="button" class="subnet-copy" data-copy="<?= htmlspecialchars($s) ?>" aria-label="Copy <?= htmlspecialchars($s) ?>">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                </button>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($split_result['total'] > $split_result['showing']) : ?>
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
            <?php if ($form_protection === 'honeypot') : ?>
                <input type="text" name="url" class="sc-honeypot" tabindex="-1" autocomplete="off" value="">
            <?php endif; ?>
            <?php if ($turnstile_active) : ?>
                <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($turnstile_site_key) ?>"></div>
            <?php endif; ?>
        </form>

        <?php if ($error6) : ?>
            <div class="error" id="ipv6-error"><?= htmlspecialchars($error6) ?></div>
        <?php endif; ?>

        <?php if ($result6) : ?>
            <div class="results" aria-live="polite" aria-atomic="false">
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
                    <span class="result-value"><?= htmlspecialchars(is_numeric($result6['total']) ? number_format((int)$result6['total']) : $result6['total']) ?></span>
                </div>
                <?php $ip6type = get_ipv6_type($input_ipv6); ?>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Address Type</span>
                    <span class="result-value"><span class="badge badge-<?= type_badge_class($ip6type) ?>"><?= htmlspecialchars($ip6type) ?></span></span>
                </div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Reverse DNS Zone</span>
                    <span class="result-value"><?= htmlspecialchars($result6['ptr_zone']) ?></span>
                </div>
            </div>
            <?php
            try {
                $bin6_prefix_int = (int)ltrim($result6['prefix'], '/');
                $bin6_net_ip     = explode('/', $result6['network_cidr'])[0];
                $bin128 = str_pad(gmp_strval(ipv6_to_gmp($bin6_net_ip), 2), 128, '0', STR_PAD_LEFT);
                $bin6_groups     = str_split($bin128, 8);
                $hex32           = str_pad(gmp_strval(ipv6_to_gmp($bin6_net_ip), 16), 32, '0', STR_PAD_LEFT);
                $hex6_groups     = str_split($hex32, 4);
                $net_nibbles     = (int)floor($bin6_prefix_int / 4);
                $bin6_ok         = true;
            } catch (\Exception $e) {
                $bin6_ok = false;
            }
            if (isset($bin6_ok) && $bin6_ok) : ?>
            <details class="binary-details">
                <summary>Binary / Hex Representation</summary>
                <div class="binary-grid">
                    <span class="bin-label">Hex</span>
                    <code class="bin-value"><?php
                    foreach ($hex6_groups as $gi => $group) :
                        $start_nibble = $gi * 4;
                        foreach (str_split($group) as $ni => $nibble) :
                            $abs_nibble = $start_nibble + $ni;
                            $cls = $abs_nibble < $net_nibbles ? 'bin-net' : 'bin-host';
                            echo '<span class="' . $cls . '">' . htmlspecialchars($nibble) . '</span>';
                        endforeach;
                        if ($gi < 7) {
                            echo ':';
                        }
                    endforeach;
                    ?></code>
                    <span class="bin-label">Binary</span>
                    <code class="bin-value bin-v6"><?php
                    foreach ($bin6_groups as $gi => $byte) :
                        $start_bit = $gi * 8;
                        for ($bi = 0; $bi < 8; $bi++) :
                            $abs_bit = $start_bit + $bi;
                            $cls = $abs_bit < $bin6_prefix_int ? 'bin-net' : 'bin-host';
                            echo '<span class="' . $cls . '">' . $byte[$bi] . '</span>';
                        endfor;
                        if ($gi < 15 && ($gi + 1) % 2 === 0) {
                            echo '.';
                        }
                    endforeach;
                    ?></code>
                </div>
                <div class="bin-boundary">Network: <?= $bin6_prefix_int ?> bits &nbsp;|&nbsp; Host: <?= 128 - $bin6_prefix_int ?> bits</div>
            </details>
            <?php endif; ?>
            <?php if ($show_share_bar) : ?>
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
                <?php if ($split_error6) : ?>
                    <div class="error" id="split-error-ipv6"><?= htmlspecialchars($split_error6) ?></div>
                <?php elseif ($split_result6 && $split_result6['showing'] > 0) : ?>
                    <div class="split-list">
                        <button type="button" class="copy-all-btn" data-target="split">Copy All</button>
                        <?php foreach ($split_result6['subnets'] as $s) : ?>
                            <div class="split-item" tabindex="0" role="button" data-copy="<?= htmlspecialchars($s) ?>">
                                <span class="split-subnet-text"><?= htmlspecialchars($s) ?></span>
                                <button type="button" class="subnet-copy" data-copy="<?= htmlspecialchars($s) ?>" aria-label="Copy <?= htmlspecialchars($s) ?>">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                </button>
                            </div>
                        <?php endforeach; ?>
                        <?php
                            $total6   = $split_result6['total'];
                            $showing6 = $split_result6['showing'];
                            $has_more6 = is_numeric($total6) ? ($showing6 < (int)$total6) : true;
                            $more_label6 = is_numeric($total6) ? number_format((int)$total6 - $showing6) : $total6 . ' total';
                        ?>
                        <?php if ($has_more6) : ?>
                            <div class="split-more">+&nbsp;<?= htmlspecialchars($more_label6) ?> more</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- VLSM Panel -->
    <div id="panel-vlsm" class="panel<?= $active_tab === 'vlsm' ? ' active' : '' ?>"
         role="tabpanel" aria-labelledby="tab-vlsm" tabindex="-1">
        <form method="post" class="vlsm-form" novalidate>
            <input type="hidden" name="tab" value="vlsm">
            <div class="form-row">
                <div class="form-group">
                    <label for="vlsm_network">Parent Network</label>
                    <input type="text" id="vlsm_network" name="vlsm_network"
                           value="<?= htmlspecialchars($vlsm_network) ?>"
                           placeholder="10.0.0.0" autocomplete="off" spellcheck="false">
                </div>
                <div class="form-group form-group--mask">
                    <label for="vlsm_cidr">Prefix</label>
                    <input type="text" id="vlsm_cidr" name="vlsm_cidr"
                           value="<?= htmlspecialchars($vlsm_cidr_input) ?>"
                           placeholder="/24" autocomplete="off" spellcheck="false">
                </div>
            </div>
            <div class="vlsm-reqs" id="vlsm-reqs">
                <div class="vlsm-reqs-header">
                    <span class="vlsm-col-name">Name</span>
                    <span class="vlsm-col-hosts">Hosts Needed</span>
                </div>
                <?php if ($vlsm_requirements) : ?>
                    <?php foreach ($vlsm_requirements as $req) : ?>
                    <div class="vlsm-req-row">
                        <input type="text" name="vlsm_name[]" class="vlsm-name-input"
                               value="<?= htmlspecialchars($req['name']) ?>" placeholder="e.g. LAN A" autocomplete="off">
                        <input type="number" name="vlsm_hosts[]" class="vlsm-hosts-input"
                               value="<?= $req['hosts'] ?>" min="1" placeholder="e.g. 50">
                        <button type="button" class="vlsm-remove-row" aria-label="Remove row">&times;</button>
                    </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="vlsm-req-row">
                        <input type="text" name="vlsm_name[]" class="vlsm-name-input" placeholder="e.g. LAN A" autocomplete="off">
                        <input type="number" name="vlsm_hosts[]" class="vlsm-hosts-input" min="1" placeholder="e.g. 50">
                        <button type="button" class="vlsm-remove-row" aria-label="Remove row">&times;</button>
                    </div>
                <?php endif; ?>
            </div>
            <div class="vlsm-actions">
                <button type="button" class="vlsm-add-row">+ Add Subnet</button>
                <button type="submit" class="btn">Calculate</button>
                <a href="?tab=vlsm" class="btn reset">Reset</a>
            </div>
        </form>
        <?php if ($vlsm_error) : ?>
            <div class="error"><?= htmlspecialchars($vlsm_error) ?></div>
        <?php elseif ($vlsm_result !== null) : ?>
            <div class="vlsm-results">
                <p class="vlsm-sort-note">Results sorted largest-first for efficient allocation.</p>
                <table class="vlsm-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Hosts Needed</th>
                            <th>Allocated Subnet</th>
                            <th>Usable</th>
                            <th>Waste</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($vlsm_result as $alloc) :
                            [$alloc_net_ip, $alloc_pfx] = explode('/', $alloc['subnet']);
                            $alloc_detail = calculate_subnet($alloc_net_ip, (int)$alloc_pfx);
                            ?>
                        <tr data-first="<?= htmlspecialchars($alloc_detail['first_usable']) ?>"
                            data-last="<?= htmlspecialchars($alloc_detail['last_usable']) ?>">
                            <td><?= htmlspecialchars($alloc['name']) ?></td>
                            <td><?= number_format($alloc['hosts_needed']) ?></td>
                            <td class="vlsm-subnet-cell" tabindex="0" role="button"
                                title="Click to copy" data-copy="<?= htmlspecialchars($alloc['subnet']) ?>">
                                <code><?= htmlspecialchars($alloc['subnet']) ?></code>
                            </td>
                            <td><?= number_format($alloc['usable']) ?></td>
                            <td class="vlsm-waste"><?= number_format($alloc['waste']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="button" class="copy-all-btn" data-target="vlsm">Copy All</button>
            <?php
            $vlsm_total_hosts_req = 0;
            $vlsm_total_allocated = 0;
            foreach ($vlsm_result as $alloc) {
                $vlsm_total_hosts_req += $alloc['hosts_needed'];
                [, $vlsm_alloc_pfx] = explode('/', $alloc['subnet']);
                $vlsm_total_allocated += (int)pow(2, 32 - (int)$vlsm_alloc_pfx);
            }
            $vlsm_parent_cidr_int = (int)ltrim($vlsm_cidr_input, '/');
            $vlsm_parent_total    = (int)pow(2, 32 - $vlsm_parent_cidr_int);
            $vlsm_remaining       = $vlsm_parent_total - $vlsm_total_allocated;
            $vlsm_util_pct        = $vlsm_parent_total > 0
                ? round(($vlsm_total_allocated / $vlsm_parent_total) * 100, 1)
                : 0.0;
            ?>
            <div class="vlsm-summary">
                <span>Hosts requested: <strong><?= number_format($vlsm_total_hosts_req) ?></strong></span>
                <span>Allocated: <strong><?= number_format($vlsm_total_allocated) ?></strong> addresses</span>
                <span>Remaining: <strong><?= number_format($vlsm_remaining) ?></strong></span>
                <span>Utilisation: <strong><?= $vlsm_util_pct ?>%</strong></span>
            </div>
            <button type="button" id="vlsm-export-csv">Export CSV</button>
            <?php if ($show_share_bar && $share_url !== '') : ?>
            <div class="share-bar">
                <span class="share-label">Share</span>
                <code class="share-url"><?= htmlspecialchars($share_url_abs) ?></code>
                <button type="button" class="share-copy" data-copy="<?= htmlspecialchars($share_url) ?>">Copy</button>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Overlap Checker -->
        <div class="overlap-panel">
            <div class="overlap-title">Subnet Overlap Checker</div>
            <form method="post" class="overlap-form" novalidate>
                <input type="hidden" name="tab" value="vlsm">
                <div class="overlap-inputs">
                    <input type="text" name="overlap_cidr_a"
                           value="<?= htmlspecialchars($overlap_cidr_a) ?>"
                           placeholder="10.0.0.0/24 or 2001:db8::/32" autocomplete="off" spellcheck="false"
                           aria-label="First subnet CIDR">
                    <span class="overlap-vs">vs</span>
                    <input type="text" name="overlap_cidr_b"
                           value="<?= htmlspecialchars($overlap_cidr_b) ?>"
                           placeholder="10.0.0.128/25 or 2001:db8:1::/48" autocomplete="off" spellcheck="false"
                           aria-label="Second subnet CIDR">
                    <button type="submit" class="splitter-btn">Check</button>
                </div>
            </form>
            <?php if ($overlap_error) : ?>
                <div class="error"><?= htmlspecialchars($overlap_error) ?></div>
            <?php elseif ($overlap_result !== null) : ?>
                <?php
                $overlap_labels = [
                    'none'        => ['No overlap', 'overlap-none'],
                    'identical'   => ['Identical subnets', 'overlap-identical'],
                    'a_contains_b' => [$overlap_cidr_a . ' contains ' . $overlap_cidr_b, 'overlap-contains'],
                    'b_contains_a' => [$overlap_cidr_b . ' contains ' . $overlap_cidr_a, 'overlap-contains'],
                ];
                [$label, $cls] = $overlap_labels[$overlap_result] ?? ['Unknown', ''];
                ?>
                <div class="overlap-result <?= htmlspecialchars($cls) ?>"><?= htmlspecialchars($label) ?></div>
            <?php endif; ?>
        </div>

        <!-- Multi-CIDR Overlap Checker -->
        <div class="overlap-panel multi-overlap-panel">
            <div class="overlap-title">Multi-CIDR Overlap Check</div>
            <form method="post" class="overlap-form" novalidate>
                <input type="hidden" name="tab" value="vlsm">
                <textarea name="multi_overlap_input" class="multi-overlap-input"
                          placeholder="One CIDR per line (max 50):&#10;10.0.0.0/24&#10;10.0.0.128/25&#10;192.168.1.0/24"
                          rows="4" autocomplete="off" spellcheck="false"><?= htmlspecialchars($multi_overlap_input) ?></textarea>
                <button type="submit" class="splitter-btn">Check</button>
            </form>
            <?php if ($multi_overlap_error) : ?>
                <div class="error"><?= htmlspecialchars($multi_overlap_error) ?></div>
            <?php elseif ($multi_overlap_result !== null) : ?>
                <?php if (count($multi_overlap_result) === 0) : ?>
                    <div class="overlap-result overlap-none">No overlaps detected.</div>
                <?php else : ?>
                    <ul class="multi-overlap-list">
                        <?php foreach ($multi_overlap_result as $conflict) :
                            if ($conflict['relation'] === 'identical') {
                                $rel_label = 'Identical';
                            } elseif ($conflict['relation'] === 'a_contains_b') {
                                $rel_label = $conflict['a'] . ' contains ' . $conflict['b'];
                            } elseif ($conflict['relation'] === 'b_contains_a') {
                                $rel_label = $conflict['b'] . ' contains ' . $conflict['a'];
                            } else {
                                $rel_label = 'Overlap';
                            }
                            ?>
                        <li class="overlap-contains">
                            <code><?= htmlspecialchars($conflict['a']) ?></code> / <code><?= htmlspecialchars($conflict['b']) ?></code>: <?= htmlspecialchars($rel_label) ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <footer>
        <a href="https://github.com/seanmousseau/Subnet-Calculator" target="_blank" rel="noopener noreferrer">github.com/seanmousseau/Subnet-Calculator</a>
    </footer>
</div>

<div id="toast" class="toast" role="status" aria-live="polite" aria-atomic="true">Copied!</div>

<script src="assets/app.js?v=<?= $app_version ?>"></script>
</body>
</html>
