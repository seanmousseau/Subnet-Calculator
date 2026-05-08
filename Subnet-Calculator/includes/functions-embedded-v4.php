<?php

declare(strict_types=1);

// IPv6 embedded-IPv4 detector (v3.5.0).
//
// Identifies any of the IPv6→IPv4 transition embedding schemes the project
// supports as front-door entry points to per-scheme tools:
//
//   - IPv4-mapped         ::ffff:0:0/96    (RFC 4291 §2.5.5.2; widely deployed)
//   - IPv4-compatible     ::/96            (RFC 4291 §2.5.5.1; DEPRECATED)
//   - 6to4                2002::/16        (RFC 3056)
//   - Teredo              2001::/32        (RFC 4380)
//   - NAT64 well-known    64:ff9b::/96     (RFC 6052 / RFC 6146)
//   - ISATAP              IID `*0000:5efe:V4` or `*0200:5efe:V4` (RFC 5214)
//
// Detection priority follows the most-specific-prefix-first rule: any address
// matching ::ffff:0:0/96 is reported as `mapped`, not `compatible`; the
// compatible branch is reached only by addresses inside ::/96 that fall
// outside the mapped block. Pure ::1 and :: are explicitly excluded from
// `compatible`, per RFC 4291 §2.5.5.1.
//
// `detail_route` is filled in per-scheme as the v3.5.0 transition tools
// land. As of T3 (#392), 6to4 deep-links to `/ipv6/6to4`; the other five
// schemes (mapped, compatible, teredo, nat64-wkp, isatap) still return
// null until their drawers ship in T4–T7. The contract is pinned by
// EmbeddedV4Test::test_detail_routes_per_scheme.

const EMBEDDEDV4_MAPPED_PREFIX_BIN     = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";
const EMBEDDEDV4_NAT64_WKP_PREFIX_BIN  = "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00";
const EMBEDDEDV4_6TO4_PREFIX_BIN       = "\x20\x02";
const EMBEDDEDV4_TEREDO_PREFIX_BIN     = "\x20\x01\x00\x00";

/**
 * Return true if $address parses as a valid IPv6 literal whose top
 * $prefix_length bits match the $prefix network. GMP throughout for
 * arbitrary prefix lengths.
 *
 * Returns false (no throw) on parse failure or prefix length out of
 * the 0..128 range — the caller should validate inputs first.
 */
function ipv6_in_prefix(string $address, string $prefix, int $prefix_length): bool
{
    if ($prefix_length < 0 || $prefix_length > 128) {
        return false;
    }
    $abin = @inet_pton($address);
    $pbin = @inet_pton($prefix);
    if ($abin === false || $pbin === false || strlen($abin) !== 16 || strlen($pbin) !== 16) {
        return false;
    }
    if ($prefix_length === 0) {
        return true;
    }
    $a = gmp_import($abin);
    $p = gmp_import($pbin);
    $shift = 128 - $prefix_length;
    // Build mask = ((1 << 128) - 1) ^ ((1 << shift) - 1)
    $all  = gmp_sub(gmp_pow(2, 128), 1);
    $low  = $shift === 0 ? gmp_init(0) : gmp_sub(gmp_pow(2, $shift), 1);
    $mask = gmp_xor($all, $low);
    return gmp_cmp(gmp_and($a, $mask), gmp_and($p, $mask)) === 0;
}

/**
 * Detect any embedded-IPv4 scheme in the given IPv6 address.
 *
 * @return array{
 *     scheme: 'mapped'|'compatible'|'6to4'|'teredo'|'nat64-wkp'|'isatap'|null,
 *     ipv4: ?string,
 *     deprecated: bool,
 *     detail_route: ?string,
 *     extra: array<string, mixed>
 * }
 *
 * @throws InvalidArgumentException if $ipv6 is not a parseable IPv6 literal.
 */
function detect_embedded_v4(string $ipv6): array
{
    $bin = @inet_pton($ipv6);
    if ($bin === false || strlen($bin) !== 16) {
        throw new InvalidArgumentException('Invalid IPv6 address');
    }

    $none = [
        'scheme'       => null,
        'ipv4'         => null,
        'deprecated'   => false,
        'detail_route' => null,
        'extra'        => [],
    ];

    // 1. IPv4-mapped: ::ffff:0:0/96 — most-specific check first.
    if (substr($bin, 0, 12) === EMBEDDEDV4_MAPPED_PREFIX_BIN) {
        $v4 = @inet_ntop(substr($bin, 12, 4));
        return [
            'scheme'       => 'mapped',
            'ipv4'         => $v4 === false ? null : $v4,
            'deprecated'   => false,
            'detail_route' => null,
            'extra'        => [],
        ];
    }

    // 2. NAT64 well-known prefix: 64:ff9b::/96.
    // TODO(nat64-nsp): reintroduce 'nat64-nsp' scheme when operator NSP
    // configuration lands (T3–T7); add the matching enum value back to
    // openapi.yaml and the layout.php scheme-label map atomically.
    if (substr($bin, 0, 12) === EMBEDDEDV4_NAT64_WKP_PREFIX_BIN) {
        $v4 = @inet_ntop(substr($bin, 12, 4));
        return [
            'scheme'       => 'nat64-wkp',
            'ipv4'         => $v4 === false ? null : $v4,
            'deprecated'   => false,
            'detail_route' => null,
            'extra'        => [],
        ];
    }

    // 3. 6to4: 2002::/16, embedded V4 in bytes 2–5.
    if (substr($bin, 0, 2) === EMBEDDEDV4_6TO4_PREFIX_BIN) {
        $v4 = @inet_ntop(substr($bin, 2, 4));
        return [
            'scheme'       => '6to4',
            'ipv4'         => $v4 === false ? null : $v4,
            'deprecated'   => false,
            // v3.5.0 T3 (#392): per-scheme drawer for 6to4 ships in this PR.
            // Mapped/teredo/nat64-wkp/isatap/compatible drawers land later in
            // v3.5.0 — their detail_route stays null until then. Contract
            // pinned by EmbeddedV4Test::test_detail_routes_per_scheme.
            'detail_route' => '/ipv6/6to4',
            'extra'        => [
                'sla_id' => bin2hex(substr($bin, 6, 2)),
            ],
        ];
    }

    // 4. Teredo: 2001:0::/32, RFC 4380. Server IPv4 in bytes 4–7;
    //    obfuscated client IPv4 (XOR 0xFF) in bytes 12–15; flags+port in 8–11.
    if (substr($bin, 0, 4) === EMBEDDEDV4_TEREDO_PREFIX_BIN) {
        $serverV4 = @inet_ntop(substr($bin, 4, 4));
        $clientObf = substr($bin, 12, 4);
        $clientPlain = '';
        for ($i = 0; $i < 4; $i++) {
            $clientPlain .= chr(ord($clientObf[$i]) ^ 0xFF);
        }
        $clientV4 = @inet_ntop($clientPlain);
        $flags    = bin2hex(substr($bin, 8, 2));
        $portObf  = substr($bin, 10, 2);
        $port     = ((ord($portObf[0]) ^ 0xFF) << 8) | (ord($portObf[1]) ^ 0xFF);
        return [
            'scheme'       => 'teredo',
            'ipv4'         => $clientV4 === false ? null : $clientV4,
            'deprecated'   => false,
            'detail_route' => null,
            'extra'        => [
                'server_ipv4' => $serverV4 === false ? null : $serverV4,
                'flags'       => $flags,
                'udp_port'    => $port,
            ],
        ];
    }

    // 5. ISATAP: IID matches `*:0000:5efe:V4ADDR` (locally-administered)
    //    or `*:0200:5efe:V4ADDR` (globally-unique u-bit set). Bytes 8–11
    //    of the address hold the magic; V4 in bytes 12–15. Any /64 prefix.
    $iidMagic = substr($bin, 8, 4);
    if ($iidMagic === "\x00\x00\x5e\xfe" || $iidMagic === "\x02\x00\x5e\xfe") {
        $v4 = @inet_ntop(substr($bin, 12, 4));
        return [
            'scheme'       => 'isatap',
            'ipv4'         => $v4 === false ? null : $v4,
            'deprecated'   => false,
            'detail_route' => null,
            'extra'        => [
                'globally_unique' => $iidMagic === "\x02\x00\x5e\xfe",
            ],
        ];
    }

    // 6. IPv4-compatible: ::/96, RFC 4291 §2.5.5.1 (DEPRECATED).
    //    Reached only after the mapped/wkp checks above failed, so the top
    //    96 bits are zero AND the bottom 32 bits are not the special ::1
    //    loopback or :: unspecified. Per RFC 4291 those reserved values
    //    are NOT IPv4-compatible.
    if (substr($bin, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00") {
        $tail = substr($bin, 12, 4);
        if ($tail === "\x00\x00\x00\x00" || $tail === "\x00\x00\x00\x01") {
            return $none;
        }
        $v4 = @inet_ntop($tail);
        return [
            'scheme'       => 'compatible',
            'ipv4'         => $v4 === false ? null : $v4,
            'deprecated'   => true,
            'detail_route' => null,
            'extra'        => [],
        ];
    }

    return $none;
}
