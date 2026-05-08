<?php

declare(strict_types=1);

// ─── MAC → IPv6 derivation helpers (v3.3.0) ──────────────────────────────────
//
// Slimmed in v3.4.0 (carry-forward item #1): zone-ID parsing moved to
// functions-zone6.php and SLAAC privacy moved to functions-slaac6.php.
// This module retains the MAC-derived address surface only.
//
// Pure functions that derive metadata from a 48-bit MAC. Sibling of
// functions-supernet6.php / functions-range6.php — same style: typed,
// GMP-backed where 128-bit math is needed, throws InvalidArgumentException on
// validation failure so handlers can render the message verbatim.
//
// Per RFC 4291 §2.5.1 the EUI-64 is formed by inserting `ff:fe` between the
// third and fourth octets and then flipping the universal/local bit (bit 1 of
// the first octet). Link-local is `fe80::` + EUI-64; solicited-node is
// `ff02::1:ff` + low 24 bits of the unicast address. All outputs are
// canonicalised via inet_pton/inet_ntop so compressed and expanded inputs
// render identically.

/**
 * Strip separators, validate, and lowercase a MAC address string. Accepts
 * `xx:xx:xx:xx:xx:xx`, `xx-xx-xx-xx-xx-xx`, `xxxx.xxxx.xxxx`, and bare
 * `xxxxxxxxxxxx` forms (mixed case allowed). Returns the 12-hex-character
 * canonical representation (no separators, lowercase).
 *
 * @throws \InvalidArgumentException on any other shape.
 */
function mac_normalize_internal(string $mac): string
{
    $trimmed = trim($mac);
    // Validate the original shape against the four documented formats BEFORE
    // stripping separators. This rejects mixed-separator inputs like
    // `00:24-b9.7e:ab:cd` that would otherwise normalise to 12 hex chars.
    $valid = preg_match(
        '/^(?:[0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5}'
        . '|[0-9A-Fa-f]{2}(?:-[0-9A-Fa-f]{2}){5}'
        . '|[0-9A-Fa-f]{4}(?:\.[0-9A-Fa-f]{4}){2}'
        . '|[0-9A-Fa-f]{12})$/',
        $trimmed
    ) === 1;
    if (!$valid) {
        throw new \InvalidArgumentException('Invalid MAC address: ' . $mac);
    }
    return strtolower(str_replace([':', '-', '.'], '', $trimmed));
}

/**
 * Derive the 64-bit Modified EUI-64 interface identifier from a 48-bit MAC,
 * per RFC 4291 §2.5.1. Inserts `ff:fe` between octets 3 and 4 and flips the
 * universal/local bit (bit 1 of the first octet, mask 0x02). Output is four
 * lowercase colon-separated hextets, e.g. `0224:b9ff:fe7e:abcd`.
 *
 * @throws \InvalidArgumentException on a malformed MAC.
 */
function mac_to_eui64(string $mac): string
{
    $hex = mac_normalize_internal($mac);
    // Flip U/L bit on byte 0.
    $byte0 = hexdec(substr($hex, 0, 2)) ^ 0x02;
    $b0    = sprintf('%02x', $byte0);
    // hex octets: b0 b1 b2 b3 b4 b5 → b0 b1 b2 ff fe b3 b4 b5
    $hextet1 = $b0 . substr($hex, 2, 2);            // b0 b1
    $hextet2 = substr($hex, 4, 2) . 'ff';           // b2 ff
    $hextet3 = 'fe' . substr($hex, 6, 2);           // fe b3
    $hextet4 = substr($hex, 8, 4);                  // b4 b5
    return $hextet1 . ':' . $hextet2 . ':' . $hextet3 . ':' . $hextet4;
}

/**
 * Derive the IPv6 link-local address (fe80::/64) for a 48-bit MAC. Combines
 * `fe80::` with the Modified EUI-64 interface identifier and round-trips
 * through inet_pton/inet_ntop for canonical compressed form
 * (e.g. `fe80::224:b9ff:fe7e:abcd`).
 *
 * @throws \InvalidArgumentException on a malformed MAC.
 */
function mac_to_link_local(string $mac): string
{
    $eui64 = mac_to_eui64($mac);
    $bin   = inet_pton('fe80::' . $eui64);
    if ($bin === false || strlen($bin) !== 16) {
        // Should be unreachable — mac_to_eui64 only emits valid hextets.
        throw new \InvalidArgumentException('Failed to assemble link-local address from MAC: ' . $mac);
    }
    $out = inet_ntop($bin);
    if ($out === false) {
        throw new \InvalidArgumentException('Failed to canonicalise link-local address from MAC: ' . $mac);
    }
    return $out;
}

/**
 * Compute the solicited-node multicast address for a unicast IPv6 address,
 * per RFC 4291 §2.7.1: `ff02::1:ff` concatenated with the low 24 bits of the
 * unicast address. Round-trips the assembled address through
 * inet_pton/inet_ntop for canonical compressed form.
 *
 * @throws \InvalidArgumentException when the input is not a valid IPv6 address.
 */
function unicast_to_solicited_node(string $ipv6): string
{
    $bin = @inet_pton(trim($ipv6));
    if ($bin === false || strlen($bin) !== 16) {
        throw new \InvalidArgumentException('Invalid IPv6 address: ' . $ipv6);
    }
    // Low 24 bits = last 3 bytes.
    $low24 = bin2hex(substr($bin, 13, 3));   // 6 hex chars
    $assembled = 'ff02::1:ff' . substr($low24, 0, 2) . ':' . substr($low24, 2, 4);
    $sn_bin = inet_pton($assembled);
    if ($sn_bin === false || strlen($sn_bin) !== 16) {
        // Should be unreachable.
        throw new \InvalidArgumentException('Failed to assemble solicited-node address for: ' . $ipv6);
    }
    $out = inet_ntop($sn_bin);
    if ($out === false) {
        throw new \InvalidArgumentException('Failed to canonicalise solicited-node address for: ' . $ipv6);
    }
    return $out;
}

/**
 * Orchestrator: from a single 48-bit MAC, derive the canonical MAC, EUI-64
 * interface ID, link-local IPv6 address, solicited-node multicast IPv6
 * address, the U/L flip outcome, and an optional warning when the input
 * appears to be a multicast MAC (LSB of first octet set).
 *
 * `ul_bit_flipped` is True if the U/L bit was originally 0 in the input MAC
 * and was therefore flipped to 1 to form the EUI-64 modified-IID. False if
 * the U/L bit was already 1 in the input. The flip operation always runs
 * unconditionally; this field reports whether the flip changed the value.
 *
 * @return array{
 *     mac_canonical: string,
 *     eui64: string,
 *     ul_bit_flipped: bool,
 *     link_local: string,
 *     solicited_node: string,
 *     warning: string|null
 * }
 *
 * @throws \InvalidArgumentException on a malformed MAC.
 */
function derive_from_mac(string $mac): array
{
    $hex = mac_normalize_internal($mac);
    $canonical = substr($hex, 0, 2) . ':' . substr($hex, 2, 2) . ':'
        . substr($hex, 4, 2) . ':' . substr($hex, 6, 2) . ':'
        . substr($hex, 8, 2) . ':' . substr($hex, 10, 2);

    $byte0 = hexdec(substr($hex, 0, 2));
    // ul_bit_flipped: True if the U/L bit was originally 0 in the input MAC
    // and was therefore flipped to 1 to form the EUI-64 modified-IID. False
    // if the U/L bit was already 1 in the input. The flip operation always
    // runs unconditionally; this field reports whether the flip changed the
    // value.
    $ul_bit_flipped = (($byte0 & 0x02) === 0);

    $eui64           = mac_to_eui64($canonical);
    $link_local      = mac_to_link_local($canonical);
    $solicited_node  = unicast_to_solicited_node($link_local);

    $warning = null;
    if (($byte0 & 0x01) === 0x01) {
        $warning = 'Multicast bit set on input MAC; derived addresses are still computed '
            . 'but the source MAC is not a valid unicast hardware address.';
    }

    return [
        'mac_canonical'  => $canonical,
        'eui64'          => $eui64,
        'ul_bit_flipped' => $ul_bit_flipped,
        'link_local'     => $link_local,
        'solicited_node' => $solicited_node,
        'warning'        => $warning,
    ];
}
