<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php // v3.4.0 — anchor relative URLs to the app root so /ipv6/derive
          // and other per-tool routes resolve assets and nav links correctly. ?>
    <base href="<?= htmlspecialchars($app_base_path) ?>">
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

    <nav aria-label="IP version">
        <div class="tabs" role="tablist">
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
    </nav>

    <!-- IPv4 Panel -->
    <div id="panel-ipv4" class="panel<?= $active_tab === 'ipv4' ? ' active' : '' ?>"
         role="tabpanel" aria-labelledby="tab-ipv4" tabindex="-1"
         <?= $active_tab !== 'ipv4' ? 'hidden inert' : '' ?>>
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
                <button type="button" class="btn reset" data-reset-tab="">Reset</button>
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
            <div class="error" id="ipv4-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
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
                <?php if ($session_enabled) : ?>
                <button type="button" class="share-shorten"
                        data-tab="ipv4"
                        data-ip="<?= htmlspecialchars($input_ip, ENT_QUOTES, 'UTF-8') ?>"
                        data-mask="<?= htmlspecialchars($input_mask, ENT_QUOTES, 'UTF-8') ?>">Shorten</button>
                <?php endif; ?>
                <button type="button" class="share-copy" data-copy="<?= htmlspecialchars($share_url) ?>">Copy</button>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php
        $open_tool_ipv4 = null;
        if (!empty($splitter)) { $open_tool_ipv4 = 'split'; }
        elseif (!empty($supernet)) { $open_tool_ipv4 = 'supernet'; }
        elseif (!empty($range)) { $open_tool_ipv4 = 'range'; }
        elseif (!empty($tree)) { $open_tool_ipv4 = 'tree'; }
        elseif (!empty($wildcard)) { $open_tool_ipv4 = 'wildcard'; }
        elseif (!empty($lookup) && $active_tab === 'ipv4') { $open_tool_ipv4 = 'lookup'; }
        elseif (!empty($diff) && $active_tab === 'ipv4') { $open_tool_ipv4 = 'diff'; }
        // v3.4.0 — fallback: /ipv4/<tool> rewrites to ?tool=<tool>; honour it
        // when no other GET trigger has already chosen a tool above.
        $ipv4_tool_whitelist = ['split','supernet','range','tree','tree-editor','wildcard','lookup','diff'];
        if ($open_tool_ipv4 === null && $active_tab === 'ipv4'
            && in_array($requested_tool, $ipv4_tool_whitelist, true)) {
            $open_tool_ipv4 = $requested_tool;
        }
        ?>
        <div class="tool-toolbar"<?= $open_tool_ipv4 ? ' data-open-tool="' . htmlspecialchars($open_tool_ipv4) . '"' : '' ?>>
            <div class="tool-toolbar-group">
                <span class="tool-toolbar-group-label">Transform</span>
                <button type="button" class="tool-trigger" data-tool="split" aria-expanded="false">Split Subnet</button>
                <button type="button" class="tool-trigger" data-tool="supernet" aria-expanded="false">Supernet</button>
                <button type="button" class="tool-trigger" data-tool="range" aria-expanded="false">Range&rarr;CIDR</button>
                <button type="button" class="tool-trigger" data-tool="wildcard" aria-expanded="false">Wildcard&harr;CIDR</button>
            </div>
            <div class="tool-toolbar-group">
                <span class="tool-toolbar-group-label">Visualize</span>
                <button type="button" class="tool-trigger" data-tool="tree" aria-expanded="false">Subnet Tree</button>
                <button type="button" class="tool-trigger" data-tool="tree-editor" aria-expanded="false">Tree Editor</button>
            </div>
            <div class="tool-toolbar-group">
                <span class="tool-toolbar-group-label">Lookups</span>
                <button type="button" class="tool-trigger" data-tool="lookup" aria-expanded="false">IP Lookup</button>
                <button type="button" class="tool-trigger" data-tool="diff" aria-expanded="false">Subnet Diff</button>
            </div>
        </div>

        <div class="tool-drawer" role="dialog" aria-modal="true" aria-labelledby="drawer-title-ipv4">
            <div class="tool-drawer-header">
                <span class="tool-drawer-title" id="drawer-title-ipv4">Tool</span>
                <button type="button" class="tool-drawer-close" aria-label="Close">&times;</button>
            </div>

            <?php require __DIR__ . '/_tools/split.php'; ?>

            <?php require __DIR__ . '/_tools/supernet.php'; ?>

            <?php require __DIR__ . '/_tools/range.php'; ?>

            <?php require __DIR__ . '/_tools/tree.php'; ?>

            <?php require __DIR__ . '/_tools/tree-editor.php'; ?>

            <?php require __DIR__ . '/_tools/wildcard.php'; ?>

            <?php require __DIR__ . '/_tools/lookup.php'; ?>

            <?php require __DIR__ . '/_tools/diff.php'; ?>
        </div>
    </div>

    <!-- IPv6 Panel -->
    <div id="panel-ipv6" class="panel<?= $active_tab === 'ipv6' ? ' active' : '' ?>"
         role="tabpanel" aria-labelledby="tab-ipv6" tabindex="-1"
         <?= $active_tab !== 'ipv6' ? 'hidden inert' : '' ?>>
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
                <button type="button" class="btn reset" data-reset-tab="ipv6">Reset</button>
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
            <div class="error" id="ipv6-error" role="alert"><?= htmlspecialchars($error6, ENT_QUOTES, 'UTF-8') ?></div>
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
                <?php if ($session_enabled) : ?>
                <button type="button" class="share-shorten"
                        data-tab="ipv6"
                        data-ip="<?= htmlspecialchars($input_ipv6, ENT_QUOTES, 'UTF-8') ?>"
                        data-mask="<?= htmlspecialchars($input_prefix, ENT_QUOTES, 'UTF-8') ?>">Shorten</button>
                <?php endif; ?>
                <button type="button" class="share-copy" data-copy="<?= htmlspecialchars($share_url) ?>">Copy</button>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php
        $open_tool_ipv6 = null;
        if (!empty($splitter6)) { $open_tool_ipv6 = 'split6'; }
        elseif (!empty($ula)) { $open_tool_ipv6 = 'ula'; }
        elseif (!empty($range6)) { $open_tool_ipv6 = 'range6'; }
        elseif (!empty($supernet6)) { $open_tool_ipv6 = 'supernet6'; }
        elseif (!empty($zoneid)) { $open_tool_ipv6 = 'zoneid'; }
        elseif (!empty($derive)) { $open_tool_ipv6 = 'derive'; }
        elseif (!empty($slaac)) { $open_tool_ipv6 = 'slaac'; }
        elseif (!empty($lookup) && $active_tab === 'ipv6') { $open_tool_ipv6 = 'lookup'; }
        elseif (!empty($diff) && $active_tab === 'ipv6') { $open_tool_ipv6 = 'diff'; }
        elseif (!empty($rdns6)) { $open_tool_ipv6 = 'rdns6'; }
        elseif (!empty($mapped6)) { $open_tool_ipv6 = 'mapped6'; }
        elseif (!empty($embedded_v4)) { $open_tool_ipv6 = 'embedded-v4'; }
        elseif (!empty($sixtofour)) { $open_tool_ipv6 = '6to4'; }
        elseif (!empty($teredo)) { $open_tool_ipv6 = 'teredo'; }
        elseif (!empty($isatap)) { $open_tool_ipv6 = 'isatap'; }
        elseif (!empty($sixrd)) { $open_tool_ipv6 = '6rd'; }
        elseif (!empty($prefix_plan6)) { $open_tool_ipv6 = 'prefix-plan'; }
        elseif (!empty($nibble6)) { $open_tool_ipv6 = 'nibble'; }
        elseif (!empty($rfc3531)) { $open_tool_ipv6 = 'rfc3531'; }
        elseif (!empty($multicast6)) { $open_tool_ipv6 = 'multicast'; }
        elseif (!empty($ssm6)) { $open_tool_ipv6 = 'ssm'; }
        elseif (!empty($embedded_rp6)) { $open_tool_ipv6 = 'embedded-rp'; }
        elseif (!empty($pmtu6)) { $open_tool_ipv6 = 'pmtu'; }
        // v3.4.0 — fallback: /ipv6/<tool> rewrites to ?tool=<tool>; honour it
        // when no other GET trigger has already chosen a tool above.
        $ipv6_tool_whitelist = ['split6','ula','range6','supernet6','zoneid','derive','slaac','lookup','diff','rdns6','mapped6','embedded-v4','6to4','teredo','isatap','6rd','prefix-plan','nibble','rfc3531','multicast','ssm','embedded-rp','pmtu'];
        if ($open_tool_ipv6 === null && $active_tab === 'ipv6'
            && in_array($requested_tool, $ipv6_tool_whitelist, true)) {
            $open_tool_ipv6 = $requested_tool;
        }
        ?>
        <div class="tool-toolbar tool-toolbar-grouped"<?= $open_tool_ipv6 ? ' data-open-tool="' . htmlspecialchars($open_tool_ipv6) . '"' : '' ?>>
            <details class="tool-group" data-group="foundational" open>
                <summary class="tool-group-summary">Foundational</summary>
                <div class="tool-group-tools">
                    <button type="button" class="tool-trigger" data-tool="split6" aria-expanded="false">Split Subnet</button>
                    <button type="button" class="tool-trigger" data-tool="ula" aria-expanded="false">ULA Generator</button>
                    <button type="button" class="tool-trigger" data-tool="range6" aria-expanded="false">Range&rarr;CIDR</button>
                    <button type="button" class="tool-trigger" data-tool="supernet6" aria-expanded="false">Supernet</button>
                </div>
            </details>
            <details class="tool-group" data-group="address-utilities" open>
                <summary class="tool-group-summary">Address utilities</summary>
                <div class="tool-group-tools">
                    <button type="button" class="tool-trigger" data-tool="zoneid" aria-expanded="false">Zone ID</button>
                    <button type="button" class="tool-trigger" data-tool="derive" aria-expanded="false">Derive Address</button>
                    <button type="button" class="tool-trigger" data-tool="slaac" aria-expanded="false">SLAAC Privacy</button>
                    <button type="button" class="tool-trigger" data-tool="lookup" aria-expanded="false">IP Lookup</button>
                    <button type="button" class="tool-trigger" data-tool="diff" aria-expanded="false">Subnet Diff</button>
                    <button type="button" class="tool-trigger" data-tool="rdns6" aria-expanded="false">Reverse DNS</button>
                </div>
            </details>
            <details class="tool-group" data-group="transition" open>
                <summary class="tool-group-summary">Transition</summary>
                <div class="tool-group-tools">
                    <button type="button" class="tool-trigger" data-tool="mapped6" aria-expanded="false">IPv4-mapped / NAT64</button>
                    <button type="button" class="tool-trigger" data-tool="embedded-v4" aria-expanded="false">Embedded IPv4</button>
                    <button type="button" class="tool-trigger" data-tool="6to4" aria-expanded="false">6to4</button>
                    <button type="button" class="tool-trigger" data-tool="teredo" aria-expanded="false">Teredo</button>
                    <button type="button" class="tool-trigger" data-tool="isatap" aria-expanded="false">ISATAP</button>
                    <button type="button" class="tool-trigger" data-tool="6rd" aria-expanded="false">6rd</button>
                </div>
            </details>
            <details class="tool-group" data-group="prefix-planning" open>
                <summary class="tool-group-summary">Prefix planning</summary>
                <div class="tool-group-tools">
                    <button type="button" class="tool-trigger" data-tool="prefix-plan" aria-expanded="false">Prefix Plan</button>
                    <button type="button" class="tool-trigger" data-tool="nibble" aria-expanded="false">Nibble Neighbours</button>
                    <button type="button" class="tool-trigger" data-tool="rfc3531" aria-expanded="false">RFC 3531 Sparse</button>
                </div>
            </details>
            <details class="tool-group" data-group="multicast" open>
                <summary class="tool-group-summary">Multicast</summary>
                <div class="tool-group-tools">
                    <button type="button" class="tool-trigger" data-tool="multicast" aria-expanded="false">Multicast Decoder</button>
                    <button type="button" class="tool-trigger" data-tool="ssm" aria-expanded="false">SSM (RFC 3306)</button>
                    <button type="button" class="tool-trigger" data-tool="embedded-rp" aria-expanded="false">Embedded-RP (RFC 3956)</button>
                    <button type="button" class="tool-trigger" data-tool="pmtu" aria-expanded="false">PMTU Helper</button>
                </div>
            </details>
        </div>

        <div class="tool-drawer" role="dialog" aria-modal="true" aria-labelledby="drawer-title-ipv6">
            <div class="tool-drawer-header">
                <span class="tool-drawer-title" id="drawer-title-ipv6">Tool</span>
                <button type="button" class="tool-drawer-close" aria-label="Close">&times;</button>
            </div>

            <?php require __DIR__ . '/_tools/split6.php'; ?>

            <?php require __DIR__ . '/_tools/ula.php'; ?>

            <?php require __DIR__ . '/_tools/range6.php'; ?>

            <?php require __DIR__ . '/_tools/supernet6.php'; ?>

            <?php require __DIR__ . '/_tools/zoneid.php'; ?>

            <?php require __DIR__ . '/_tools/derive.php'; ?>

            <?php require __DIR__ . '/_tools/slaac.php'; ?>

            <?php require __DIR__ . '/_tools/lookup-ipv6.php'; ?>

            <?php require __DIR__ . '/_tools/diff-ipv6.php'; ?>

            <?php require __DIR__ . '/_tools/rdns6.php'; ?>

            <?php require __DIR__ . '/_tools/mapped6.php'; ?>

            <?php require __DIR__ . '/_tools/embedded-v4.php'; ?>

            <?php require __DIR__ . '/_tools/6to4.php'; ?>

            <?php require __DIR__ . '/_tools/teredo.php'; ?>

            <?php require __DIR__ . '/_tools/isatap.php'; ?>

            <?php require __DIR__ . '/_tools/6rd.php'; ?>
            <?php require __DIR__ . '/_tools/prefix-plan.php'; ?>
            <?php require __DIR__ . '/_tools/nibble.php'; ?>
            <?php require __DIR__ . '/_tools/rfc3531.php'; ?>

            <?php require __DIR__ . '/_tools/multicast.php'; ?>

            <?php require __DIR__ . '/_tools/ssm.php'; ?>

            <?php require __DIR__ . '/_tools/embedded-rp.php'; ?>

            <?php require __DIR__ . '/_tools/pmtu.php'; ?>
        </div>
    </div>

    <!-- VLSM Panel -->
    <div id="panel-vlsm" class="panel<?= $active_tab === 'vlsm' ? ' active' : '' ?>"
         role="tabpanel" aria-labelledby="tab-vlsm" tabindex="-1"
         <?= $active_tab !== 'vlsm' ? 'hidden inert' : '' ?>>
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
                <button type="button" class="btn reset" data-reset-tab="vlsm">Reset</button>
            </div>
        </form>
        <?php if (!empty($vlsm['error'])) : ?>
            <div class="error" role="alert"><?= htmlspecialchars($vlsm['error'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif (isset($vlsm['result'])) : ?>
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
                        <?php foreach ($vlsm['result'] as $alloc) :
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
            foreach ($vlsm['result'] as $alloc) {
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
        elseif (!empty($overlap)) { $open_tool_vlsm = 'overlap'; }
        elseif (!empty($multi_overlap)) { $open_tool_vlsm = 'multi'; }
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
            <?php require __DIR__ . '/_tools/session.php'; ?>
            <?php endif; ?>

            <?php require __DIR__ . '/_tools/overlap.php'; ?>

            <?php require __DIR__ . '/_tools/multi.php'; ?>
        </div>
    </div>

    <!-- VLSM IPv6 Panel -->
    <div id="panel-vlsm6" class="panel<?= $active_tab === 'vlsm6' ? ' active' : '' ?>"
         role="tabpanel" aria-labelledby="tab-vlsm6" tabindex="-1"
         <?= $active_tab !== 'vlsm6' ? 'hidden inert' : '' ?>>
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
                <button type="button" class="btn reset" data-reset-tab="vlsm6">Reset</button>
            </div>
        </form>
        <?php if (!empty($vlsm6['error'])) : ?>
            <div class="error" role="alert"><?= htmlspecialchars($vlsm6['error'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif (isset($vlsm6['result'])) : ?>
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
                        <?php foreach ($vlsm6['result'] as $alloc6) : ?>
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
            foreach ($vlsm6['result'] as $alloc6) {
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

            <?php require __DIR__ . '/_tools/session6.php'; ?>
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
