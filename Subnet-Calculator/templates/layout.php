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
    <link rel="icon" type="image/webp" href="assets/favicon-32-dark.webp?v=<?= htmlspecialchars($app_version) ?>" media="(prefers-color-scheme: dark)">
    <link rel="icon" type="image/png"  href="assets/favicon-32-dark.png?v=<?= htmlspecialchars($app_version) ?>"  media="(prefers-color-scheme: dark)">
    <link rel="icon" type="image/webp" href="assets/favicon-32-light.webp?v=<?= htmlspecialchars($app_version) ?>" media="(prefers-color-scheme: light)">
    <link rel="icon" type="image/png"  href="assets/favicon-32-light.png?v=<?= htmlspecialchars($app_version) ?>"  media="(prefers-color-scheme: light)">
    <link rel="icon" type="image/webp" href="assets/favicon-32-dark.webp?v=<?= htmlspecialchars($app_version) ?>">
    <link rel="icon" type="image/png"  href="assets/favicon-32-dark.png?v=<?= htmlspecialchars($app_version) ?>">
    <link rel="apple-touch-icon"       href="assets/apple-touch-icon.png?v=<?= htmlspecialchars($app_version) ?>">
    <?php
    // $canonical_url may include a path (e.g. in subdir installs); extract scheme+host
    // so the social image URL always points to the docroot, not a page path.
    $_cu      = parse_url(htmlspecialchars_decode((string) $canonical_url));
    $_si_url  = htmlspecialchars(
        ($_cu['scheme'] ?? 'https') . '://' . ($_cu['host'] ?? '') . '/assets/logo.webp'
    );
    unset($_cu);
    ?>
    <meta property="og:image"        content="<?= $_si_url ?>">
    <meta property="og:image:width"  content="512">
    <meta property="og:image:height" content="512">
    <meta property="og:image:type"   content="image/webp">
    <meta property="og:site_name"    content="Subnet Calculator">
    <meta name="twitter:card"        content="summary">
    <meta name="twitter:title"       content="<?= htmlspecialchars($page_title) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($page_description) ?>">
    <meta name="twitter:image"       content="<?= $_si_url ?>">
    <meta name="twitter:image:alt"   content="Subnet Calculator logo">
    <meta name="theme-color"         content="#0d1117">
    <meta name="color-scheme"        content="dark light">
    <?php if ($turnstile_curl_missing) : ?>
    <!-- sc-warning: Turnstile is configured but the PHP cURL extension is not loaded.
         Captcha verification is being skipped. Install php-curl to enable it. -->
    <?php endif; ?>
    <?php if ($hcaptcha_curl_missing) : ?>
    <!-- sc-warning: hCaptcha is configured but the PHP cURL extension is not loaded.
         Captcha verification is being skipped. Install php-curl to enable it. -->
    <?php endif; ?>
    <?php if ($recaptcha_curl_missing) : ?>
    <!-- sc-warning: reCAPTCHA Enterprise is configured but the PHP cURL extension is not loaded.
         Captcha verification is being skipped. Install php-curl to enable it. -->
    <?php endif; ?>
    <script nonce="<?= htmlspecialchars($csp_nonce) ?>">(function(){var t=localStorage.getItem('theme')||(window.matchMedia('(prefers-color-scheme: light)').matches?'light':null);if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="assets/app.css?v=<?= $app_version ?>">
    <?php if ($bg_override_style) {
        echo '<style nonce="' . htmlspecialchars($csp_nonce) . '">' . $bg_override_style . '</style>';
    } ?>
    <?php if ($turnstile_active) : ?>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php elseif ($hcaptcha_active) : ?>
        <script src="https://js.hcaptcha.com/1/api.js" async defer></script>
    <?php elseif ($recaptcha_active) : ?>
        <script src="https://www.google.com/recaptcha/enterprise.js" async defer></script>
    <?php endif; ?>
</head>
<?php if (ob_get_level() > 0) { ob_flush(); flush(); } ?>
<body>
<?php
// Shared app header (logo / wordmark / version pill / theme toggle).
// Calculator passes show_app_actions=true so the history/keyboard-shortcut
// buttons are rendered. See templates/_app_header.php for the contract.
$show_app_actions = true;
$breadcrumb_html  = null;
require __DIR__ . '/_app_header.php';
?>

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
        <button class="tab-btn<?= $active_tab === 'vlsm6' ? ' active' : '' ?>"
                role="tab" id="tab-vlsm6"
                aria-selected="<?= $active_tab === 'vlsm6' ? 'true' : 'false' ?>"
                aria-controls="panel-vlsm6"
                data-tab="vlsm6">VLSM IPv6</button>
    </div>

    <!-- IPv4 Panel -->
    <div id="panel-ipv4" class="panel<?= $active_tab === 'ipv4' ? ' active' : '' ?>"
         role="tabpanel" aria-labelledby="tab-ipv4" tabindex="-1">
        <form method="post" novalidate>
            <input type="hidden" name="tab" value="ipv4">
            <div class="form-row">
                <div class="form-group">
                    <label for="ip">IP Address<?= help_bubble('ipv4-ip', 'Enter an IPv4 address (e.g. 192.168.1.1) or CIDR notation (e.g. 192.168.1.0/24). The subnet mask field is optional when using CIDR notation.') ?></label>
                    <input type="text" id="ip" name="ip"
                           placeholder="192.168.1.0 or 192.168.1.0/24"
                           value="<?= htmlspecialchars($input_ip) ?>"
                           autocomplete="off" spellcheck="false"
                           <?= $error ? 'aria-invalid="true" aria-describedby="ipv4-error"' : '' ?>>
                </div>
                <div class="form-group">
                    <label for="mask">Netmask<?= help_bubble('ipv4-mask', 'Enter the subnet mask as a prefix length (/24), dotted-decimal (255.255.255.0), or wildcard (0.0.0.255). Leave blank when using CIDR notation in the IP field.') ?></label>
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
            <?php elseif ($hcaptcha_active) : ?>
                <div class="h-captcha" data-sitekey="<?= htmlspecialchars($hcaptcha_site_key) ?>"></div>
            <?php elseif ($recaptcha_active) : ?>
                <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars($recaptcha_enterprise_site_key) ?>" data-action="SUBMIT"></div>
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
                    <span class="result-label">Wildcard Mask<?= help_bubble('ipv4-wildcard', 'The inverse of the subnet mask. Used in access-control lists (ACLs) to specify matching bits — a 0 means the bit must match, a 1 means any value is accepted.') ?></span>
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
                    <span class="result-value"><?= format_number($result['usable_hosts']) ?></span>
                </div>
                <?php $ip4type = get_ipv4_type($input_ip); ?>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Address Type<?= help_bubble('ipv4-type', 'Classifies the IP address by its purpose: Private (RFC 1918), Loopback (127.0.0.0/8), Link-local (169.254.0.0/16), Public (globally routable), Multicast (224.0.0.0/4), etc.') ?></span>
                    <span class="result-value"><span class="badge badge-<?= type_badge_class($ip4type) ?>"><?= htmlspecialchars($ip4type) ?></span></span>
                </div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Reverse DNS Zone</span>
                    <span class="result-value"><?= htmlspecialchars($result['ptr_zone']) ?></span>
                </div>
            </div>
            <div class="copy-actions" data-kind="ipv4">
                <button type="button" class="copy-all-btn copy-md-btn" data-target="ipv4">Copy as Markdown</button>
                <button type="button" class="copy-all-btn copy-cisco-btn" data-target="ipv4">Copy as Cisco</button><?= help_bubble('copy-cisco-ipv4', 'Cisco output is generic IOS-style: an interface stanza with ip address. Vendor-specific tweaks (e.g. Juniper, Arista) may be required.') ?>
            </div>
            <?php
            $bin_cidr  = (int)ltrim($result['netmask_cidr'], '/');
            $bin_net   = array_map(fn($o) => sprintf('%08b', (int)$o), explode('.', explode('/', $result['network_cidr'])[0]));
            $bin_mask  = array_map(fn($o) => sprintf('%08b', (int)$o), explode('.', $result['netmask_octet']));
            ?>
            <details class="binary-details">
                <summary>Binary Representation<?= help_bubble('ipv4-binary', 'Shows the network address and mask in binary. Teal bits are the network portion (fixed); grey bits are the host portion (variable). Also shows the network address in hexadecimal and unsigned decimal.') ?></summary>
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
                    <span class="bin-label">Hex</span>
                    <code class="bin-value" tabindex="0" role="button" title="Click to copy"><?= htmlspecialchars($result['network_hex']) ?></code>
                    <span class="bin-label">Decimal</span>
                    <code class="bin-value" tabindex="0" role="button" title="Click to copy"><?= htmlspecialchars((string)$result['network_decimal']) ?></code>
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
        <?php endif; ?>

        <?php
        $open_tool_ipv4 = null;
        if ($split_result !== null || $split_error !== null) { $open_tool_ipv4 = 'split'; }
        elseif ($supernet_result !== null || $supernet_error !== null) { $open_tool_ipv4 = 'supernet'; }
        elseif ($range_result !== null || $range_error !== null) { $open_tool_ipv4 = 'range'; }
        elseif ($tree_result !== null || $tree_error !== null) { $open_tool_ipv4 = 'tree'; }
        elseif ($wildcard_result !== null || $wildcard_error !== null) { $open_tool_ipv4 = 'wildcard'; }
        elseif (($lookup_result !== null || $lookup_error !== null) && $active_tab === 'ipv4') { $open_tool_ipv4 = 'lookup'; }
        elseif (($diff_result !== null || $diff_error !== null) && $active_tab === 'ipv4') { $open_tool_ipv4 = 'diff'; }
        ?>
        <div class="tool-toolbar"<?= $open_tool_ipv4 ? ' data-open-tool="' . htmlspecialchars($open_tool_ipv4) . '"' : '' ?>>
            <button type="button" class="tool-trigger" data-tool="split" aria-expanded="false">Split Subnet</button>
            <button type="button" class="tool-trigger" data-tool="supernet" aria-expanded="false">Supernet</button>
            <button type="button" class="tool-trigger" data-tool="range" aria-expanded="false">Range&rarr;CIDR</button>
            <button type="button" class="tool-trigger" data-tool="tree" aria-expanded="false">Subnet Tree</button>
            <button type="button" class="tool-trigger" data-tool="tree-editor" aria-expanded="false">Tree Editor</button>
            <button type="button" class="tool-trigger" data-tool="wildcard" aria-expanded="false">Wildcard&harr;CIDR</button>
            <button type="button" class="tool-trigger" data-tool="lookup" aria-expanded="false">IP Lookup</button>
            <button type="button" class="tool-trigger" data-tool="diff" aria-expanded="false">Subnet Diff</button>
        </div>

        <div class="tool-drawer" role="dialog" aria-modal="true" aria-labelledby="drawer-title-ipv4">
            <div class="tool-drawer-header">
                <span class="tool-drawer-title" id="drawer-title-ipv4">Tool</span>
                <button type="button" class="tool-drawer-close" aria-label="Close">&times;</button>
            </div>

            <div class="tool-panel" data-tool="split">
                <div class="splitter">
                    <div class="splitter-title">Split Subnet</div>
                    <form method="post" class="splitter-form">
                        <input type="hidden" name="tab" value="ipv4">
                        <input type="hidden" name="ip" value="<?= htmlspecialchars($input_ip) ?>">
                        <input type="hidden" name="mask" value="<?= htmlspecialchars($input_mask) ?>">
                        <div class="splitter-row">
                            <span class="splitter-label">Split into<?= help_bubble('ipv4-split', 'Enter a prefix length larger than the parent (e.g. /25 splits a /24 into two /25 subnets). The result is capped at the configured maximum.') ?></span>
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
                        <div class="split-list" data-parent="<?= htmlspecialchars($result['cidr'] ?? '') ?>">
                            <button type="button" class="copy-all-btn" data-target="split">Copy All</button>
                            <button type="button" class="copy-all-btn copy-md-btn" data-target="split4">Copy as Markdown</button>
                            <button type="button" class="copy-all-btn copy-cisco-btn" data-target="split4">Copy as Cisco</button><?= help_bubble('copy-cisco-split4', 'Cisco output is generic IOS-style — one interface stanza per split subnet. Vendor-specific tweaks may be required.') ?>
                            <button type="button" class="ascii-export-btn">Export ASCII</button>
                            <?php foreach ($split_result['subnets'] as $s) : ?>
                                <div class="split-item" tabindex="0" role="button" data-copy="<?= htmlspecialchars($s) ?>">
                                    <span class="split-subnet-text"><?= htmlspecialchars($s) ?></span>
                                    <?= copy_button($s, 'Copy ' . $s) ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($split_result['total'] > $split_result['showing']) : ?>
                                <div class="split-more">+&nbsp;<?= format_number($split_result['total'] - $split_result['showing']) ?> more</div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tool-panel" data-tool="supernet">
                <div class="overlap-panel">
                    <div class="overlap-title">Supernet &amp; Route Summarisation</div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv4">
                        <textarea name="supernet_input" rows="4" class="multi-overlap-input"
                                  placeholder="One IPv4 CIDR per line (max 50):&#10;10.0.0.0/24&#10;10.0.1.0/24"
                                  autocomplete="off" spellcheck="false"><?= htmlspecialchars($supernet_input) ?></textarea>
                        <div class="splitter-row supernet-action-row">
                            <button type="submit" name="supernet_action" value="find" class="splitter-btn">Find Supernet</button><?= help_bubble('supernet-find', 'Finds the smallest single CIDR block that contains all of the listed CIDRs. Useful for aggregating routes into a single summary route.') ?>
                            <button type="submit" name="supernet_action" value="summarise" class="splitter-btn">Summarise Routes</button><?= help_bubble('supernet-summarise', 'Computes the minimal set of non-overlapping CIDRs that exactly covers the listed networks. Unlike Find Supernet, this avoids including addresses outside the input ranges.') ?>
                        </div>
                    </form>
                    <?php if ($supernet_error) : ?>
                        <div class="error"><?= htmlspecialchars($supernet_error) ?></div>
                    <?php elseif ($supernet_result !== null) : ?>
                        <?php
                        $_supernet_inputs = count(array_filter(array_map('trim', explode("\n", $supernet_input))));
                        if ($supernet_action === 'find') {
                            $_supernet_label = 'Supernet: ' . $_supernet_inputs . ' CIDR' . ($_supernet_inputs !== 1 ? 's' : '');
                        } else {
                            $_supernet_outs  = count($supernet_result['summaries'] ?? []);
                            $_supernet_label = 'Summarise: ' . $_supernet_inputs . ' → ' . $_supernet_outs;
                        }
                        ?>
                        <?php if ($supernet_action === 'find') : ?>
                            <div class="overlap-result overlap-contains"
                                 data-history-source="supernet"
                                 data-history-active="1"
                                 data-history-label="<?= htmlspecialchars($_supernet_label) ?>">
                                <?= htmlspecialchars($supernet_result['supernet'] ?? '') ?>
                            </div>
                        <?php else : ?>
                            <div class="split-list split-list--mt"
                                 data-history-source="supernet"
                                 data-history-active="1"
                                 data-history-label="<?= htmlspecialchars($_supernet_label) ?>">
                                <button type="button" class="copy-all-btn" data-target="supernet">Copy All</button>
                                <?php foreach ($supernet_result['summaries'] ?? [] as $s) : ?>
                                    <div class="split-item" tabindex="0" role="button" data-copy="<?= htmlspecialchars($s) ?>">
                                        <span class="split-subnet-text"><?= htmlspecialchars($s) ?></span>
                                        <?= copy_button($s, 'Copy ' . $s) ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php $s_count = count($supernet_result['summaries'] ?? []);
                                      $i_count = count(array_filter(explode("\n", $supernet_input))); ?>
                                <div class="split-more"><?= $s_count ?> prefix<?= $s_count !== 1 ? 'es' : '' ?> from <?= $i_count ?> input<?= $i_count !== 1 ? 's' : '' ?></div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tool-panel" data-tool="range">
                <div class="overlap-panel">
                    <div class="overlap-title">IP Range &rarr; CIDR<?= help_bubble('range-cidr', 'Enter a start and end IPv4 address to get the minimal set of CIDR blocks that exactly covers that range using the greedy largest-aligned-block algorithm.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv4">
                        <div class="overlap-inputs">
                            <input type="text" name="range_start"
                                   value="<?= htmlspecialchars($range_start) ?>"
                                   placeholder="Start IP (e.g. 10.0.0.0)"
                                   autocomplete="off" spellcheck="false"
                                   aria-label="Start IP address">
                            <span class="overlap-vs">to</span>
                            <input type="text" name="range_end"
                                   value="<?= htmlspecialchars($range_end) ?>"
                                   placeholder="End IP (e.g. 10.0.0.255)"
                                   autocomplete="off" spellcheck="false"
                                   aria-label="End IP address">
                            <button type="submit" class="splitter-btn">Convert</button>
                        </div>
                    </form>
                    <?php if ($range_error) : ?>
                        <div class="error"><?= htmlspecialchars($range_error) ?></div>
                    <?php elseif ($range_result !== null) : ?>
                        <?php $_range_label = 'Range: ' . $range_start . ' → ' . $range_end; ?>
                        <div class="split-list split-list--mt"
                             data-history-source="range"
                             data-history-active="1"
                             data-history-label="<?= htmlspecialchars($_range_label) ?>">
                            <button type="button" class="copy-all-btn" data-target="range">Copy All</button>
                            <?php foreach ($range_result as $r_cidr) : ?>
                                <div class="split-item" tabindex="0" role="button" data-copy="<?= htmlspecialchars($r_cidr) ?>">
                                    <span class="split-subnet-text"><?= htmlspecialchars($r_cidr) ?></span>
                                    <?= copy_button($r_cidr, 'Copy ' . $r_cidr) ?>
                                </div>
                            <?php endforeach; ?>
                            <div class="split-more"><?= count($range_result) ?> CIDR block<?= count($range_result) !== 1 ? 's' : '' ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tool-panel" data-tool="tree">
                <div class="overlap-panel">
                    <div class="overlap-title">Subnet Allocation Tree</div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv4">
                        <div class="form-group tree-form-group">
                            <label for="tree_parent" class="tree-parent-label">Parent CIDR</label>
                            <input type="text" id="tree_parent" name="tree_parent"
                                   value="<?= htmlspecialchars($tree_parent) ?>"
                                   placeholder="10.0.0.0/16" autocomplete="off" spellcheck="false">
                        </div>
                        <textarea name="tree_children" rows="4" class="multi-overlap-input"
                                  placeholder="One child CIDR per line (max 100):&#10;10.0.0.0/24&#10;10.0.1.0/24"
                                  autocomplete="off" spellcheck="false"><?= htmlspecialchars($tree_children) ?></textarea>
                        <div class="tree-action-row">
                            <button type="submit" class="splitter-btn">Build Tree</button>
                        </div>
                    </form>
                    <?php if ($tree_error) : ?>
                        <div class="error"><?= htmlspecialchars($tree_error) ?></div>
                    <?php elseif ($tree_result !== null) : ?>
                        <?php $_tree_label = 'Tree: ' . (string)($tree_result['cidr'] ?? $tree_parent); ?>
                        <div class="tree-view"
                             data-history-source="tree"
                             data-history-active="1"
                             data-history-label="<?= htmlspecialchars($_tree_label) ?>">
                            <?php
                            /**
                             * @param array<string, mixed> $node
                             * @param int $depth
                             */
                            function render_tree_node(array $node, int $depth = 0): void
                            {
                                $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $depth);
                                $cidr   = htmlspecialchars((string)($node['cidr'] ?? ''));
                                echo '<div class="tree-node">';
                                echo $indent . '<span class="tree-cidr" tabindex="0" role="button" data-copy="' . $cidr . '" title="Click to copy">';
                                echo '<code>' . $cidr . '</code>';
                                echo '</span>';
                                echo '</div>';
                                $gaps = $node['gaps'] ?? [];
                                foreach ((array)($node['children'] ?? []) as $child) {
                                    if (is_array($child)) {
                                        render_tree_node($child, $depth + 1);
                                    }
                                }
                                foreach ((array)$gaps as $gap) {
                                    $gap_safe = htmlspecialchars((string)$gap);
                                    echo '<div class="tree-node tree-gap">';
                                    echo $indent . '&nbsp;&nbsp;&nbsp;&nbsp;<code>' . $gap_safe . '</code> <span class="tree-free-label">(free)</span>';
                                    echo '</div>';
                                }
                            }
                            render_tree_node($tree_result);
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tool-panel" data-tool="tree-editor">
                <?php
                $tree_editor_initial_cidr = $result['cidr'] ?? '';
                require __DIR__ . '/_tree_editor.php';
                ?>
            </div>

            <div class="tool-panel" data-tool="wildcard">
                <div class="overlap-panel">
                    <div class="overlap-title">Wildcard &harr; CIDR<?= help_bubble('wildcard-cidr', 'Bidirectional Cisco-style converter. Enter a wildcard mask (e.g. 0.0.0.255) to get its CIDR prefix, or a prefix (/24 or 24) to get the wildcard. Non-contiguous masks are rejected.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv4">
                        <div class="overlap-inputs">
                            <input type="text" id="wildcard_input" name="wildcard_input"
                                   value="<?= htmlspecialchars($wildcard_input) ?>"
                                   placeholder="/24  or  0.0.0.255"
                                   autocomplete="off" spellcheck="false"
                                   aria-label="CIDR prefix or wildcard mask">
                            <button type="submit" class="splitter-btn">Convert</button>
                        </div>
                    </form>
                    <?php if ($wildcard_error) : ?>
                        <div class="error wildcard-error"><?= htmlspecialchars($wildcard_error) ?></div>
                    <?php elseif ($wildcard_result !== null) : ?>
                        <?php $_wildcard_label = 'Wildcard: ' . $wildcard_input; ?>
                        <div class="split-list split-list--mt"
                             data-history-source="wildcard"
                             data-history-active="1"
                             data-history-label="<?= htmlspecialchars($_wildcard_label) ?>">
                            <div class="split-item" tabindex="0" role="button"
                                 data-copy="<?= htmlspecialchars($wildcard_result['cidr']) ?>">
                                <span class="split-subnet-text" id="wildcard-result-cidr">CIDR: <?= htmlspecialchars($wildcard_result['cidr']) ?></span>
                                <?= copy_button($wildcard_result['cidr'], 'Copy CIDR ' . $wildcard_result['cidr']) ?>
                            </div>
                            <div class="split-item" tabindex="0" role="button"
                                 data-copy="<?= htmlspecialchars($wildcard_result['wildcard']) ?>">
                                <span class="split-subnet-text" id="wildcard-result-mask">Wildcard: <?= htmlspecialchars($wildcard_result['wildcard']) ?></span>
                                <?= copy_button($wildcard_result['wildcard'], 'Copy wildcard ' . $wildcard_result['wildcard']) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

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
                    <?php if ($active_tab === 'ipv4' && $lookup_error) : ?>
                        <div class="error"><?= htmlspecialchars($lookup_error) ?></div>
                    <?php elseif ($active_tab === 'ipv4' && $lookup_result !== null) : ?>
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
                                        <?php foreach ($lookup_result as $row) : ?>
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
                            <div class="split-more"><?= count($lookup_result) ?> IP<?= count($lookup_result) !== 1 ? 's' : '' ?> looked up</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tool-panel" data-tool="diff">
                <div class="overlap-panel">
                    <div class="overlap-title">Subnet Diff<?= help_bubble('ipv4-diff', 'Compares two CIDR lists. Inputs are canonicalised (host bits zeroed, IPv6 lowercased) before comparison. Reports added, removed, unchanged, and changed (same network address but different prefix length). Mixed IPv4/IPv6 inputs are allowed. Cap: 1000 entries per side.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv4">
                        <div class="lookup-form-grid">
                            <label for="diff_before_v4" class="lookup-form-label">Before <span class="lookup-form-hint">(one CIDR per line)</span></label>
                            <textarea id="diff_before_v4" name="diff_before" rows="4" class="multi-overlap-input"
                                      placeholder="10.0.0.0/24&#10;10.0.1.0/24&#10;10.0.2.0/24"
                                      autocomplete="off" spellcheck="false"><?= htmlspecialchars($active_tab === 'ipv4' ? $diff_before_input : '') ?></textarea>
                            <label for="diff_after_v4" class="lookup-form-label">After <span class="lookup-form-hint">(one CIDR per line)</span></label>
                            <textarea id="diff_after_v4" name="diff_after" rows="4" class="multi-overlap-input"
                                      placeholder="10.0.0.0/23&#10;10.0.2.0/24&#10;10.0.3.0/24"
                                      autocomplete="off" spellcheck="false"><?= htmlspecialchars($active_tab === 'ipv4' ? $diff_after_input : '') ?></textarea>
                        </div>
                        <div class="splitter-row">
                            <button type="submit" class="splitter-btn">Diff</button>
                        </div>
                    </form>
                    <?php if ($active_tab === 'ipv4' && $diff_error) : ?>
                        <div class="error"><?= htmlspecialchars($diff_error) ?></div>
                    <?php elseif ($active_tab === 'ipv4' && $diff_result !== null) : ?>
                        <?php include __DIR__ . '/_diff_result.php'; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
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
            <?php elseif ($hcaptcha_active) : ?>
                <div class="h-captcha" data-sitekey="<?= htmlspecialchars($hcaptcha_site_key) ?>"></div>
            <?php elseif ($recaptcha_active) : ?>
                <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars($recaptcha_enterprise_site_key) ?>" data-action="SUBMIT"></div>
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
                    <span class="result-value"><?= htmlspecialchars(is_numeric($result6['total']) ? format_number((int)$result6['total']) : $result6['total']) ?></span>
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
                <?php if (isset($result6['address_expanded'])) : ?>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Address (Expanded)<?= help_bubble('ipv6-expanded', 'Full 128-bit representation with all leading zeros shown, e.g. 2001:0db8:0000:0000:0000:0000:0000:0001.') ?></span>
                    <span class="result-value"><?= htmlspecialchars($result6['address_expanded']) ?></span>
                </div>
                <div class="result-row" title="Click to copy" tabindex="0" role="button">
                    <span class="result-label">Address (Compressed)<?= help_bubble('ipv6-compressed', 'Canonical short form per RFC 5952 — leading zeros in each group are suppressed and the longest run of consecutive all-zero groups is replaced with ::.') ?></span>
                    <span class="result-value"><?= htmlspecialchars($result6['address_compressed']) ?></span>
                </div>
                <?php endif; ?>
            </div>
            <div class="copy-actions" data-kind="ipv6">
                <button type="button" class="copy-all-btn copy-md-btn" data-target="ipv6">Copy as Markdown</button>
                <button type="button" class="copy-all-btn copy-cisco-btn" data-target="ipv6">Copy as Cisco</button><?= help_bubble('copy-cisco-ipv6', 'Cisco output is generic IOS-style: ipv6 address on an interface stanza. Vendor-specific tweaks may be required.') ?>
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
                <summary>Binary / Hex Representation<?= help_bubble('ipv6-binary', 'Shows the IPv6 address in binary (128 bits), split into network (teal) and interface (grey) portions based on the prefix length. Also displays the address in hexadecimal.') ?></summary>
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
        <?php endif; ?>

        <?php
        $open_tool_ipv6 = null;
        if ($split_result6 !== null || $split_error6 !== null) { $open_tool_ipv6 = 'split6'; }
        elseif ($ula_result !== null || $ula_error !== null) { $open_tool_ipv6 = 'ula'; }
        elseif (!empty($range6)) { $open_tool_ipv6 = 'range6'; }
        elseif ($supernet6_result !== null || $supernet6_error !== null) { $open_tool_ipv6 = 'supernet6'; }
        elseif (!empty($zoneid)) { $open_tool_ipv6 = 'zoneid'; }
        elseif (!empty($derive)) { $open_tool_ipv6 = 'derive'; }
        elseif (!empty($slaac)) { $open_tool_ipv6 = 'slaac'; }
        elseif (($lookup_result !== null || $lookup_error !== null) && $active_tab === 'ipv6') { $open_tool_ipv6 = 'lookup'; }
        elseif (($diff_result !== null || $diff_error !== null) && $active_tab === 'ipv6') { $open_tool_ipv6 = 'diff'; }
        ?>
        <div class="tool-toolbar"<?= $open_tool_ipv6 ? ' data-open-tool="' . htmlspecialchars($open_tool_ipv6) . '"' : '' ?>>
            <button type="button" class="tool-trigger" data-tool="split6" aria-expanded="false">Split Subnet</button>
            <button type="button" class="tool-trigger" data-tool="ula" aria-expanded="false">ULA Generator</button>
            <button type="button" class="tool-trigger" data-tool="range6" aria-expanded="false">Range&rarr;CIDR</button>
            <button type="button" class="tool-trigger" data-tool="supernet6" aria-expanded="false">Supernet</button>
            <button type="button" class="tool-trigger" data-tool="zoneid" aria-expanded="false">Zone ID</button>
            <button type="button" class="tool-trigger" data-tool="derive" aria-expanded="false">Derive Address</button>
            <button type="button" class="tool-trigger" data-tool="slaac" aria-expanded="false">SLAAC Privacy</button>
            <button type="button" class="tool-trigger" data-tool="lookup" aria-expanded="false">IP Lookup</button>
            <button type="button" class="tool-trigger" data-tool="diff" aria-expanded="false">Subnet Diff</button>
        </div>

        <div class="tool-drawer" role="dialog" aria-modal="true" aria-labelledby="drawer-title-ipv6">
            <div class="tool-drawer-header">
                <span class="tool-drawer-title" id="drawer-title-ipv6">Tool</span>
                <button type="button" class="tool-drawer-close" aria-label="Close">&times;</button>
            </div>

            <div class="tool-panel" data-tool="split6">
                <div class="splitter">
                    <div class="splitter-title">Split Subnet</div>
                    <form method="post" class="splitter-form">
                        <input type="hidden" name="tab" value="ipv6">
                        <input type="hidden" name="ipv6" value="<?= htmlspecialchars($input_ipv6) ?>">
                        <input type="hidden" name="prefix" value="<?= htmlspecialchars($input_prefix) ?>">
                        <div class="splitter-row">
                            <span class="splitter-label">Split into<?= help_bubble('ipv6-split', 'Enter a prefix length larger than the parent (e.g. /65 splits a /64 into two /65 subnets). The result is capped at the configured maximum.') ?></span>
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
                        <div class="split-list" data-parent="<?= htmlspecialchars($result6['network_cidr'] ?? '') ?>">
                            <button type="button" class="copy-all-btn" data-target="split">Copy All</button>
                            <button type="button" class="copy-all-btn copy-md-btn" data-target="split6">Copy as Markdown</button>
                            <button type="button" class="copy-all-btn copy-cisco-btn" data-target="split6">Copy as Cisco</button><?= help_bubble('copy-cisco-split6', 'Cisco output is generic IOS-style — one interface stanza per split IPv6 subnet using ipv6 address. Vendor-specific tweaks may be required.') ?>
                            <button type="button" class="ascii-export-btn">Export ASCII</button>
                            <?php foreach ($split_result6['subnets'] as $s) : ?>
                                <div class="split-item" tabindex="0" role="button" data-copy="<?= htmlspecialchars($s) ?>">
                                    <span class="split-subnet-text"><?= htmlspecialchars($s) ?></span>
                                    <?= copy_button($s, 'Copy ' . $s) ?>
                                </div>
                            <?php endforeach; ?>
                            <?php
                                $total6   = $split_result6['total'];
                                $showing6 = $split_result6['showing'];
                                $has_more6 = is_numeric($total6) ? ($showing6 < (int)$total6) : true;
                                $more_label6 = is_numeric($total6) ? format_number((int)$total6 - $showing6) . ' more' : $total6 . ' more';
                            ?>
                            <?php if ($has_more6) : ?>
                                <div class="split-more">+&nbsp;<?= htmlspecialchars($more_label6) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

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
                    <?php if ($ula_error) : ?>
                        <div class="error"><?= htmlspecialchars($ula_error) ?></div>
                    <?php elseif ($ula_result !== null) : ?>
                        <?php $_ula_label = 'ULA: ' . (string)($ula_result['prefix'] ?? ''); ?>
                        <div class="ula-result"
                             data-history-source="ula"
                             data-history-active="1"
                             data-history-label="<?= htmlspecialchars($_ula_label) ?>">
                            <div class="overlap-result overlap-contains"><?= htmlspecialchars($ula_result['prefix'] ?? '') ?></div>
                            <div class="ula-meta">
                                <span>Global ID: <code><?= htmlspecialchars($ula_result['global_id'] ?? '') ?></code></span>
                                <span>Available /64s: <strong><?= format_number((int)($ula_result['available_64s'] ?? 0)) ?></strong></span>
                            </div>
                            <?php if (!empty($ula_result['example_64s'])) : ?>
                            <div class="split-list split-list--mt">
                                <button type="button" class="copy-all-btn" data-target="ula">Copy All</button>
                                <?php foreach ($ula_result['example_64s'] as $ex64) : ?>
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

            <div class="tool-panel" data-tool="range6">
                <div class="overlap-panel">
                    <div class="overlap-title">IPv6 Range &rarr; CIDR<?= help_bubble('ipv6-range-cidr', 'Enter a start and end IPv6 address to get the minimal set of CIDR blocks that exactly covers that range. Uses GMP arithmetic so /128-wide ranges work without overflow. Output is capped (default 256 blocks); the cap is configurable via $range_max_cidrs.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <div class="overlap-inputs">
                            <input type="text" name="range6_start"
                                   value="<?= htmlspecialchars($range6_start) ?>"
                                   placeholder="Start IPv6 (e.g. 2001:db8::)"
                                   autocomplete="off" spellcheck="false"
                                   aria-label="Start IPv6 address">
                            <span class="overlap-vs">to</span>
                            <input type="text" name="range6_end"
                                   value="<?= htmlspecialchars($range6_end) ?>"
                                   placeholder="End IPv6 (e.g. 2001:db8::ffff)"
                                   autocomplete="off" spellcheck="false"
                                   aria-label="End IPv6 address">
                            <button type="submit" class="splitter-btn">Convert</button>
                        </div>
                    </form>
                    <?php if (!empty($range6['error'])) : ?>
                        <div class="error"><?= htmlspecialchars($range6['error']) ?></div>
                    <?php elseif (isset($range6['result'])) : ?>
                        <?php if (!empty($range6['warning'])) : ?>
                            <div class="warning"><?= htmlspecialchars($range6['warning']) ?></div>
                        <?php endif; ?>
                        <?php $_range6_label = 'Range: ' . $range6_start . ' → ' . $range6_end; ?>
                        <div class="split-list split-list--mt"
                             data-history-source="range6"
                             data-history-active="1"
                             data-history-label="<?= htmlspecialchars($_range6_label) ?>">
                            <button type="button" class="copy-all-btn" data-target="range6">Copy All</button>
                            <?php foreach ($range6['result'] as $r6_cidr) : ?>
                                <div class="split-item" tabindex="0" role="button" data-copy="<?= htmlspecialchars($r6_cidr) ?>">
                                    <span class="split-subnet-text"><?= htmlspecialchars($r6_cidr) ?></span>
                                    <?= copy_button($r6_cidr, 'Copy ' . $r6_cidr) ?>
                                </div>
                            <?php endforeach; ?>
                            <div class="split-more"><?= count($range6['result']) ?> CIDR block<?= count($range6['result']) !== 1 ? 's' : '' ?><?php if (($range6['total'] ?? null) !== null) : ?> · <?= htmlspecialchars(is_string($range6['total']) ? $range6['total'] : (string)$range6['total']) ?> addresses<?php endif; ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tool-panel" data-tool="supernet6">
                <div class="overlap-panel">
                    <div class="overlap-title">IPv6 Supernet &amp; Route Summarisation<?= help_bubble('ipv6-supernet', 'IPv6 counterpart to the IPv4 Supernet tool. Find returns the smallest CIDR enclosing all listed IPv6 prefixes; Summarise reduces the list to the minimal covering IPv6 prefixes. Pure GMP — works for /128-wide inputs. Maximum 50 CIDRs per check.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <label for="supernet6_input" class="sr-only">IPv6 CIDR list (one per line)</label>
                        <textarea id="supernet6_input" name="supernet6_input" rows="4" class="multi-overlap-input"
                                  placeholder="One IPv6 CIDR per line (max 50):&#10;2001:db8::/64&#10;2001:db8:0:1::/64"
                                  autocomplete="off" spellcheck="false"><?= htmlspecialchars($supernet6_input) ?></textarea>
                        <div class="splitter-row supernet-action-row">
                            <button type="submit" name="supernet6_action" value="find" class="splitter-btn">Find Supernet</button><?= help_bubble('supernet6-find', 'Finds the smallest single IPv6 CIDR block that contains all of the listed CIDRs. Useful for aggregating IPv6 routes into a single summary route.') ?>
                            <button type="submit" name="supernet6_action" value="summarise" class="splitter-btn">Summarise Routes</button><?= help_bubble('supernet6-summarise', 'Computes the minimal set of non-overlapping IPv6 CIDRs that exactly covers the listed networks. Unlike Find Supernet, this avoids including addresses outside the input ranges.') ?>
                        </div>
                    </form>
                    <?php if ($supernet6_error) : ?>
                        <div class="error"><?= htmlspecialchars($supernet6_error) ?></div>
                    <?php elseif ($supernet6_result !== null) : ?>
                        <?php
                        $_supernet6_inputs = count(array_filter(array_map('trim', explode("\n", $supernet6_input))));
                        if ($supernet6_action === 'find') {
                            $_supernet6_label = 'Supernet6: ' . $_supernet6_inputs . ' CIDR' . ($_supernet6_inputs !== 1 ? 's' : '');
                        } else {
                            $_supernet6_outs  = count($supernet6_result['summaries'] ?? []);
                            $_supernet6_label = 'Summarise6: ' . $_supernet6_inputs . ' → ' . $_supernet6_outs;
                        }
                        ?>
                        <?php if ($supernet6_action === 'find') : ?>
                            <div class="overlap-result overlap-contains"
                                 data-history-source="supernet6"
                                 data-history-active="1"
                                 data-history-label="<?= htmlspecialchars($_supernet6_label) ?>">
                                <?= htmlspecialchars($supernet6_result['supernet'] ?? '') ?>
                            </div>
                        <?php else : ?>
                            <div class="split-list split-list--mt"
                                 data-history-source="supernet6"
                                 data-history-active="1"
                                 data-history-label="<?= htmlspecialchars($_supernet6_label) ?>">
                                <button type="button" class="copy-all-btn" data-target="supernet6">Copy All</button>
                                <?php foreach ($supernet6_result['summaries'] ?? [] as $s6) : ?>
                                    <div class="split-item" tabindex="0" role="button" data-copy="<?= htmlspecialchars($s6) ?>">
                                        <span class="split-subnet-text"><?= htmlspecialchars($s6) ?></span>
                                        <?= copy_button($s6, 'Copy ' . $s6) ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php $s6_count = count($supernet6_result['summaries'] ?? []);
                                      $i6_count = count(array_filter(explode("\n", $supernet6_input))); ?>
                                <div class="split-more"><?= $s6_count ?> prefix<?= $s6_count !== 1 ? 'es' : '' ?> from <?= $i6_count ?> input<?= $i6_count !== 1 ? 's' : '' ?></div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tool-panel" data-tool="zoneid">
                <div class="overlap-panel">
                    <div class="overlap-title">Zone ID Parser<?= help_bubble('ipv6-zoneid', 'Zone identifiers (RFC 4007) scope an IPv6 address to a specific interface. They are written after a percent sign — e.g. fe80::1%eth0 — and are only meaningful on link-local (fe80::/10) addresses; most operating systems ignore zones supplied on global addresses.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <label for="zoneid_input" class="sr-only">IPv6 address with optional zone identifier</label>
                        <div class="splitter-row">
                            <input type="text" id="zoneid_input" name="zoneid_input" class="splitter-input"
                                   placeholder="fe80::1%eth0"
                                   value="<?= htmlspecialchars($zoneid_input) ?>"
                                   autocomplete="off" spellcheck="false"
                                   <?= !empty($zoneid['error']) ? 'aria-invalid="true" aria-describedby="zoneid-error"' : '' ?>>
                            <button type="submit" class="splitter-btn">Parse</button>
                        </div>
                    </form>
                    <?php if (!empty($zoneid['error'])) : ?>
                        <div class="error" id="zoneid-error"><?= htmlspecialchars($zoneid['error']) ?></div>
                    <?php elseif (isset($zoneid['address'])) : ?>
                        <?php if (!empty($zoneid['warning'])) : ?>
                            <div class="warning"><?= htmlspecialchars($zoneid['warning']) ?></div>
                        <?php endif; ?>
                        <?php $_zoneid_label = 'Zone ID: ' . $zoneid['address'] . (($zoneid['zone_id'] ?? null) !== null ? '%' . $zoneid['zone_id'] : ''); ?>
                        <dl class="zoneid-result"
                            data-history-source="zoneid"
                            data-history-active="1"
                            data-history-label="<?= htmlspecialchars($_zoneid_label) ?>">
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Address</dt>
                                <dd class="zoneid-result__value"><code><?= htmlspecialchars($zoneid['address']) ?></code></dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Zone ID</dt>
                                <dd class="zoneid-result__value">
                                    <?php if (($zoneid['zone_id'] ?? null) !== null) : ?>
                                        <code><?= htmlspecialchars($zoneid['zone_id']) ?></code>
                                    <?php else : ?>
                                        <span class="zoneid-result__empty" aria-label="no zone identifier">&mdash;</span>
                                    <?php endif; ?>
                                </dd>
                            </div>
                            <div class="zoneid-result__row">
                                <dt class="zoneid-result__label">Link-local</dt>
                                <dd class="zoneid-result__value"><?= !empty($zoneid['is_link_local']) ? 'Yes' : 'No' ?></dd>
                            </div>
                        </dl>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tool-panel" data-tool="derive">
                <div class="overlap-panel">
                    <div class="overlap-title">Derive Address<?= help_bubble('ipv6-derive', 'Derives the IPv6 forms generated from a 48-bit MAC address per RFC 4291 §2.5.1: the modified EUI-64 interface identifier (with the U/L bit flipped), the link-local address (fe80:: + EUI-64), and the solicited-node multicast address (ff02::1:ff + the low 24 bits of the unicast address). Accepts colon, hyphen, Cisco dotted, or bare-hex MAC formats.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <label for="derive_mac" class="sr-only">MAC address</label>
                        <div class="splitter-row">
                            <input type="text" id="derive_mac" name="derive_mac" class="splitter-input"
                                   placeholder="00:24:b9:7e:ab:cd"
                                   value="<?= htmlspecialchars($derive_input) ?>"
                                   autocomplete="off" spellcheck="false"
                                   <?= !empty($derive['error']) ? 'aria-invalid="true" aria-describedby="derive-error"' : '' ?>>
                            <button type="submit" class="splitter-btn">Derive</button>
                        </div>
                    </form>
                    <?php if (!empty($derive['error'])) : ?>
                        <div class="error" id="derive-error"><?= htmlspecialchars($derive['error']) ?></div>
                    <?php elseif (isset($derive['eui64'])) : ?>
                        <?php if (!empty($derive['warning'])) : ?>
                            <div class="warning"><?= htmlspecialchars($derive['warning']) ?></div>
                        <?php endif; ?>
                        <?php $_derive_label = 'Derive: ' . ($derive['mac_canonical'] ?? ''); ?>
                        <dl class="derive-result"
                            data-history-source="derive"
                            data-history-active="1"
                            data-history-label="<?= htmlspecialchars($_derive_label) ?>">
                            <div class="derive-result__row">
                                <dt class="derive-result__label">MAC (canonical)</dt>
                                <dd class="derive-result__value">
                                    <code><?= htmlspecialchars((string)($derive['mac_canonical'] ?? '')) ?></code>
                                </dd>
                            </div>
                            <div class="derive-result__row">
                                <dt class="derive-result__label">EUI-64<?= help_bubble('ipv6-derive-eui64', 'Modified EUI-64 interface identifier — the U/L (universal/local) bit in the first MAC byte is inverted, then the 16-bit value 0xFFFE is inserted between the OUI and the NIC half (RFC 4291 §2.5.1).') ?></dt>
                                <dd class="derive-result__value">
                                    <code><?= htmlspecialchars((string)($derive['eui64'] ?? '')) ?></code>
                                    <?= copy_button((string)($derive['eui64'] ?? ''), 'Copy EUI-64 interface ID') ?>
                                </dd>
                            </div>
                            <div class="derive-result__row">
                                <dt class="derive-result__label">Link-local<?= help_bubble('ipv6-derive-ll', 'Link-local address — the fe80::/64 prefix concatenated with the EUI-64 interface identifier. Always assigned automatically to every IPv6-enabled interface (RFC 4291 §2.5.6).') ?></dt>
                                <dd class="derive-result__value">
                                    <code><?= htmlspecialchars((string)($derive['link_local'] ?? '')) ?></code>
                                    <?= copy_button((string)($derive['link_local'] ?? ''), 'Copy link-local address') ?>
                                </dd>
                            </div>
                            <div class="derive-result__row">
                                <dt class="derive-result__label">Solicited-node<?= help_bubble('ipv6-derive-sn', 'Solicited-node multicast address — ff02::1:ff followed by the low 24 bits of the unicast address. Used by IPv6 Neighbor Discovery so a host only listens for resolution requests targeted at its own address (RFC 4291 §2.7.1).') ?></dt>
                                <dd class="derive-result__value">
                                    <code><?= htmlspecialchars((string)($derive['solicited_node'] ?? '')) ?></code>
                                    <?= copy_button((string)($derive['solicited_node'] ?? ''), 'Copy solicited-node multicast address') ?>
                                </dd>
                            </div>
                        </dl>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tool-panel" data-tool="slaac">
                <div class="overlap-panel">
                    <div class="overlap-title">SLAAC Privacy<?= help_bubble('ipv6-slaac', 'Generates an RFC 8981 privacy interface identifier inside the supplied /64 prefix. The 64-bit interface ID is cryptographically random (PHP random_bytes), with the U/L bit cleared so it cannot be confused with an EUI-64 address derived from a real MAC. Production hosts should leave the seed blank; the seed input is provided only for reproducible documentation/testing output.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <label for="slaac_prefix" class="sr-only">IPv6 /64 prefix</label>
                        <div class="splitter-row">
                            <input type="text" id="slaac_prefix" name="slaac_prefix" class="splitter-input"
                                   placeholder="2001:db8:1:2::/64"
                                   value="<?= htmlspecialchars($slaac_prefix_input) ?>"
                                   autocomplete="off" spellcheck="false"
                                   required
                                   <?= !empty($slaac['error']) ? 'aria-invalid="true" aria-describedby="slaac-error"' : '' ?>>
                            <button type="submit" class="splitter-btn">Generate</button>
                            <button type="reset" class="splitter-btn-ghost">Reset</button>
                        </div>
                        <details class="slaac-advanced"<?= !empty($slaac['seed_was_provided']) ? ' open' : '' ?>>
                            <summary>Advanced (seed for reproducibility)</summary>
                            <p class="slaac-advanced__hint">Optional 16-hex seed for reproducible output (RFC 8981 §3.3.1). Leave blank in production &mdash; every fresh generation should be cryptographically random.</p>
                            <label for="slaac_seed" class="sr-only">Seed (16 hex characters)</label>
                            <input type="text" id="slaac_seed" name="slaac_seed" class="splitter-input"
                                   placeholder="a8d3f4e10c529837"
                                   value="<?= htmlspecialchars($slaac_seed_input) ?>"
                                   pattern="[0-9a-fA-F]{16}"
                                   maxlength="16"
                                   autocomplete="off" spellcheck="false">
                            <?= help_bubble('ipv6-slaac-seed', 'A 16-character hexadecimal seed (64 bits) makes the generated interface ID deterministic. Useful for reproducing examples in documentation or tests; never use a fixed seed in production because it defeats the privacy purpose of RFC 8981.') ?>
                        </details>
                    </form>
                    <?php if (!empty($slaac['error'])) : ?>
                        <div class="error" id="slaac-error"><?= htmlspecialchars($slaac['error']) ?></div>
                    <?php elseif (isset($slaac['address'])) : ?>
                        <?php if (!empty($slaac['warning'])) : ?>
                            <div class="warning"><?= htmlspecialchars($slaac['warning']) ?></div>
                        <?php endif; ?>
                        <?php $_slaac_label = 'SLAAC: ' . ($slaac['prefix'] ?? ''); ?>
                        <dl class="slaac-result"
                            data-history-source="slaac"
                            data-history-active="1"
                            data-history-label="<?= htmlspecialchars($_slaac_label) ?>">
                            <div class="slaac-result__row">
                                <dt class="slaac-result__label">Prefix (canonical)</dt>
                                <dd class="slaac-result__value">
                                    <code><?= htmlspecialchars((string)($slaac['prefix'] ?? '')) ?></code>
                                </dd>
                            </div>
                            <div class="slaac-result__row">
                                <dt class="slaac-result__label">Address<?= help_bubble('ipv6-slaac-addr', 'The full 128-bit IPv6 address: the supplied /64 prefix concatenated with the random 64-bit privacy interface identifier. This is what would be assigned to the host as a temporary SLAAC address per RFC 8981.') ?></dt>
                                <dd class="slaac-result__value">
                                    <code><?= htmlspecialchars((string)($slaac['address'] ?? '')) ?></code>
                                    <?= copy_button((string)($slaac['address'] ?? ''), 'Copy SLAAC privacy address') ?>
                                </dd>
                            </div>
                            <div class="slaac-result__row">
                                <dt class="slaac-result__label">Interface ID<?= help_bubble('ipv6-slaac-iid', 'The 64-bit random interface identifier in colon-separated hextet form. The U/L bit (second-lowest bit of the first byte) is cleared per RFC 4291 §2.5.1 so the address cannot be mistaken for an EUI-64 derived from a hardware MAC.') ?></dt>
                                <dd class="slaac-result__value">
                                    <code><?= htmlspecialchars((string)($slaac['interface_id'] ?? '')) ?></code>
                                    <?= copy_button((string)($slaac['interface_id'] ?? ''), 'Copy interface ID') ?>
                                </dd>
                            </div>
                            <div class="slaac-result__row">
                                <dt class="slaac-result__label">Seed used<?= help_bubble('ipv6-slaac-seed-used', 'The 16-hex seed value that produced this address. If you supplied a seed it is echoed here; otherwise the random seed used internally is shown so you can reproduce the result later (e.g. by pasting it back into the Advanced field).') ?></dt>
                                <dd class="slaac-result__value">
                                    <code><?= htmlspecialchars((string)($slaac['seed_used'] ?? '')) ?></code>
                                    <?= copy_button((string)($slaac['seed_used'] ?? ''), 'Copy seed') ?>
                                </dd>
                            </div>
                        </dl>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tool-panel" data-tool="lookup">
                <div class="overlap-panel">
                    <div class="overlap-title">IP Lookup<?= help_bubble('ipv6-lookup', 'For each IP, finds every CIDR that contains it. The "Deepest" column is the longest-prefix (most specific) match. Mixed IPv4/IPv6 inputs are allowed; CIDRs only match IPs of the same family. Caps: 100 CIDRs, 1000 IPs.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <div class="lookup-form-grid">
                            <label for="lookup_cidrs_v6" class="lookup-form-label">CIDRs <span class="lookup-form-hint">(one per line, max 100)</span></label>
                            <textarea id="lookup_cidrs_v6" name="lookup_cidrs" rows="4" class="multi-overlap-input"
                                      placeholder="2001:db8::/32&#10;2001:db8:1::/48&#10;10.0.0.0/8"
                                      autocomplete="off" spellcheck="false"><?= htmlspecialchars($active_tab === 'ipv6' ? $lookup_cidrs_input : '') ?></textarea>
                            <label for="lookup_ips_v6" class="lookup-form-label">IPs <span class="lookup-form-hint">(one per line, max 1000)</span></label>
                            <textarea id="lookup_ips_v6" name="lookup_ips" rows="4" class="multi-overlap-input"
                                      placeholder="2001:db8::1&#10;2001:db8:1::5&#10;10.1.2.3"
                                      autocomplete="off" spellcheck="false"><?= htmlspecialchars($active_tab === 'ipv6' ? $lookup_ips_input : '') ?></textarea>
                        </div>
                        <div class="splitter-row">
                            <button type="submit" class="splitter-btn">Lookup</button>
                        </div>
                    </form>
                    <?php if ($active_tab === 'ipv6' && $lookup_error) : ?>
                        <div class="error"><?= htmlspecialchars($lookup_error) ?></div>
                    <?php elseif ($active_tab === 'ipv6' && $lookup_result !== null) : ?>
                        <?php
                        $_lookup_ips_count6   = count(array_filter(array_map('trim', explode("\n", $lookup_ips_input))));
                        $_lookup_cidrs_count6 = count(array_filter(array_map('trim', explode("\n", $lookup_cidrs_input))));
                        $_lookup_label6 = sprintf(
                            'Lookup: %d IP%s in %d CIDR%s',
                            $_lookup_ips_count6,
                            $_lookup_ips_count6 !== 1 ? 's' : '',
                            $_lookup_cidrs_count6,
                            $_lookup_cidrs_count6 !== 1 ? 's' : ''
                        );
                        ?>
                        <div class="lookup-results"
                             data-history-source="lookup"
                             data-history-active="1"
                             data-history-label="<?= htmlspecialchars($_lookup_label6) ?>">
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
                                        <?php foreach ($lookup_result as $row) : ?>
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
                            <div class="split-more"><?= count($lookup_result) ?> IP<?= count($lookup_result) !== 1 ? 's' : '' ?> looked up</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tool-panel" data-tool="diff">
                <div class="overlap-panel">
                    <div class="overlap-title">Subnet Diff<?= help_bubble('ipv6-diff', 'Compares two CIDR lists. Inputs are canonicalised (host bits zeroed, IPv6 lowercased) before comparison. Reports added, removed, unchanged, and changed (same network address but different prefix length). Mixed IPv4/IPv6 inputs are allowed. Cap: 1000 entries per side.') ?></div>
                    <form method="post" novalidate>
                        <input type="hidden" name="tab" value="ipv6">
                        <div class="lookup-form-grid">
                            <label for="diff_before_v6" class="lookup-form-label">Before <span class="lookup-form-hint">(one CIDR per line)</span></label>
                            <textarea id="diff_before_v6" name="diff_before" rows="4" class="multi-overlap-input"
                                      placeholder="2001:db8::/48&#10;2001:db8:1::/48&#10;2001:db8:2::/48"
                                      autocomplete="off" spellcheck="false"><?= htmlspecialchars($active_tab === 'ipv6' ? $diff_before_input : '') ?></textarea>
                            <label for="diff_after_v6" class="lookup-form-label">After <span class="lookup-form-hint">(one CIDR per line)</span></label>
                            <textarea id="diff_after_v6" name="diff_after" rows="4" class="multi-overlap-input"
                                      placeholder="2001:db8::/47&#10;2001:db8:2::/48&#10;2001:db8:3::/48"
                                      autocomplete="off" spellcheck="false"><?= htmlspecialchars($active_tab === 'ipv6' ? $diff_after_input : '') ?></textarea>
                        </div>
                        <div class="splitter-row">
                            <button type="submit" class="splitter-btn">Diff</button>
                        </div>
                    </form>
                    <?php if ($active_tab === 'ipv6' && $diff_error) : ?>
                        <div class="error"><?= htmlspecialchars($diff_error) ?></div>
                    <?php elseif ($active_tab === 'ipv6' && $diff_result !== null) : ?>
                        <?php include __DIR__ . '/_diff_result.php'; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
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
                <p class="vlsm-sort-note">Results sorted largest-first for efficient allocation.<?= help_bubble('vlsm-sort', 'Subnets are allocated from largest to smallest so that larger blocks can be placed at aligned boundaries without wasting address space.') ?></p>
                <table class="vlsm-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Hosts Needed<?= help_bubble('vlsm-hosts', 'The number of usable host addresses you requested for this subnet. The allocated block is the smallest power-of-2 that satisfies this requirement (plus network and broadcast addresses).') ?></th>
                            <th>Allocated Subnet</th>
                            <th>Usable</th>
                            <th>Waste<?= help_bubble('vlsm-waste', 'Usable addresses in the allocated block minus the hosts you requested. Larger values indicate more address space is reserved than strictly needed.') ?></th>
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
                            <td><?= format_number($alloc['hosts_needed']) ?></td>
                            <td class="vlsm-subnet-cell" tabindex="0" role="button"
                                title="Click to copy" data-copy="<?= htmlspecialchars($alloc['subnet']) ?>">
                                <code><?= htmlspecialchars($alloc['subnet']) ?></code>
                            </td>
                            <td><?= format_number($alloc['usable']) ?></td>
                            <td class="vlsm-waste"><?= format_number($alloc['waste']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="copy-actions" data-kind="vlsm">
                <button type="button" class="copy-all-btn" data-target="vlsm">Copy All</button>
                <button type="button" class="copy-all-btn copy-md-btn" data-target="vlsm">Copy as Markdown</button>
                <button type="button" class="copy-all-btn copy-cisco-btn" data-target="vlsm">Copy as Cisco</button><?= help_bubble('copy-cisco-vlsm', 'Cisco output is generic IOS-style — one interface stanza per VLSM allocation. Vendor-specific tweaks may be required.') ?>
            </div>
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
                <span>Hosts requested: <strong><?= format_number($vlsm_total_hosts_req) ?></strong></span>
                <span>Allocated: <strong><?= format_number($vlsm_total_allocated) ?></strong> addresses</span>
                <span>Remaining: <strong><?= format_number($vlsm_remaining) ?></strong></span>
                <span>Utilisation: <strong><?= $vlsm_util_pct ?>%</strong><?= help_bubble('vlsm-util', 'Total allocated addresses ÷ total addresses in the parent subnet × 100. Values below 100% indicate unallocated space remaining in the parent block.') ?></span>
            </div>
            <div class="export-btn-group">
                <button type="button" id="vlsm-export-csv">Export CSV</button>
                <button type="button" id="vlsm-export-json">Export JSON</button>
                <button type="button" id="vlsm-export-xlsx">Export XLSX</button>
                <button type="button" id="vlsm-export-ascii">Export ASCII</button>
            </div>
            <?php if ($show_share_bar && $share_url !== '') : ?>
            <div class="share-bar">
                <span class="share-label">Share</span>
                <code class="share-url"><?= htmlspecialchars($share_url_abs) ?></code>
                <button type="button" class="share-copy" data-copy="<?= htmlspecialchars($share_url) ?>">Copy</button>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php
        $open_tool_vlsm = null;
        if ($session_save_id !== '' || $session_error !== null) { $open_tool_vlsm = 'session'; }
        elseif ($overlap_result !== null || $overlap_error !== null) { $open_tool_vlsm = 'overlap'; }
        elseif ($multi_overlap_result !== null || $multi_overlap_error !== null) { $open_tool_vlsm = 'multi'; }
        ?>
        <div class="tool-toolbar"<?= $open_tool_vlsm ? ' data-open-tool="' . htmlspecialchars($open_tool_vlsm) . '"' : '' ?>>
            <?php if ($session_enabled) : ?>
            <button type="button" class="tool-trigger" data-tool="session" aria-expanded="false">Save Session</button>
            <?php endif; ?>
            <button type="button" class="tool-trigger" data-tool="overlap" aria-expanded="false">Overlap Check</button>
            <button type="button" class="tool-trigger" data-tool="multi" aria-expanded="false">Multi-CIDR Overlap</button>
        </div>

        <div class="tool-drawer" role="dialog" aria-modal="true" aria-labelledby="drawer-title-vlsm">
            <div class="tool-drawer-header">
                <span class="tool-drawer-title" id="drawer-title-vlsm">Tool</span>
                <button type="button" class="tool-drawer-close" aria-label="Close">&times;</button>
            </div>

            <?php if ($session_enabled) : ?>
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
            <?php endif; ?>

            <div class="tool-panel" data-tool="overlap">
                <div class="overlap-panel">
                    <div class="overlap-title">Subnet Overlap Checker<?= help_bubble('overlap-two', 'Compares two CIDRs and reports whether they overlap, one contains the other, are identical, or have no relationship. Supports both IPv4 and IPv6.') ?></div>
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
                            'none'         => ['No overlap', 'overlap-none'],
                            'identical'    => ['Identical subnets', 'overlap-identical'],
                            'a_contains_b' => [$overlap_cidr_a . ' contains ' . $overlap_cidr_b, 'overlap-contains'],
                            'b_contains_a' => [$overlap_cidr_b . ' contains ' . $overlap_cidr_a, 'overlap-contains'],
                        ];
                        [$label, $cls] = $overlap_labels[$overlap_result] ?? ['Unknown', ''];
                        ?>
                        <div class="overlap-result <?= htmlspecialchars($cls) ?>"><?= htmlspecialchars($label) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tool-panel" data-tool="multi">
                <div class="overlap-panel multi-overlap-panel">
                    <div class="overlap-title">Multi-CIDR Overlap Check<?= help_bubble('multi-cidr', 'Enter up to 50 IPv4 or IPv6 CIDRs, one per line. The tool reports any pairs that overlap, are identical, or where one contains the other.') ?></div>
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
        </div>
    </div>

    <!-- VLSM IPv6 Panel -->
    <div id="panel-vlsm6" class="panel<?= $active_tab === 'vlsm6' ? ' active' : '' ?>"
         role="tabpanel" aria-labelledby="tab-vlsm6" tabindex="-1">
        <form method="post" class="vlsm6-form" novalidate>
            <input type="hidden" name="tab" value="vlsm6">
            <div class="form-row">
                <div class="form-group">
                    <label for="vlsm6_network">Parent Network</label>
                    <input type="text" id="vlsm6_network" name="vlsm6_network"
                           value="<?= htmlspecialchars($vlsm6_network) ?>"
                           placeholder="2001:db8::" autocomplete="off" spellcheck="false">
                </div>
                <div class="form-group form-group--mask">
                    <label for="vlsm6_cidr">Prefix</label>
                    <input type="text" id="vlsm6_cidr" name="vlsm6_cidr"
                           value="<?= htmlspecialchars($vlsm6_cidr_input) ?>"
                           placeholder="/48" autocomplete="off" spellcheck="false">
                </div>
            </div>
            <div class="vlsm-reqs" id="vlsm6-reqs">
                <div class="vlsm-reqs-header">
                    <span class="vlsm-col-name">Name</span>
                    <span class="vlsm-col-hosts">Hosts Needed<?= help_bubble('vlsm6-hosts-input', 'Enter a positive integer (e.g. 50) or a power-of-two expression (e.g. 2^64) for very large IPv6 sizings.') ?></span>
                </div>
                <?php if ($vlsm6_requirements) : ?>
                    <?php foreach ($vlsm6_requirements as $req6) : ?>
                    <div class="vlsm-req-row">
                        <input type="text" name="vlsm6_name[]" class="vlsm6-name-input"
                               value="<?= htmlspecialchars($req6['name']) ?>" placeholder="e.g. Site A" autocomplete="off">
                        <input type="text" name="vlsm6_hosts[]" class="vlsm6-hosts-input"
                               value="<?= htmlspecialchars((string)$req6['hosts']) ?>" placeholder="e.g. 256 or 2^64" autocomplete="off" spellcheck="false">
                        <button type="button" class="vlsm-remove-row" aria-label="Remove row">&times;</button>
                    </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="vlsm-req-row">
                        <input type="text" name="vlsm6_name[]" class="vlsm6-name-input" placeholder="e.g. Site A" autocomplete="off">
                        <input type="text" name="vlsm6_hosts[]" class="vlsm6-hosts-input" placeholder="e.g. 256 or 2^64" autocomplete="off" spellcheck="false">
                        <button type="button" class="vlsm-remove-row" aria-label="Remove row">&times;</button>
                    </div>
                <?php endif; ?>
            </div>
            <div class="vlsm-actions">
                <button type="button" class="vlsm6-add-row">+ Add Subnet</button>
                <button type="submit" class="btn">Calculate</button>
                <a href="?tab=vlsm6" class="btn reset">Reset</a>
            </div>
        </form>
        <?php if ($vlsm6_error) : ?>
            <div class="error"><?= htmlspecialchars($vlsm6_error) ?></div>
        <?php elseif ($vlsm6_result !== null) : ?>
            <div class="vlsm-results">
                <p class="vlsm-sort-note">Results sorted largest-first for efficient allocation.<?= help_bubble('vlsm6-sort', 'Subnets are allocated from largest to smallest so that larger blocks can be placed at aligned boundaries without wasting address space.') ?></p>
                <table class="vlsm-table vlsm6-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Hosts Needed<?= help_bubble('vlsm6-hosts', 'The number of usable host addresses you requested. Every IPv6 address in the allocated block is usable (no broadcast / network reservation).') ?></th>
                            <th>Allocated Subnet</th>
                            <th>Usable<?= help_bubble('vlsm6-usable', 'Total addresses in the allocated block. For very large blocks, the count is shown as 2^N.') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($vlsm6_result as $alloc6) : ?>
                        <tr>
                            <td><?= htmlspecialchars($alloc6['name']) ?></td>
                            <td><?= htmlspecialchars((string)$alloc6['hosts_needed']) ?></td>
                            <td class="vlsm-subnet-cell vlsm6-subnet-cell" tabindex="0" role="button"
                                title="Click to copy" data-copy="<?= htmlspecialchars($alloc6['subnet']) ?>">
                                <code><?= htmlspecialchars($alloc6['subnet']) ?></code>
                            </td>
                            <td><?= htmlspecialchars((string)$alloc6['usable']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="copy-actions" data-kind="vlsm6">
                <button type="button" class="copy-all-btn" data-target="vlsm6">Copy All</button>
                <button type="button" class="copy-all-btn copy-md-btn" data-target="vlsm6">Copy as Markdown</button>
                <button type="button" class="copy-all-btn copy-cisco-btn" data-target="vlsm6">Copy as Cisco</button><?= help_bubble('copy-cisco-vlsm6', 'Cisco output is generic IOS-style — one interface stanza per IPv6 VLSM allocation using ipv6 address. Vendor-specific tweaks may be required.') ?>
            </div>
            <?php
            $vlsm6_parent_cidr_int = (int)ltrim((string)$vlsm6_cidr_input, '/');
            $vlsm6_parent_total    = gmp_pow(gmp_init(2), 128 - $vlsm6_parent_cidr_int);
            $vlsm6_total_allocated = gmp_init(0);
            $vlsm6_total_hosts_req = gmp_init(0);
            foreach ($vlsm6_result as $alloc6) {
                [, $vlsm6_alloc_pfx_str] = explode('/', $alloc6['subnet']);
                $vlsm6_alloc_pfx = (int)$vlsm6_alloc_pfx_str;
                $vlsm6_total_allocated = gmp_add(
                    $vlsm6_total_allocated,
                    gmp_pow(gmp_init(2), 128 - $vlsm6_alloc_pfx)
                );
                $vlsm6_hosts_raw = (string)$alloc6['hosts_needed'];
                if (preg_match('/^2\^(\d+)$/', $vlsm6_hosts_raw, $m_hr) === 1) {
                    $vlsm6_total_hosts_req = gmp_add(
                        $vlsm6_total_hosts_req,
                        gmp_pow(gmp_init(2), (int)$m_hr[1])
                    );
                } elseif (preg_match('/^\d+$/', $vlsm6_hosts_raw) === 1) {
                    $vlsm6_total_hosts_req = gmp_add($vlsm6_total_hosts_req, gmp_init($vlsm6_hosts_raw));
                }
            }
            $vlsm6_remaining = gmp_sub($vlsm6_parent_total, $vlsm6_total_allocated);

            $vlsm6_fmt_count = static function (\GMP $g): string {
                $s = gmp_strval($g);
                if ($s === '0') {
                    return '0';
                }
                // Native int range — show the exact comma-formatted decimal.
                if (strlen($s) <= 15) {
                    return format_number((int)$s);
                }
                // Otherwise, only use 2^N shorthand when the value is exactly
                // a power of two; never round non-powers up to the next 2^N.
                $bin = gmp_strval($g, 2);
                if (preg_match('/^10+$/', $bin) === 1) {
                    return '2^' . (strlen($bin) - 1);
                }
                return preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $s) ?? $s;
            };

            // Utilisation %: when both fit in float, compute exact; else show approximation
            // via log2 difference: 100 * 2^(log2(allocated) - log2(parent_total)).
            $vlsm6_float_safe = gmp_cmp($vlsm6_parent_total, gmp_pow(gmp_init(2), 53)) <= 0;
            if (gmp_cmp($vlsm6_parent_total, gmp_init(0)) <= 0) {
                $vlsm6_util_display = '0%';
            } elseif ($vlsm6_float_safe) {
                $pct = ((float)gmp_strval($vlsm6_total_allocated) / (float)gmp_strval($vlsm6_parent_total)) * 100.0;
                $vlsm6_util_display = round($pct, 6) . '%';
            } else {
                // Approximate: ratio = allocated / parent_total. Compute via shifting down to a safe magnitude.
                $shift = 0;
                $denom = $vlsm6_parent_total;
                $numer = $vlsm6_total_allocated;
                $cap = gmp_pow(gmp_init(2), 53);
                while (gmp_cmp($denom, $cap) > 0) {
                    $denom = gmp_div_q($denom, gmp_init(2));
                    $numer = gmp_div_q($numer, gmp_init(2));
                    $shift++;
                }
                $denom_f = (float)gmp_strval($denom);
                $numer_f = (float)gmp_strval($numer);
                $pct = $denom_f > 0 ? ($numer_f / $denom_f) * 100.0 : 0.0;
                $vlsm6_util_display = '~' . round($pct, 6) . '%';
            }
            ?>
            <div class="vlsm-summary">
                <span>Hosts requested: <strong><?= htmlspecialchars($vlsm6_fmt_count($vlsm6_total_hosts_req)) ?></strong></span>
                <span>Allocated: <strong><?= htmlspecialchars($vlsm6_fmt_count($vlsm6_total_allocated)) ?></strong> addresses</span>
                <span>Remaining: <strong><?= htmlspecialchars($vlsm6_fmt_count($vlsm6_remaining)) ?></strong></span>
                <span>Utilisation: <strong><?= htmlspecialchars($vlsm6_util_display) ?></strong><?= help_bubble('vlsm6-util', 'Total allocated addresses ÷ total addresses in the parent /N × 100. For very large IPv6 totals (parent &gt; 2^53), the percentage is shown as an approximation prefixed with ~.') ?></span>
            </div>
            <div class="export-btn-group">
                <button type="button" id="vlsm6-export-csv">Export CSV</button>
                <button type="button" id="vlsm6-export-json">Export JSON</button>
                <button type="button" id="vlsm6-export-ascii">Export ASCII</button>
            </div>
            <?php if ($show_share_bar && $share_url !== '') : ?>
            <div class="share-bar">
                <span class="share-label">Share</span>
                <code class="share-url"><?= htmlspecialchars($share_url_abs) ?></code>
                <button type="button" class="share-copy" data-copy="<?= htmlspecialchars($share_url) ?>">Copy</button>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php
        // v3.1.0 (#321) — IPv6 VLSM Save Session moved into the tool-drawer
        // pattern to match the IPv4 VLSM tab. Auto-open the session6 drawer
        // after a successful save (or session error) on the vlsm6 tab.
        $open_tool_vlsm6 = null;
        if ($session_enabled && $active_tab === 'vlsm6'
            && ($session_save_id !== '' || $session_error !== null)) {
            $open_tool_vlsm6 = 'session6';
        }
        ?>
        <?php if ($session_enabled) : ?>
        <div class="tool-toolbar"<?= $open_tool_vlsm6 ? ' data-open-tool="' . htmlspecialchars($open_tool_vlsm6) . '"' : '' ?>>
            <button type="button" class="tool-trigger" data-tool="session6" aria-expanded="false">Save Session</button>
        </div>

        <div class="tool-drawer" role="dialog" aria-modal="true" aria-labelledby="drawer-title-vlsm6">
            <div class="tool-drawer-header">
                <span class="tool-drawer-title" id="drawer-title-vlsm6">Tool</span>
                <button type="button" class="tool-drawer-close" aria-label="Close">&times;</button>
            </div>

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
        </div>
        <?php endif; ?>
    </div>

    <footer>
        <a href="https://github.com/seanmousseau/Subnet-Calculator" target="_blank" rel="noopener noreferrer">GitHub</a>
        &nbsp;&middot;&nbsp;
        <a href="https://docs.subnetcalculator.app/" target="_blank" rel="noreferrer">Docs</a>
    </footer>
</main>

<div id="toast" class="toast" role="status" aria-live="polite" aria-atomic="true">Copied!</div>

<!-- v3.0.0 (#300) keyboard shortcut overlay -->
<div id="kbd-overlay" class="modal-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="kbd-overlay-title">
    <div class="modal">
        <div class="modal-header">
            <h2 id="kbd-overlay-title">Keyboard shortcuts</h2>
            <button type="button" class="modal-close" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <dl class="kbd-list">
                <dt><kbd>?</kbd></dt><dd>Show this help</dd>
                <dt><kbd>Esc</kbd></dt><dd>Close any open overlay or tool drawer</dd>
                <dt><kbd>1</kbd> &hellip; <kbd>4</kbd></dt><dd>Switch tabs (IPv4, IPv6, VLSM, VLSM IPv6) and focus the first input</dd>
                <dt><kbd>/</kbd></dt><dd>Focus the first input on the active tab</dd>
                <dt><kbd>Enter</kbd></dt><dd>Submit the current form (native; from any input)</dd>
                <dt><kbd>Ctrl</kbd>+<kbd>R</kbd> / <kbd>Cmd</kbd>+<kbd>R</kbd></dt><dd>Reset the active tab (intercepts browser reload)</dd>
                <dt><kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>C</kbd></dt><dd>Copy the first result on the active tab</dd>
                <dt><kbd>H</kbd></dt><dd>Open recent calculations history</dd>
            </dl>
            <p class="modal-note">Shortcuts are ignored while typing in inputs (other than <kbd>Enter</kbd>, <kbd>Esc</kbd>, and <kbd>Ctrl</kbd>+<kbd>R</kbd>).</p>
        </div>
    </div>
</div>

<!-- v3.0.0 (#301) recent calculations history overlay -->
<div id="history-overlay" class="modal-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="history-overlay-title">
    <div class="modal">
        <div class="modal-header">
            <h2 id="history-overlay-title">Recent calculations</h2>
            <button type="button" class="modal-close" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="history-controls">
                <label class="history-toggle-label">
                    <input type="checkbox" id="history-enabled-toggle">
                    <span>Remember calculations on this device</span>
                </label>
                <button type="button" id="history-clear" class="btn btn-small" hidden>Clear all</button>
            </div>
            <p class="modal-note" id="history-empty-msg" hidden>No history yet. Run a calculation with history enabled and it will appear here.</p>
            <p class="modal-note" id="history-disabled-msg" hidden>History is off. Enable it above to start recording calculations on this device.</p>
            <ul class="history-list" id="history-list"></ul>
            <p class="modal-note">Stored in this browser only. Up to 50 most-recent entries (FIFO).</p>
        </div>
    </div>
</div>

<script defer src="assets/vendor/xlsx/xlsx.full.min.js" integrity="sha384-EnyY0/GSHQGSxSgMwaIPzSESbqoOLSexfnSMN2AP+39Ckmn92stwABZynq1JyzdT" crossorigin="anonymous"></script>
<script src="assets/app.js?v=<?= $app_version ?>"></script>
</body>
</html>
