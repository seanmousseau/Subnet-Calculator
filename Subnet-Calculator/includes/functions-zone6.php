<?php

declare(strict_types=1);

// ─── IPv6 zone-ID parser (RFC 4007 / RFC 6874) ───────────────────────────────
//
// Extracted from functions-derive6.php in v3.4.0 (carry-forward item #1).
// Public function names unchanged.
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
            throw new \InvalidArgumentException(
                'Zone identifier must be 1–32 characters using letters, digits, "_" or "-".'
            );
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
