<?php

function cidr_to_mask(int $cidr): string {
    $mask = $cidr === 0 ? 0 : (~0 << (32 - $cidr));
    return long2ip($mask);
}

function mask_to_cidr(string $mask): int {
    return strlen(str_replace('0', '', decbin(ip2long($mask))));
}

function is_valid_ip(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

function is_valid_mask_octet(string $mask): bool {
    if (!is_valid_ip($mask)) return false;
    $long = ip2long($mask);
    // A valid subnet mask has all 1s followed by all 0s in binary
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
    $total_hosts  = 1 << (32 - $cidr);
    $usable_hosts = $cidr >= 31 ? $total_hosts  : max(0, $total_hosts - 2);

    return [
        'network_cidr' => long2ip($network_long) . '/' . $cidr,
        'first_usable' => long2ip($first),
        'last_usable'  => long2ip($last),
        'broadcast'    => long2ip($broadcast),
        'netmask_cidr' => '/' . $cidr,
        'netmask_octet'=> cidr_to_mask($cidr),
        'total_hosts'  => $total_hosts,
        'usable_hosts' => $usable_hosts,
    ];
}

$result = null;
$error  = null;
$input_ip   = '';
$input_mask = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_ip   = trim($_POST['ip']   ?? '');
    $input_mask = trim($_POST['mask'] ?? '');

    if (!is_valid_ip($input_ip)) {
        $error = 'Invalid IPv4 address.';
    } else {
        // Detect CIDR (e.g. "24" or "/24") or octet notation
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
            $error = 'Invalid netmask. Use CIDR (e.g. 24 or /24) or dotted-decimal (e.g. 255.255.255.0).';
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
            margin-bottom: 0.25rem;
        }

        .logo {
            width: 32px;
            height: 32px;
            flex-shrink: 0;
        }

        h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #f8fafc;
        }

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

        .subtitle {
            font-size: 0.875rem;
            color: #64748b;
            margin-bottom: 1.75rem;
        }

        .form-row {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            flex: 1;
        }

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
            font-size: 0.95rem;
            font-family: 'Courier New', monospace;
            padding: 0.55rem 0.75rem;
            transition: border-color 0.15s;
            width: 100%;
        }

        input[type="text"]:focus {
            outline: none;
            border-color: #3b82f6;
        }

        input[type="text"]::placeholder { color: #475569; }

        button {
            width: 100%;
            background: #3b82f6;
            border: none;
            border-radius: 6px;
            color: #fff;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 600;
            padding: 0.65rem 1rem;
            transition: background 0.15s;
            margin-top: 0.25rem;
        }

        button:hover { background: #2563eb; }

        .error {
            background: #450a0a;
            border: 1px solid #7f1d1d;
            border-radius: 6px;
            color: #fca5a5;
            font-size: 0.875rem;
            margin-top: 1.25rem;
            padding: 0.65rem 0.85rem;
        }

        .results {
            margin-top: 1.5rem;
            border: 1px solid #334155;
            border-radius: 8px;
            overflow: hidden;
        }

        .results-header {
            background: #0f172a;
            padding: 0.6rem 1rem;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #64748b;
        }

        .result-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.65rem 1rem;
            border-top: 1px solid #1e293b;
            background: #0f172a;
        }

        .result-row:nth-child(odd) { background: #111827; }

        .result-label {
            font-size: 0.8rem;
            color: #94a3b8;
        }

        .result-value {
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            color: #38bdf8;
            font-weight: 600;
        }

        .divider {
            border: none;
            border-top: 1px solid #334155;
            margin: 0.25rem 0;
        }

        .hosts-row .result-value { color: #4ade80; }

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
        <span class="version">v0.1</span>
    </div>
    <p class="subtitle">Enter an IP and netmask in CIDR (/24) or dotted-decimal (255.255.255.0) notation.</p>

    <form method="post" novalidate>
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
        <button type="submit">Calculate</button>
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
                <span class="result-label">Total Hosts</span>
                <span class="result-value"><?= number_format($result['total_hosts']) ?></span>
            </div>
            <div class="result-row hosts-row">
                <span class="result-label">Usable IPs</span>
                <span class="result-value"><?= number_format($result['usable_hosts']) ?></span>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
