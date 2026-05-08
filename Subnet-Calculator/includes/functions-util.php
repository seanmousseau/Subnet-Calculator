<?php

declare(strict_types=1);

/**
 * Generic UI/template helpers.
 *
 * IPv4 address classification, address-type badge class mapping,
 * locale-aware number formatting, and the help-bubble / copy-button
 * HTML renderers. IPv6 classification lives in functions-type6.php
 * as of v3.4.0 (architecture item C); the badge-class mapper below
 * still covers both IPv4 and IPv6 type strings (UI concern).
 */

// ─── Address type detection (IPv4) ───────────────────────────────────────────

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

// IPv6 classification (get_ipv6_type) lives in functions-type6.php as of v3.4.0.

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
         . '<span class="help-bubble-icon" tabindex="0" role="button"'
         . ' aria-label="Help" aria-describedby="hb-' . $safe_id . '">?</span>'
         . '<span class="help-bubble-text" role="tooltip" id="hb-' . $safe_id . '">' . $safe . '</span>'
         . '</span>';
}

// ─── Copy button ─────────────────────────────────────────────────────────────

/**
 * Render a single "copy to clipboard" SVG icon button.
 *
 * Produces the canonical `<button class="subnet-copy" data-copy="…"
 * aria-label="…">` markup used throughout templates/layout.php (in
 * split-item lists, derive/SLAAC result rows, wildcard results, etc.).
 * The JS handler in app.js binds via `.subnet-copy` and reads the
 * `data-copy` attribute, so existing JS continues to work unchanged.
 *
 * Returns pre-escaped HTML — safe to echo directly (do not re-escape).
 *
 * @param string $value The text to be copied to the clipboard.
 * @param string $label The aria-label for the button (e.g. "Copy 10.0.0.1").
 */
function copy_button(string $value, string $label): string
{
    $safe_value = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safe_label = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<button type="button" class="subnet-copy"'
         . ' data-copy="' . $safe_value . '"'
         . ' aria-label="' . $safe_label . '">'
         . '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
         . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . '<rect x="9" y="9" width="13" height="13" rx="2"/>'
         . '<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>'
         . '</svg>'
         . '</button>';
}
