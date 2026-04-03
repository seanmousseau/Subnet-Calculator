<?php

// ─── IPv4 ─────────────────────────────────────────────────────────────────────

function cidr_to_mask(int $cidr): string {
    $mask = $cidr === 0 ? 0 : (~0 << (32 - $cidr));
    return long2ip($mask);
}

function mask_to_cidr(string $mask): int {
    return strlen(str_replace('0', '', decbin(ip2long($mask))));
}

function is_valid_ipv4(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

function is_valid_mask_octet(string $mask): bool {
    if (!is_valid_ipv4($mask)) return false;
    $long     = ip2long($mask);
    $inverted = ~$long & 0xFFFFFFFF;
    return ($inverted & ($inverted + 1)) === 0;
}

function calculate_subnet(string $ip, int $cidr): array {
    $ip_long      = ip2long($ip);
    $mask_long    = $cidr === 0 ? 0 : (~0 << (32 - $cidr));
    $network_long = $ip_long & $mask_long;
    $broadcast    = $network_long | (~$mask_long & 0xFFFFFFFF);
    $first        = $cidr >= 31 ? $network_long : $network_long + 1;
    $last         = $cidr >= 31 ? $broadcast    : $broadcast - 1;
    $usable       = $cidr >= 31 ? (1 << (32 - $cidr)) : max(0, (1 << (32 - $cidr)) - 2);

    return [
        'network_cidr'  => long2ip($network_long) . '/' . $cidr,
        'netmask_cidr'  => '/' . $cidr,
        'netmask_octet' => cidr_to_mask($cidr),
        'first_usable'  => long2ip($first),
        'last_usable'   => long2ip($last),
        'broadcast'     => long2ip($broadcast),
        'usable_hosts'  => $usable,
    ];
}

// ─── IPv6 ─────────────────────────────────────────────────────────────────────

function is_valid_ipv6(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
}

function ipv6_to_gmp(string $ip): \GMP {
    return gmp_init(bin2hex(inet_pton($ip)), 16);
}

function gmp_to_ipv6(\GMP $n): string {
    return inet_ntop(hex2bin(str_pad(gmp_strval($n, 16), 32, '0', STR_PAD_LEFT)));
}

function calculate_subnet6(string $ip, int $prefix): array {
    $ip_int    = ipv6_to_gmp($ip);
    $host_bits = 128 - $prefix;
    $host_mask = $host_bits > 0 ? gmp_sub(gmp_pow(2, $host_bits), 1) : gmp_init(0);
    $net_mask  = gmp_xor(gmp_sub(gmp_pow(2, 128), 1), $host_mask);
    $network   = gmp_and($ip_int, $net_mask);
    $last      = gmp_or($network, $host_mask);
    $total     = $host_bits === 0 ? '1' : '2^' . $host_bits;

    return [
        'network_cidr' => gmp_to_ipv6($network) . '/' . $prefix,
        'prefix'       => '/' . $prefix,
        'first_ip'     => gmp_to_ipv6($network),
        'last_ip'      => gmp_to_ipv6($last),
        'total'        => $total,
    ];
}

// ─── Request handling ─────────────────────────────────────────────────────────

$active_tab = ($_GET['tab'] ?? '') === 'ipv6' ? 'ipv6' : 'ipv4';

$result = $error = null;
$input_ip = $input_mask = '';

$result6 = $error6 = null;
$input_ipv6 = $input_prefix = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $active_tab = ($_POST['tab'] ?? 'ipv4') === 'ipv6' ? 'ipv6' : 'ipv4';

    if ($active_tab === 'ipv4') {
        $input_ip   = trim($_POST['ip']   ?? '');
        $input_mask = trim($_POST['mask'] ?? '');

        if (!is_valid_ipv4($input_ip)) {
            $error = 'Invalid IPv4 address.';
        } else {
            $mask_clean = ltrim($input_mask, '/');
            if (ctype_digit($mask_clean)) {
                $cidr = (int)$mask_clean;
                if ($cidr < 0 || $cidr > 32) {
                    $error = 'CIDR prefix must be between 0 and 32.';
                } else {
                    $result = calculate_subnet($input_ip, $cidr);
                }
            } elseif (is_valid_mask_octet($mask_clean)) {
                $result = calculate_subnet($input_ip, mask_to_cidr($mask_clean));
            } else {
                $error = 'Invalid netmask. Use CIDR (e.g. /24) or dotted-decimal (e.g. 255.255.255.0).';
            }
        }
    } else {
        $input_ipv6   = trim($_POST['ipv6']   ?? '');
        $input_prefix = trim($_POST['prefix'] ?? '');

        if (!extension_loaded('gmp')) {
            $error6 = 'IPv6 calculation requires the PHP GMP extension.';
        } elseif (!is_valid_ipv6($input_ipv6)) {
            $error6 = 'Invalid IPv6 address.';
        } else {
            $pfx = ltrim($input_prefix, '/');
            if (!ctype_digit($pfx) || (int)$pfx < 0 || (int)$pfx > 128) {
                $error6 = 'Prefix must be between 0 and 128.';
            } else {
                $result6 = calculate_subnet6($input_ipv6, (int)$pfx);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subnet Calculator</title>
    <link rel="icon" type="image/svg+xml" href="logo.svg">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        .card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 2rem;
            width: 100%;
            max-width: 560px;
        }

        .title-row {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin-bottom: 1.25rem;
        }

        .logo { width: 32px; height: 32px; flex-shrink: 0; }

        h1 { font-size: 1.5rem; font-weight: 700; color: #f8fafc; }

        .version {
            font-size: 0.7rem;
            font-weight: 600;
            color: #475569;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 4px;
            padding: 0.15rem 0.4rem;
            letter-spacing: 0.04em;
        }

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 1px solid #334155;
            margin-bottom: 1.5rem;
        }

        .tab-btn {
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            border-radius: 0;
            color: #64748b;
            cursor: pointer;
            flex: 0;
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: -1px;
            padding: 0.5rem 1.25rem;
            transition: color 0.15s, border-color 0.15s;
            width: auto;
        }

        .tab-btn:hover { background: none; color: #e2e8f0; }
        .tab-btn.active { border-bottom-color: #3b82f6; color: #38bdf8; }

        .panel { display: none; }
        .panel.active { display: block; }

        /* Form */
        .form-row {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .form-group { display: flex; flex-direction: column; flex: 1; }

        label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
            margin-bottom: 0.4rem;
        }

        input[type="text"] {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 6px;
            color: #f1f5f9;
            font-family: 'Courier New', monospace;
            font-size: 0.95rem;
            padding: 0.55rem 0.75rem;
            transition: border-color 0.15s;
            width: 100%;
        }

        input[type="text"]:focus { outline: none; border-color: #3b82f6; }
        input[type="text"]::placeholder { color: #475569; }

        .btn-row { display: flex; gap: 0.75rem; margin-top: 0.25rem; }

        button[type="submit"] {
            flex: 1;
            background: #3b82f6;
            border: none;
            border-radius: 6px;
            color: #fff;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 600;
            padding: 0.65rem 1rem;
            transition: background 0.15s;
        }

        button[type="submit"]:hover { background: #2563eb; }

        a.btn.reset {
            flex: 1;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 6px;
            color: #94a3b8;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            font-weight: 600;
            padding: 0.65rem 1rem;
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }

        a.btn.reset:hover { background: #334155; color: #e2e8f0; }

        /* Error */
        .error {
            background: #450a0a;
            border: 1px solid #7f1d1d;
            border-radius: 6px;
            color: #fca5a5;
            font-size: 0.875rem;
            margin-top: 1.25rem;
            padding: 0.65rem 0.85rem;
        }

        /* Results */
        .results {
            margin-top: 1.5rem;
            border: 1px solid #334155;
            border-radius: 8px;
            overflow: hidden;
        }

        .results-header {
            background: #0f172a;
            color: #64748b;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            padding: 0.6rem 1rem;
            text-transform: uppercase;
        }

        .result-row {
            align-items: center;
            background: #0f172a;
            border-top: 1px solid #1e293b;
            display: flex;
            justify-content: space-between;
            padding: 0.65rem 1rem;
        }

        .result-row:nth-child(odd) { background: #111827; }

        .result-label { color: #94a3b8; font-size: 0.8rem; }

        .result-value {
            color: #38bdf8;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .hosts-row .result-value { color: #4ade80; }

        footer {
            margin-top: 1.25rem;
            text-align: center;
        }

        footer a {
            color: #475569;
            font-size: 0.75rem;
            text-decoration: none;
            transition: color 0.15s;
        }

        footer a:hover { color: #94a3b8; }

        @media (max-width: 480px) {
            .form-row { flex-direction: column; }
        }
    </style>
</head>
<body>
<div class="card">
    <div class="title-row">
        <img src="logo.svg" alt="Subnet Calculator logo" class="logo">
        <h1>Subnet Calculator</h1>
        <span class="version">v0.3</span>
    </div>

    <div class="tabs">
        <button class="tab-btn<?= $active_tab === 'ipv4' ? ' active' : '' ?>" data-tab="ipv4">IPv4</button>
        <button class="tab-btn<?= $active_tab === 'ipv6' ? ' active' : '' ?>" data-tab="ipv6">IPv6</button>
    </div>

    <!-- IPv4 Panel -->
    <div id="panel-ipv4" class="panel<?= $active_tab === 'ipv4' ? ' active' : '' ?>">
        <form method="post" novalidate>
            <input type="hidden" name="tab" value="ipv4">
            <div class="form-row">
                <div class="form-group">
                    <label for="ip">IP Address</label>
                    <input type="text" id="ip" name="ip"
                           placeholder="192.168.1.0"
                           value="<?= htmlspecialchars($input_ip) ?>"
                           autocomplete="off" spellcheck="false">
                </div>
                <div class="form-group">
                    <label for="mask">Netmask</label>
                    <input type="text" id="mask" name="mask"
                           placeholder="/24 or 255.255.255.0"
                           value="<?= htmlspecialchars($input_mask) ?>"
                           autocomplete="off" spellcheck="false">
                </div>
            </div>
            <div class="btn-row">
                <button type="submit">Calculate</button>
                <a href="?" class="btn reset">Reset</a>
            </div>
        </form>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($result): ?>
            <div class="results">
                <div class="results-header">Results</div>
                <div class="result-row">
                    <span class="result-label">Subnet (CIDR)</span>
                    <span class="result-value"><?= htmlspecialchars($result['network_cidr']) ?></span>
                </div>
                <div class="result-row">
                    <span class="result-label">Netmask (CIDR)</span>
                    <span class="result-value"><?= htmlspecialchars($result['netmask_cidr']) ?></span>
                </div>
                <div class="result-row">
                    <span class="result-label">Netmask (Octet)</span>
                    <span class="result-value"><?= htmlspecialchars($result['netmask_octet']) ?></span>
                </div>
                <div class="result-row">
                    <span class="result-label">First Usable IP</span>
                    <span class="result-value"><?= htmlspecialchars($result['first_usable']) ?></span>
                </div>
                <div class="result-row">
                    <span class="result-label">Last Usable IP</span>
                    <span class="result-value"><?= htmlspecialchars($result['last_usable']) ?></span>
                </div>
                <div class="result-row">
                    <span class="result-label">Broadcast IP</span>
                    <span class="result-value"><?= htmlspecialchars($result['broadcast']) ?></span>
                </div>
                <div class="result-row hosts-row">
                    <span class="result-label">Usable IPs</span>
                    <span class="result-value"><?= number_format($result['usable_hosts']) ?></span>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- IPv6 Panel -->
    <div id="panel-ipv6" class="panel<?= $active_tab === 'ipv6' ? ' active' : '' ?>">
        <form method="post" novalidate>
            <input type="hidden" name="tab" value="ipv6">
            <div class="form-row">
                <div class="form-group">
                    <label for="ipv6">IPv6 Address</label>
                    <input type="text" id="ipv6" name="ipv6"
                           placeholder="2001:db8::1"
                           value="<?= htmlspecialchars($input_ipv6) ?>"
                           autocomplete="off" spellcheck="false">
                </div>
                <div class="form-group" style="max-width:120px">
                    <label for="prefix">Prefix</label>
                    <input type="text" id="prefix" name="prefix"
                           placeholder="/64"
                           value="<?= htmlspecialchars($input_prefix) ?>"
                           autocomplete="off" spellcheck="false">
                </div>
            </div>
            <div class="btn-row">
                <button type="submit">Calculate</button>
                <a href="?tab=ipv6" class="btn reset">Reset</a>
            </div>
        </form>

        <?php if ($error6): ?>
            <div class="error"><?= htmlspecialchars($error6) ?></div>
        <?php endif; ?>

        <?php if ($result6): ?>
            <div class="results">
                <div class="results-header">Results</div>
                <div class="result-row">
                    <span class="result-label">Network (CIDR)</span>
                    <span class="result-value"><?= htmlspecialchars($result6['network_cidr']) ?></span>
                </div>
                <div class="result-row">
                    <span class="result-label">Prefix Length</span>
                    <span class="result-value"><?= htmlspecialchars($result6['prefix']) ?></span>
                </div>
                <div class="result-row">
                    <span class="result-label">First IP</span>
                    <span class="result-value"><?= htmlspecialchars($result6['first_ip']) ?></span>
                </div>
                <div class="result-row">
                    <span class="result-label">Last IP</span>
                    <span class="result-value"><?= htmlspecialchars($result6['last_ip']) ?></span>
                </div>
                <div class="result-row hosts-row">
                    <span class="result-label">Total Addresses</span>
                    <span class="result-value"><?= htmlspecialchars($result6['total']) ?></span>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<footer>
    <a href="https://github.com/seanmousseau/Subnet-Calculator" target="_blank" rel="noopener">github.com/seanmousseau/Subnet-Calculator</a>
</footer>

<script>
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn, .panel').forEach(el => el.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('panel-' + btn.dataset.tab).classList.add('active');
    });
});
</script>
</body>
</html>
