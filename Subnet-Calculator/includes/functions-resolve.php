<?php

declare(strict_types=1);

// ─── Input resolvers — shared by web request.php and API handlers ─────────────

/**
 * Parse and validate an IPv4 address + optional mask/prefix.
 *
 * @return array{result: array<string,mixed>|null, error: string|null, ip: string, mask: string}
 */
function resolve_ipv4_input(string $ip, string $mask): array
{
    // CIDR auto-detection: "192.168.1.0/24" typed into IP field (#27)
    if (strpos($ip, '/') !== false && $mask === '') {
        [$ip, $mask] = array_pad(explode('/', $ip, 2), 2, '');
        $ip   = trim($ip);
        $mask = trim($mask);
    }
    $result = $error = null;
    if (!is_valid_ipv4($ip)) {
        $error = 'Invalid IPv4 address.';
    } else {
        $mask_clean = ltrim($mask, '/');
        if (ctype_digit($mask_clean)) {
            $cidr = (int)$mask_clean;
            if ($cidr < 0 || $cidr > 32) {
                $error = 'CIDR prefix must be between 0 and 32.';
            } else {
                $result = calculate_subnet($ip, $cidr);
            }
        } elseif (is_valid_mask_octet($mask_clean)) {
            $result = calculate_subnet($ip, mask_to_cidr($mask_clean));
        } else {
            $error = 'Invalid netmask. Use CIDR (e.g. /24) or dotted-decimal (e.g. 255.255.255.0).';
        }
    }
    return ['result' => $result, 'error' => $error, 'ip' => $ip, 'mask' => $mask];
}

/**
 * Parse and validate an IPv6 address + optional prefix.
 *
 * @return array{result6: array<string,mixed>|null, error6: string|null, ip: string, prefix: string}
 */
function resolve_ipv6_input(string $ip, string $prefix): array
{
    // CIDR auto-detection: "2001:db8::/32" typed into IPv6 field (#27)
    if (strpos($ip, '/') !== false && $prefix === '') {
        [$ip, $prefix] = array_pad(explode('/', $ip, 2), 2, '');
        $ip     = trim($ip);
        $prefix = trim($prefix);
    }
    $result6 = $error6 = null;
    if (!extension_loaded('gmp')) {
        $error6 = 'IPv6 calculation requires the PHP GMP extension.';
    } elseif (!is_valid_ipv6($ip)) {
        $error6 = 'Invalid IPv6 address.';
    } else {
        $pfx = ltrim($prefix, '/');
        if (!ctype_digit($pfx) || (int)$pfx > 128) {
            $error6 = 'Prefix must be between 0 and 128.';
        } else {
            try {
                $result6 = calculate_subnet6($ip, (int)$pfx);
            } catch (\Exception $e) {
                error_log('sc IPv6 error: ' . $e->getMessage());
                $error6 = 'An error occurred during calculation. Please check your input.';
            }
        }
    }
    return ['result6' => $result6, 'error6' => $error6, 'ip' => $ip, 'prefix' => $prefix];
}
