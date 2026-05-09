<?php

declare(strict_types=1);

// IPv6 SSM / unicast-prefix-based multicast (v3.6.0 T3, #398).
//
// Builds and decodes RFC 3306 unicast-prefix-based IPv6 multicast group
// addresses. The full layout per RFC 3306 §4 is:
//
//   |   8   |  4 |  4 |   8    |   8    |   64    |    32     |
//   +-------+----+----+--------+--------+---------+-----------+
//   | 0xFF  | 11RP|scop| reserv | plen   | network | group ID  |
//   |       |  T |    | (0x00) |        | prefix  |           |
//   +-------+----+----+--------+--------+---------+-----------+
//
// The P-flag (bit 1 of the flags nibble) marks a unicast-prefix-based
// group; SSM uses the P+T flag combination (0x3) which yields the
// FF3x::/12 group range. See RFC 4607 for SSM semantics and RFC 3306
// §4.1 for the encoding above. Prefix length is restricted to 0..64
// because the prefix area in the multicast address is exactly 64 bits.
//
// The companion decoder reverses the encoding and rejects any address
// whose flags differ from 0x3 (e.g. FF7x::/12 embedded-RP groups),
// non-multicast input, or a prefix length out of range.

/**
 * Build a unicast-prefix-based / SSM multicast group per RFC 3306.
 *
 * @param string $unicast_prefix Source unicast prefix (e.g. '2001:db8::/32');
 *                               prefix length must be 0..64.
 * @param int    $scope          1..15 (typically 0xE for global).
 * @param int    $group_id       32-bit group ID.
 *
 * @return array{
 *   address: string,
 *   scope: int,
 *   prefix_length: int,
 *   unicast_prefix: string,
 *   group_id: int
 * }
 *
 * @throws InvalidArgumentException on any out-of-range argument.
 */
function build_ssm_group(string $unicast_prefix, int $scope, int $group_id): array
{
    if ($scope < 1 || $scope > 15) {
        throw new InvalidArgumentException('Scope must be in 1..15.');
    }
    if ($group_id < 0 || $group_id > 0xFFFFFFFF) {
        throw new InvalidArgumentException('Group ID must be a 32-bit unsigned integer (0..2^32-1).');
    }

    $slash = strpos($unicast_prefix, '/');
    if ($slash === false) {
        throw new InvalidArgumentException('Unicast prefix must be in <ipv6>/<n> form.');
    }
    $prefix_str    = substr($unicast_prefix, 0, $slash);
    $length_str    = substr($unicast_prefix, $slash + 1);
    if ($length_str === '' || !ctype_digit($length_str)) {
        throw new InvalidArgumentException('Unicast prefix length must be a non-negative integer.');
    }
    $prefix_length = (int)$length_str;
    if ($prefix_length < 0 || $prefix_length > 64) {
        throw new InvalidArgumentException('Unicast prefix length must be in 0..64 for RFC 3306 SSM groups.');
    }

    $bin = @inet_pton($prefix_str);
    if ($bin === false || strlen($bin) !== 16) {
        throw new InvalidArgumentException('Unicast prefix must be a valid IPv6 address.');
    }

    // Mask host bits beyond $prefix_length within the upper 64 bits.
    $masked = ssm6_mask_prefix($bin, $prefix_length);

    // Take the first 8 bytes of the masked prefix; the multicast address
    // only carries the upper 64 bits of the unicast prefix.
    $upper64 = substr($masked, 0, 8);

    // Compose the 16-byte multicast address.
    $out  = "\xFF";
    $out .= chr((0x3 << 4) | $scope);
    $out .= "\x00";
    $out .= chr($prefix_length);
    $out .= $upper64;
    $out .= pack('N', $group_id);

    $address = @inet_ntop($out);
    if (!is_string($address)) {
        // Defensive — should be unreachable since we built exactly 16 bytes.
        throw new InvalidArgumentException('Failed to encode multicast address.');
    }

    // Canonical, lowercase, host-bits-zeroed unicast prefix for echoing back.
    // Pad the upper 64 to a full 128 with zeros so inet_ntop is happy.
    $canon_unicast_bin = $upper64 . str_repeat("\x00", 8);
    $canon_unicast_raw = @inet_ntop($canon_unicast_bin);
    $canon_unicast = is_string($canon_unicast_raw) ? strtolower($canon_unicast_raw) : '::';

    return [
        'address'        => strtolower($address),
        'scope'          => $scope,
        'prefix_length'  => $prefix_length,
        'unicast_prefix' => $canon_unicast,
        'group_id'       => $group_id,
    ];
}

/**
 * Decode an SSM / unicast-prefix-based multicast group (RFC 3306).
 *
 * Inverse of build_ssm_group(). Requires the flags nibble to be exactly
 * 0x3 (P+T); embedded-RP groups (R+P+T = 0x7) are out of scope and
 * routed to the embedded-RP tool by the multicast scope decoder.
 *
 * @return array{
 *   address: string,
 *   scope: int,
 *   prefix_length: int,
 *   unicast_prefix: string,
 *   group_id: int
 * }
 *
 * @throws InvalidArgumentException on any non-SSM or malformed input.
 */
function decode_ssm_group(string $ipv6): array
{
    $bin = @inet_pton($ipv6);
    if ($bin === false || strlen($bin) !== 16) {
        throw new InvalidArgumentException('Invalid IPv6 address.');
    }
    if (ord($bin[0]) !== 0xFF) {
        throw new InvalidArgumentException('Address is not in the IPv6 multicast range FF00::/8.');
    }

    $flags = (ord($bin[1]) & 0xF0) >> 4;
    $scope = ord($bin[1]) & 0x0F;
    if ($flags !== 0x3) {
        throw new InvalidArgumentException(
            'Address is not a unicast-prefix-based / SSM multicast group (flags must be 0x3 = P+T).'
        );
    }

    $prefix_length = ord($bin[3]);
    if ($prefix_length > 64) {
        throw new InvalidArgumentException('Prefix length out of range (must be 0..64).');
    }

    $upper64    = substr($bin, 4, 8);
    $masked     = ssm6_mask_prefix($upper64 . str_repeat("\x00", 8), $prefix_length);
    $canon_raw  = @inet_ntop($masked);
    $canonical  = is_string($canon_raw) ? strtolower($canon_raw) : '::';

    /** @var array{0: int, 1: int}|false $unpacked */
    $unpacked = @unpack('N', substr($bin, 12, 4));
    if ($unpacked === false || !isset($unpacked[1])) {
        throw new InvalidArgumentException('Failed to extract group ID.');
    }
    // unpack('N', ...) returns a possibly-signed int on 32-bit PHP; the app
    // requires 64-bit so this is always 0..2^32-1, but coerce defensively.
    $group_id = $unpacked[1] & 0xFFFFFFFF;

    $address_raw = @inet_ntop($bin);
    $address     = is_string($address_raw) ? strtolower($address_raw) : strtolower($ipv6);

    return [
        'address'        => $address,
        'scope'          => $scope,
        'prefix_length'  => $prefix_length,
        'unicast_prefix' => $canonical,
        'group_id'       => (int)$group_id,
    ];
}

/**
 * Zero host bits beyond $prefix_length in a 16-byte IPv6 binary string.
 *
 * Internal helper for build/decode SSM. Returns a fresh 16-byte string.
 *
 * @internal
 */
function ssm6_mask_prefix(string $bin, int $prefix_length): string
{
    if (strlen($bin) !== 16) {
        throw new InvalidArgumentException('Internal: ssm6_mask_prefix requires 16 bytes.');
    }
    if ($prefix_length >= 128) {
        return $bin;
    }
    if ($prefix_length <= 0) {
        return str_repeat("\x00", 16);
    }
    $full_bytes  = intdiv($prefix_length, 8);
    $remain_bits = $prefix_length % 8;
    $out         = substr($bin, 0, $full_bytes);
    if ($remain_bits !== 0) {
        $mask = (0xFF << (8 - $remain_bits)) & 0xFF;
        $out .= chr(ord($bin[$full_bytes]) & $mask);
        $full_bytes++;
    }
    $out .= str_repeat("\x00", 16 - strlen($out));
    return $out;
}
