<?php

declare(strict_types=1);

// ─── Address type detection ───────────────────────────────────────────────────

function get_ipv4_type(string $ip): string
{
    $n = ip2long($ip) & 0xFFFFFFFF;
    if ($n === 0) {
        return 'Unspecified';
    }
    if ($n === 0xFFFFFFFF) {
        return 'Broadcast';
    }
    if (($n & 0xFF000000) === 0x7F000000) {
        return 'Loopback';
    }
    if (($n & 0xFF000000) === 0x0A000000) {
        return 'Private';
    }
    if (($n & 0xFFF00000) === 0xAC100000) {
        return 'Private';
    }
    if (($n & 0xFFFF0000) === 0xC0A80000) {
        return 'Private';
    }
    if (($n & 0xFFFF0000) === 0xA9FE0000) {
        return 'Link-local';
    }
    if (($n & 0xF0000000) === 0xE0000000) {
        return 'Multicast';
    }
    if (($n & 0xFFFFFF00) === 0xC0000200) {
        return 'Documentation';
    }
    if (($n & 0xFFFFFF00) === 0xC6336400) {
        return 'Documentation';
    }
    if (($n & 0xFFFFFF00) === 0xCB007100) {
        return 'Documentation';
    }
    if (($n & 0xF0000000) === 0xF0000000) {
        return 'Reserved';
    }
    if (($n & 0xFF000000) === 0x00000000) {
        return 'This Network';
    }
    if (($n & 0xFFC00000) === 0x64400000) {
        return 'CGNAT';
    }
    if (($n & 0xFFFE0000) === 0xC6120000) {
        return 'Benchmarking';
    }
    if (($n & 0xFFFFFF00) === 0xC0000000) {
        return 'IETF Reserved';
    }
    return 'Public';
}

function get_ipv6_type(string $ip): string
{
    $bin = inet_pton($ip);
    if ($bin === false) {
        return 'Unknown';
    }
    $b = array_values(unpack('C*', $bin));
    if ($bin === str_repeat("\x00", 15) . "\x01") {
        return 'Loopback';
    }
    if ($bin === str_repeat("\x00", 16)) {
        return 'Unspecified';
    }
    if (substr($bin, 0, 10) === str_repeat("\x00", 10) && substr($bin, 10, 2) === "\xff\xff") {
        return 'IPv4-mapped';
    }
    if ($b[0] === 0xFF) {
        return 'Multicast';
    }
    if ($b[0] === 0xFE && ($b[1] & 0xC0) === 0x80) {
        return 'Link-local';
    }
    if (($b[0] & 0xFE) === 0xFC) {
        return 'Unique Local';
    }
    if ($b[0] === 0x20 && $b[1] === 0x01 && $b[2] === 0x0D && $b[3] === 0xB8) {
        return 'Documentation';
    }
    if ($b[0] === 0x20 && $b[1] === 0x01 && $b[2] === 0x00 && $b[3] === 0x00) {
        return 'Teredo';
    }
    if ($b[0] === 0x20 && $b[1] === 0x02) {
        return '6to4';
    }
    if ($b[0] === 0x00 && $b[1] === 0x64 && $b[2] === 0xFF && $b[3] === 0x9B) {
        if ($b[4] === 0x00 && $b[5] === 0x01) {
            return 'NAT64 (local)';
        }
                                                               return 'NAT64';
    }
    if (($b[0] & 0xE0) === 0x20) {
        return 'Global Unicast';
    }
    return 'Unknown';
}

function type_badge_class(string $type): string
{
    $map = [
        'Private'       => 'private',
        'Public'        => 'public',
        'Loopback'      => 'loopback',
        'Link-local'    => 'link-local',
        'Multicast'     => 'multicast',
        'Documentation' => 'doc',
        'Global Unicast' => 'public',
        'Unique Local'  => 'ula',
        'CGNAT'         => 'other',
        'Reserved'      => 'other',
        'Broadcast'     => 'loopback',
        'Unspecified'   => 'loopback',
        'This Network'  => 'loopback',
        'Benchmarking'  => 'other',
        'IETF Reserved' => 'other',
        'IPv4-mapped'   => 'doc',
        'Teredo'        => 'doc',
        '6to4'          => 'doc',
        'NAT64'         => 'doc',
        'NAT64 (local)' => 'doc',
    ];
    return $map[$type] ?? 'other';
}

// ─── Locale-aware number formatting ──────────────────────────────────────────

/**
 * Format an integer with locale-aware thousands separators.
 *
 * Uses PHP's intl NumberFormatter when the intl extension is loaded and
 * $locale (a global set by config.php) is not the default 'en'. Falls back
 * to number_format() which uses comma separators.
 *
 * @param int|float $n
 * @return string
 */
function format_number(int|float $n): string
{
    $raw = $GLOBALS['locale'] ?? 'en';
    $loc = is_string($raw) ? $raw : 'en';
    if ($loc !== 'en' && \extension_loaded('intl')) {
        $fmt = \numfmt_create($loc, \NumberFormatter::DECIMAL);
        if ($fmt !== null) {
            $out = \numfmt_format($fmt, $n);
            if ($out !== false) {
                return $out;
            }
        }
    }
    $decimals = (is_float($n) && floor($n) !== $n) ? 2 : 0;
    return \number_format($n, $decimals);
}

// ─── Help bubble ─────────────────────────────────────────────────────────────

/**
 * Render an inline help-bubble icon with a tooltip.
 *
 * Returns pre-escaped HTML — safe to echo directly (do not re-escape).
 */
function help_bubble(string $id, string $text): string
{
    // Normalise $id to a safe HTML-ID token (alphanumerics, hyphens, underscores only).
    $safe_id = preg_replace('/[^A-Za-z0-9\-_]/', '-', $id);
    if ($safe_id === '' || $safe_id === null) {
        $safe_id = 'auto';
    }
    $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<span class="help-bubble">'
         . '<span class="help-bubble-icon" tabindex="0" aria-describedby="hb-' . $safe_id . '">?</span>'
         . '<span class="help-bubble-text" role="tooltip" id="hb-' . $safe_id . '">' . $safe . '</span>'
         . '</span>';
}
