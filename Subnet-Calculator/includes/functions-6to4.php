<?php

declare(strict_types=1);

// 6to4 address tool (RFC 3056) — bidirectional translation between
// public IPv4 and 2002::/16 6to4 prefixes.
//
//   IPv4 W.X.Y.Z   →   2002:WWXX:YYZZ::/48
//
// Bytes 2–5 of the IPv6 address carry the embedded IPv4. Bytes 6–7
// hold the 16-bit Subnet/SLA ID; bytes 8–15 hold the 64-bit Interface
// ID. RFC 7526 deprecated the 6to4 anycast relay in 2015; this tool
// is provided for analysis and migration audits, not for greenfield
// deployments.
//
// Encoding rejects non-public IPv4 inputs:
//   - private (RFC 1918) and reserved ranges via filter_var flags
//   - multicast 224.0.0.0/4 (filter_var leaves these enabled)
//   - 0.0.0.0 unspecified
// Decoding rejects any address that is not under 2002::/16.

const SIX_TO_FOUR_PREFIX_BIN = "\x20\x02";

/**
 * Convert a public IPv4 address to its 6to4 prefix.
 *
 * @return string The 2002:WWXX:YYZZ::/48 prefix in canonical compressed form.
 *
 * @throws InvalidArgumentException if the IPv4 is malformed, private,
 *         reserved, multicast, or the unspecified address.
 */
function ipv4_to_6to4(string $public_ipv4): string
{
    $v4 = trim($public_ipv4);
    if ($v4 === '') {
        throw new InvalidArgumentException('IPv4 address is required.');
    }

    // Reject any non-IPv4 literal up front. FILTER_FLAG_NO_PRIV_RANGE
    // (RFC 1918 + 169.254/16 + ::1 etc.) and FILTER_FLAG_NO_RES_RANGE
    // (loopback, broadcast, "this network", documentation, future-use,
    // benchmarking, and multicast 224.0.0.0/4 + reserved 240.0.0.0/4)
    // together cover every IANA-special-purpose v4 prefix. We still
    // do an explicit multicast check below so the error message
    // mentions multicast specifically when that's the cause.
    if (filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        throw new InvalidArgumentException('Invalid IPv4 address: ' . $v4);
    }

    $packed = inet_pton($v4);
    if ($packed === false || strlen($packed) !== 4) {
        // Defensive: filter_var said yes, inet_pton said no. Should never happen.
        throw new InvalidArgumentException('Invalid IPv4 address: ' . $v4);
    }

    $first = ord($packed[0]);

    // Reject multicast 224.0.0.0/4 (224–239) and reserved 240.0.0.0/4 (240–255)
    // explicitly with their own message — FILTER_FLAG_NO_RES_RANGE behaviour
    // varies across PHP versions for these blocks.
    if ($first >= 224 && $first <= 239) {
        throw new InvalidArgumentException(
            'Multicast IPv4 cannot be encoded as 6to4: ' . $v4
        );
    }
    if ($first >= 240) {
        throw new InvalidArgumentException(
            'Reserved IPv4 cannot be encoded as 6to4: ' . $v4
        );
    }

    // FILTER_FLAG_NO_PRIV_RANGE blocks RFC 1918 (10/8, 172.16/12, 192.168/16)
    // and link-local 169.254/16. FILTER_FLAG_NO_RES_RANGE blocks the rest of
    // the IANA special-purpose v4 prefixes (loopback 127/8, broadcast,
    // "this network" 0/8, documentation, benchmarking).
    $strict = filter_var(
        $v4,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
    if ($strict === false) {
        throw new InvalidArgumentException(
            'Private, reserved, or non-public IPv4 cannot be encoded as 6to4: ' . $v4
        );
    }

    // Render the /48 prefix as 2002:WWXX:YYZZ::/48. We format the two
    // V4-derived hexadectets manually with %04x so that an octet like
    // .2.1 becomes "0201", not "201" — inet_ntop()'s RFC 5952 compression
    // would suppress the leading zero and break the canonical form expected
    // by RFC 3056.
    $hex1 = sprintf('%04x', (ord($packed[0]) << 8) | ord($packed[1]));
    $hex2 = sprintf('%04x', (ord($packed[2]) << 8) | ord($packed[3]));
    return '2002:' . $hex1 . ':' . $hex2 . '::/48';
}

/**
 * Decode a 6to4 IPv6 address back to its embedded public IPv4.
 *
 * @return array{ipv4: string, subnet_id: int, interface_id: string}
 *         The embedded IPv4, the 16-bit Subnet/SLA ID, and the 64-bit
 *         Interface ID rendered as four colon-separated 16-bit groups.
 *
 * @throws InvalidArgumentException if the address is not a parseable
 *         IPv6 literal under 2002::/16.
 */
function decode_6to4(string $ipv6): array
{
    $raw = trim($ipv6);
    if ($raw === '') {
        throw new InvalidArgumentException('IPv6 address is required.');
    }

    $bin = @inet_pton($raw);
    if ($bin === false || strlen($bin) !== 16) {
        throw new InvalidArgumentException('Invalid IPv6 address: ' . $raw);
    }

    if (substr($bin, 0, 2) !== SIX_TO_FOUR_PREFIX_BIN) {
        throw new InvalidArgumentException(
            'Address is not under 2002::/16: ' . $raw
        );
    }

    $v4 = @inet_ntop(substr($bin, 2, 4));
    if ($v4 === false) {
        throw new InvalidArgumentException('Internal error decoding embedded IPv4.');
    }

    $subnetId = (ord($bin[6]) << 8) | ord($bin[7]);

    $iidBytes = substr($bin, 8, 8);
    // Render IID as four 16-bit groups, lowercase hex, zero-padded.
    $iidParts = [];
    for ($i = 0; $i < 8; $i += 2) {
        $iidParts[] = sprintf('%04x', (ord($iidBytes[$i]) << 8) | ord($iidBytes[$i + 1]));
    }

    return [
        'ipv4'         => $v4,
        'subnet_id'    => $subnetId,
        'interface_id' => implode(':', $iidParts),
    ];
}
