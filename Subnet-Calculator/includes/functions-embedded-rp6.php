<?php

declare(strict_types=1);

// IPv6 embedded-RP multicast (v3.6.0 T4, #397).
//
// Builds and decodes RFC 3956 embedded-RP IPv6 multicast group addresses
// (FF7x::/12). The Rendezvous Point's IPv6 address is encoded directly
// into the multicast group address so any router can derive the RP for
// a group without an out-of-band RP-set distribution protocol.
//
// Layout per RFC 3956 §4:
//
//   |   8   |  4  |  4  |   4    |  4   |   8    |   64    |    32    |
//   +-------+-----+-----+--------+------+--------+---------+----------+
//   | 0xFF  | 0RPT| scop| Rsvd   | RIID | RP plen| RP-prefix| group ID|
//   |       | =0x7|     | =0x0   |      |        | (upper64)|         |
//   +-------+-----+-----+--------+------+--------+---------+----------+
//
// Flags must be 0x7 (R+P+T set). Prefix length is restricted to 0..64
// because only the upper 64 bits of the RP prefix fit in the address.
// RIID is the 4-bit RP interface ID; the canonical RP address is the
// RP prefix with the RIID set in the low nibble of the last byte
// (i.e. RP-prefix + ::RIID).

/**
 * Build an embedded-RP multicast group per RFC 3956.
 *
 * The RP address can have any host suffix; only the upper 64 bits (after
 * applying $rp_prefix_length) and the explicit $riid argument are encoded
 * in the resulting multicast group address. The returned `rp_address`
 * field is canonicalized to RP-prefix + `::<riid>`.
 *
 * @param string $rp_address       RP IPv6 address (e.g. '2001:db8:cafe::1').
 * @param int    $rp_prefix_length 0..64.
 * @param int    $riid             4-bit RP interface ID (0..15).
 * @param int    $scope            1..15.
 * @param int    $group_id         32-bit group ID.
 *
 * @return array{
 *   address: string,
 *   scope: int,
 *   rp_prefix: string,
 *   rp_prefix_length: int,
 *   rp_address: string,
 *   riid: int,
 *   group_id: int
 * }
 *
 * @throws InvalidArgumentException on validation failure.
 */
function build_embedded_rp_group(
    string $rp_address,
    int $rp_prefix_length,
    int $riid,
    int $scope,
    int $group_id
): array {
    if ($scope < 1 || $scope > 15) {
        throw new InvalidArgumentException('Scope must be in 1..15.');
    }
    if ($riid < 0 || $riid > 15) {
        throw new InvalidArgumentException('RIID must be a 4-bit value (0..15).');
    }
    if ($rp_prefix_length < 0 || $rp_prefix_length > 64) {
        throw new InvalidArgumentException('RP prefix length must be in 0..64.');
    }
    if ($group_id < 0 || $group_id > 0xFFFFFFFF) {
        throw new InvalidArgumentException('Group ID must be a 32-bit unsigned integer (0..2^32-1).');
    }

    $rp_bin = @inet_pton($rp_address);
    if ($rp_bin === false || strlen($rp_bin) !== 16) {
        throw new InvalidArgumentException('RP address must be a valid IPv6 address.');
    }

    // Mask the RP prefix to its declared length; only the upper 64 bits
    // are actually encoded in the multicast group address.
    $masked_prefix = embedded_rp6_mask_prefix($rp_bin, $rp_prefix_length);
    $upper64       = substr($masked_prefix, 0, 8);

    // 16-byte multicast address per RFC 3956 §4.
    $out  = "\xFF";
    $out .= chr((0x7 << 4) | $scope);
    $out .= chr($riid & 0x0F);
    $out .= chr($rp_prefix_length);
    $out .= $upper64;
    $out .= pack('N', $group_id);

    $address = @inet_ntop($out);
    if (!is_string($address)) {
        throw new InvalidArgumentException('Failed to encode multicast address.');
    }

    // Canonical RP prefix string (host bits zeroed, lowercase, compressed).
    $rp_prefix_bin = $upper64 . str_repeat("\x00", 8);
    $rp_prefix_str = @inet_ntop($rp_prefix_bin);
    $rp_prefix     = is_string($rp_prefix_str) ? strtolower($rp_prefix_str) : '::';

    // Canonical RP address = RP-prefix + ::RIID
    $rp_addr_bin   = $upper64 . str_repeat("\x00", 7) . chr($riid & 0x0F);
    $rp_addr_str   = @inet_ntop($rp_addr_bin);
    $canon_rp_addr = is_string($rp_addr_str) ? strtolower($rp_addr_str) : '::';

    return [
        'address'          => strtolower($address),
        'scope'            => $scope,
        'rp_prefix'        => $rp_prefix,
        'rp_prefix_length' => $rp_prefix_length,
        'rp_address'       => $canon_rp_addr,
        'riid'             => $riid,
        'group_id'         => $group_id,
    ];
}

/**
 * Decode an embedded-RP multicast group (RFC 3956).
 *
 * Inverse of build_embedded_rp_group(). Requires the flag nibble to be
 * exactly 0x7 (R+P+T); SSM-only groups (P+T = 0x3) and other multicast
 * schemes are rejected and routed to their own tools.
 *
 * @return array{
 *   address: string,
 *   scope: int,
 *   rp_prefix: string,
 *   rp_prefix_length: int,
 *   rp_address: string,
 *   riid: int,
 *   group_id: int
 * }
 *
 * @throws InvalidArgumentException on any malformed or non-embedded-RP input.
 */
function decode_embedded_rp_group(string $ipv6): array
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
    if ($flags !== 0x7) {
        throw new InvalidArgumentException(
            'Address is not an embedded-RP multicast group (flags must be 0x7 = R+P+T).'
        );
    }

    // High nibble of byte 2 is reserved; ignore it but expose RIID from the low nibble.
    $riid = ord($bin[2]) & 0x0F;

    $rp_prefix_length = ord($bin[3]);
    if ($rp_prefix_length > 64) {
        throw new InvalidArgumentException('RP prefix length out of range (must be 0..64).');
    }

    // RP prefix = first 8 bytes of the prefix area + 8 zero bytes,
    // re-masked defensively in case the source encoded host bits.
    $upper64       = substr($bin, 4, 8);
    $masked_prefix = embedded_rp6_mask_prefix($upper64 . str_repeat("\x00", 8), $rp_prefix_length);
    $rp_prefix_str = @inet_ntop($masked_prefix);
    $rp_prefix     = is_string($rp_prefix_str) ? strtolower($rp_prefix_str) : '::';

    // Canonical RP address = RP prefix + ::RIID (RIID in last byte's low nibble).
    $rp_addr_bin = substr($masked_prefix, 0, 8) . str_repeat("\x00", 7) . chr($riid & 0x0F);
    $rp_addr_str = @inet_ntop($rp_addr_bin);
    $rp_address  = is_string($rp_addr_str) ? strtolower($rp_addr_str) : '::';

    /** @var array{0: int, 1: int}|false $unpacked */
    $unpacked = @unpack('N', substr($bin, 12, 4));
    if ($unpacked === false || !isset($unpacked[1])) {
        throw new InvalidArgumentException('Failed to extract group ID.');
    }
    $group_id = $unpacked[1] & 0xFFFFFFFF;

    $address_raw = @inet_ntop($bin);
    $address     = is_string($address_raw) ? strtolower($address_raw) : strtolower($ipv6);

    return [
        'address'          => $address,
        'scope'            => $scope,
        'rp_prefix'        => $rp_prefix,
        'rp_prefix_length' => $rp_prefix_length,
        'rp_address'       => $rp_address,
        'riid'             => $riid,
        'group_id'         => (int)$group_id,
    ];
}

/**
 * Zero host bits beyond $prefix_length in a 16-byte IPv6 binary string.
 *
 * Internal helper for build/decode embedded-RP. Returns a fresh 16-byte string.
 *
 * @internal
 */
function embedded_rp6_mask_prefix(string $bin, int $prefix_length): string
{
    if (strlen($bin) !== 16) {
        throw new InvalidArgumentException('Internal: embedded_rp6_mask_prefix requires 16 bytes.');
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
