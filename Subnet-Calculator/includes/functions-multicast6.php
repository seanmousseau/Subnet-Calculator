<?php

declare(strict_types=1);

// IPv6 multicast scope decoder (v3.6.0 T2, #396).
//
// Front door for the v3.6.0 IPv6 multicast tools. Decodes any FF00::/8
// address into:
//   - scope (1..15) per RFC 4291 §2.7 + RFC 7346
//   - 4-bit flags field (0RPT) per RFC 4291 §2.7 / RFC 3306 / RFC 3956
//   - scheme hint (well-known | transient | ssm | embedded-rp | reserved)
//   - 112-bit group ID (hex)
//   - well-known group lookup (small in-tree registry)
//   - deep-link route to the per-scheme tool when applicable
//
// Per-scheme tools (SSM, embedded-RP) deep-link off the 'detail_route'
// returned here. Their drawers ship in v3.6.0 T3 and T4 respectively.

/**
 * Built-in registry of well-known IPv6 multicast group addresses.
 *
 * Keyed by the canonical compressed lowercase address per inet_ntop().
 * Solicited-Node addresses (ff02::1:ff00:0/104) are matched separately
 * as a prefix in decode_multicast(); they are NOT in this map.
 *
 * @return array<string, array{name: string, rfc: string}>
 */
function multicast_well_known_registry(): array
{
    return [
        'ff02::1'   => ['name' => 'All Nodes Address',                    'rfc' => 'RFC 4291'],
        'ff02::2'   => ['name' => 'All Routers Address',                  'rfc' => 'RFC 4291'],
        'ff02::5'   => ['name' => 'OSPFIGP',                              'rfc' => 'RFC 2328'],
        'ff02::6'   => ['name' => 'OSPFIGP Designated Routers',           'rfc' => 'RFC 2328'],
        'ff02::9'   => ['name' => 'RIP Routers',                          'rfc' => 'RFC 2080'],
        'ff02::a'   => ['name' => 'EIGRP Routers',                        'rfc' => '(Cisco)'],
        'ff02::d'   => ['name' => 'All PIM Routers',                      'rfc' => 'RFC 7761'],
        'ff02::16'  => ['name' => 'MLDv2-capable Routers',                'rfc' => 'RFC 3810'],
        'ff05::101' => ['name' => 'All NTP Servers',                      'rfc' => 'RFC 5905'],
    ];
}

/**
 * Decode an IPv6 multicast address per RFC 4291 §2.7.
 *
 * @return array{
 *     address: string,
 *     scope: int,
 *     scope_name: string,
 *     flags: int,
 *     transient: bool,
 *     prefix_based: bool,
 *     embedded_rp: bool,
 *     group_id: string,
 *     scheme: string,
 *     detail_route: string|null,
 *     well_known: array{name: string, rfc: string}|null
 * }
 *
 * @throws InvalidArgumentException if not a valid IPv6 multicast address (FF00::/8).
 */
function decode_multicast(string $ipv6): array
{
    $bin = @inet_pton($ipv6);
    if ($bin === false || strlen($bin) !== 16) {
        throw new InvalidArgumentException('Invalid IPv6 address.');
    }
    if (ord($bin[0]) !== 0xFF) {
        throw new InvalidArgumentException('Address is not in the IPv6 multicast range FF00::/8.');
    }

    // RFC 4291 §2.7: address layout is 11111111|flags(4)|scope(4)|group(112).
    // Byte 0 is the constant 0xFF prefix; byte 1 carries the 4-bit flags
    // (high nibble) and 4-bit scope (low nibble).
    $flags = (ord($bin[1]) & 0xF0) >> 4;
    $scope = ord($bin[1]) & 0x0F;

    $scope_names = [
        1   => 'interface-local',
        2   => 'link-local',
        4   => 'admin-local',
        5   => 'site-local',
        8   => 'organization-local',
        0xE => 'global',
    ];
    $scope_name = $scope_names[$scope] ?? 'reserved';

    $transient    = ($flags & 0x1) !== 0;
    $prefix_based = ($flags & 0x2) !== 0;
    $embedded_rp  = ($flags & 0x4) !== 0;

    $group_id = bin2hex(substr($bin, 2, 14));

    // Canonical compressed lowercase form for both registry lookup and output.
    $canon_raw = @inet_ntop($bin);
    $canonical = is_string($canon_raw) ? strtolower($canon_raw) : strtolower($ipv6);

    // Scheme decision in RFC priority order: R (embedded-RP) wins over P (prefix-based / SSM)
    // wins over T (transient) wins over flags=0 (well-known).
    $detail_route = null;
    $well_known   = null;

    if ($embedded_rp) {
        $scheme       = 'embedded-rp';
        $detail_route = '/ipv6/embedded-rp';
    } elseif ($prefix_based) {
        $scheme       = 'ssm';
        $detail_route = '/ipv6/ssm';
    } elseif ($transient) {
        $scheme = 'transient';
    } elseif ($flags === 0) {
        $scheme = 'well-known';
        // Solicited-Node prefix match: ff02::1:ff00:0/104 (RFC 4291 §2.7.1).
        // Bytes 0..12: FF 02 00 00 00 00 00 00 00 00 00 00 00 — but byte 11
        // is 01 and byte 12 is FF. Lay out the 13-byte prefix explicitly.
        $sol_prefix = "\xFF\x02\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01\xFF";
        if (substr($bin, 0, 13) === $sol_prefix) {
            $well_known = [
                'name' => 'Solicited-Node Address (RFC 4291)',
                'rfc'  => 'RFC 4291',
            ];
        } else {
            $registry = multicast_well_known_registry();
            if (isset($registry[$canonical])) {
                $well_known = $registry[$canonical];
            }
        }
    } else {
        $scheme = 'reserved';
    }

    return [
        'address'      => $canonical,
        'scope'        => $scope,
        'scope_name'   => $scope_name,
        'flags'        => $flags,
        'transient'    => $transient,
        'prefix_based' => $prefix_based,
        'embedded_rp'  => $embedded_rp,
        'group_id'     => $group_id,
        'scheme'       => $scheme,
        'detail_route' => $detail_route,
        'well_known'   => $well_known,
    ];
}
