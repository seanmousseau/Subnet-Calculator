<?php

declare(strict_types=1);

// ─── IPv6 derivation helpers (v3.3.0) ────────────────────────────────────────
//
// Pure functions that derive metadata from a raw IPv6 address string. Sibling
// of functions-supernet6.php / functions-range6.php — same style: typed,
// GMP-backed where 128-bit math is needed, throws InvalidArgumentException on
// validation failure so handlers can render the message verbatim.
//
// `parse_zone_id()` parses an `address[%zone]` string per RFC 4007 section 11.
// Output address is always normalised via inet_pton/inet_ntop so compressed
// and expanded forms canonicalise identically. Zone identifiers are accepted
// for any IPv6 address but only meaningful on link-local (fe80::/10); a
// warning string is returned when a zone is supplied on a non-link-local
// address.

/**
 * Parse an IPv6 `address[%zone]` string into address + zone components,
 * detect whether the address is link-local (fe80::/10), and surface a
 * warning when a zone identifier is attached to a non-link-local address.
 *
 * @return array{address: string, zone_id: string|null, is_link_local: bool, warning: string|null}
 *
 * @throws \InvalidArgumentException on empty input, invalid IPv6 address, or
 *                                   malformed zone identifier.
 */
function parse_zone_id(string $input): array
{
    $trimmed = trim($input);
    if ($trimmed === '') {
        throw new \InvalidArgumentException('Enter an address (with optional %zone).');
    }

    $pct = strpos($trimmed, '%');
    if ($pct === false) {
        $addr_part = $trimmed;
        $zone_part = null;
    } else {
        $addr_part = substr($trimmed, 0, $pct);
        $zone_part = substr($trimmed, $pct + 1);
        if ($zone_part === '' || preg_match('/^[A-Za-z0-9_-]{1,32}$/', $zone_part) !== 1) {
            throw new \InvalidArgumentException('Zone identifier must be 1–32 alphanumeric characters.');
        }
    }

    $bin = @inet_pton($addr_part);
    if ($bin === false || strlen($bin) !== 16) {
        throw new \InvalidArgumentException('Invalid IPv6 address: ' . $addr_part);
    }

    $canonical = inet_ntop($bin);
    if ($canonical === false) {
        throw new \InvalidArgumentException('Invalid IPv6 address: ' . $addr_part);
    }

    // Link-local detection: top 10 bits == fe80 (1111 1110 10xx xxxx).
    // Mask the GMP value with /10 and compare to fe80::.
    $addr_gmp = ipv6_to_gmp($canonical);
    $mask10   = supernet6_prefix_mask(10);
    $ll_net   = ipv6_to_gmp('fe80::');
    $is_link_local = (gmp_cmp(gmp_and($addr_gmp, $mask10), $ll_net) === 0);

    $warning = null;
    if ($zone_part !== null && !$is_link_local) {
        $warning = 'Zone identifiers are only meaningful on link-local (fe80::/10) addresses; '
            . 'the zone will be ignored by most operating systems.';
    }

    return [
        'address'       => $canonical,
        'zone_id'       => $zone_part,
        'is_link_local' => $is_link_local,
        'warning'       => $warning,
    ];
}

// ─── MAC → IPv6 derivation helpers (v3.3.0 Task 4) ───────────────────────────
//
// Derive EUI-64 interface IDs, link-local addresses, and solicited-node
// multicast addresses from a 48-bit MAC. Per RFC 4291 §2.5.1 the EUI-64 is
// formed by inserting `ff:fe` between the third and fourth octets and then
// flipping the universal/local bit (bit 1 of the first octet). Link-local is
// `fe80::` + EUI-64; solicited-node is `ff02::1:ff` + low 24 bits of the
// unicast address. All outputs are canonicalised via inet_pton/inet_ntop so
// compressed and expanded inputs render identically.

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
    $stripped = strtolower(str_replace([':', '-', '.'], '', trim($mac)));
    if (preg_match('/^[0-9a-f]{12}$/', $stripped) !== 1) {
        throw new \InvalidArgumentException('Invalid MAC address: ' . $mac);
    }
    return $stripped;
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
    // ul_bit_flipped reports whether the operation toggled the bit from
    // 0 → 1 (the standard case). When the input already has U/L = 1 the
    // flip goes 1 → 0, so we report false.
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
