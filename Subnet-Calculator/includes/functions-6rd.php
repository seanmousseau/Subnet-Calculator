<?php

declare(strict_types=1);

// 6rd delegation helper (RFC 5969) — bidirectional translation between a
// customer IPv4 address and the customer-delegated IPv6 prefix derived
// from a service-provider 6rd domain.
//
// 6rd is a service-provider-configured tunneling scheme. Unlike 6to4
// (which uses the well-known 2002::/16 prefix and embeds the full v4),
// 6rd uses the SP's own globally-routed IPv6 prefix and may discard
// some leading bits of the customer v4 if the SP knows those bits are
// constant across its v4 footprint. The customer-delegated prefix is:
//
//   [ SP IPv6 prefix ][ customer v4 with top mask_len bits stripped ]
//
// The new prefix length is sp_prefix_length + (32 - sp_ipv4_mask_len).
// 6rd cannot be detected from an address alone — the SP parameters are
// out-of-band configuration. This tool is therefore configurable
// rather than auto-detecting.

/**
 * Compute the customer's delegated IPv6 prefix per RFC 5969 §4.
 *
 * @param string $sp_ipv6_prefix    e.g. '2001:db8::/32'
 * @param int    $sp_ipv4_mask_len  bits of the customer IPv4 to discard from the high end
 *                                  (typically 0 for full v4)
 * @param string $customer_ipv4
 *
 * @return array{prefix: string, prefix_length: int}
 *
 * @throws InvalidArgumentException
 */
function compute_6rd_delegation(string $sp_ipv6_prefix, int $sp_ipv4_mask_len, string $customer_ipv4): array
{
    [$sp_address, $sp_prefix_length] = sixrd_parse_sp_prefix($sp_ipv6_prefix);

    if ($sp_ipv4_mask_len < 0 || $sp_ipv4_mask_len > 32) {
        throw new InvalidArgumentException(
            'SP IPv4 mask length must be between 0 and 32: ' . $sp_ipv4_mask_len
        );
    }

    $customer_bits  = 32 - $sp_ipv4_mask_len;
    $delegated_len  = $sp_prefix_length + $customer_bits;
    if ($delegated_len > 128) {
        throw new InvalidArgumentException(
            'SP prefix length plus customer bits exceeds 128: '
            . $sp_prefix_length . ' + ' . $customer_bits
        );
    }

    $v4 = trim($customer_ipv4);
    if ($v4 === '') {
        throw new InvalidArgumentException('Customer IPv4 address is required.');
    }
    if (filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        throw new InvalidArgumentException('Invalid customer IPv4 address: ' . $v4);
    }
    $v4Bin = @inet_pton($v4);
    if ($v4Bin === false || strlen($v4Bin) !== 4) {
        throw new InvalidArgumentException('Invalid customer IPv4 address: ' . $v4);
    }

    // Pack everything into a 128-bit GMP integer, big-endian.
    $sp_int       = gmp_init(bin2hex($sp_address), 16);
    $v4_int       = gmp_init(bin2hex($v4Bin), 16);

    // Strip the top sp_ipv4_mask_len bits: keep only the bottom $customer_bits.
    if ($customer_bits === 0) {
        $customer_int = gmp_init(0);
    } else {
        $mask = gmp_sub(gmp_pow(2, $customer_bits), 1);
        $customer_int = gmp_and($v4_int, $mask);
    }

    // SP prefix occupies the top $sp_prefix_length bits of the 128-bit address;
    // the inet_pton bytes already have host bits zeroed only if the input was
    // canonical, so mask them explicitly to be safe.
    if ($sp_prefix_length === 0) {
        $sp_masked = gmp_init(0);
    } else {
        $sp_mask  = gmp_sub(gmp_pow(2, 128), gmp_pow(2, 128 - $sp_prefix_length));
        $sp_masked = gmp_and($sp_int, $sp_mask);
    }

    // Shift customer bits into position: [sp_prefix_length, sp_prefix_length + customer_bits)
    $customer_shifted = gmp_mul($customer_int, gmp_pow(2, 128 - $delegated_len));

    $delegated_int = gmp_or($sp_masked, $customer_shifted);

    $hex   = str_pad(gmp_strval($delegated_int, 16), 32, '0', STR_PAD_LEFT);
    $bytes = hex2bin($hex);
    if ($bytes === false || strlen($bytes) !== 16) {
        throw new InvalidArgumentException('Internal error packing delegated prefix.');
    }
    $canonical = @inet_ntop($bytes);
    if ($canonical === false) {
        throw new InvalidArgumentException('Internal error formatting delegated prefix.');
    }

    return [
        'prefix'        => $canonical . '/' . $delegated_len,
        'prefix_length' => $delegated_len,
    ];
}

/**
 * Reverse: extract the customer IPv4 from a 6rd address given SP parameters.
 * The masked-off region (top sp_ipv4_mask_len bits of the v4) is treated as
 * zero — the encoder discards it so the decoder cannot recover it without
 * additional out-of-band information.
 *
 * @throws InvalidArgumentException
 */
function extract_6rd_ipv4(string $sp_ipv6_prefix, int $sp_ipv4_mask_len, string $rd_ipv6_address): string
{
    [$sp_address, $sp_prefix_length] = sixrd_parse_sp_prefix($sp_ipv6_prefix);

    if ($sp_ipv4_mask_len < 0 || $sp_ipv4_mask_len > 32) {
        throw new InvalidArgumentException(
            'SP IPv4 mask length must be between 0 and 32: ' . $sp_ipv4_mask_len
        );
    }
    $customer_bits = 32 - $sp_ipv4_mask_len;
    $delegated_len = $sp_prefix_length + $customer_bits;
    if ($delegated_len > 128) {
        throw new InvalidArgumentException(
            'SP prefix length plus customer bits exceeds 128.'
        );
    }

    $rd = trim($rd_ipv6_address);
    if ($rd === '') {
        throw new InvalidArgumentException('6rd IPv6 address is required.');
    }
    // Strip optional /prefix on the input — accept full addresses or prefixes.
    $slash = strpos($rd, '/');
    if ($slash !== false) {
        $rd = substr($rd, 0, $slash);
    }
    if (filter_var($rd, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
        throw new InvalidArgumentException('Invalid 6rd IPv6 address: ' . $rd);
    }
    $rdBin = @inet_pton($rd);
    if ($rdBin === false || strlen($rdBin) !== 16) {
        throw new InvalidArgumentException('Invalid 6rd IPv6 address: ' . $rd);
    }

    $rd_int = gmp_init(bin2hex($rdBin), 16);
    $sp_int = gmp_init(bin2hex($sp_address), 16);

    // Verify the address starts with the SP prefix bits.
    if ($sp_prefix_length > 0) {
        $sp_mask    = gmp_sub(gmp_pow(2, 128), gmp_pow(2, 128 - $sp_prefix_length));
        $rd_high    = gmp_and($rd_int, $sp_mask);
        $sp_masked  = gmp_and($sp_int, $sp_mask);
        if (gmp_cmp($rd_high, $sp_masked) !== 0) {
            throw new InvalidArgumentException(
                '6rd address does not start with the SP IPv6 prefix: ' . $rd
            );
        }
    }

    // Read bits [sp_prefix_length, sp_prefix_length + customer_bits) as the
    // customer-contributed bits. Right-shift by (128 - delegated_len) and mask
    // to $customer_bits.
    $shifted = gmp_div_q($rd_int, gmp_pow(2, 128 - $delegated_len));
    if ($customer_bits === 0) {
        $customer_int = gmp_init(0);
    } else {
        $mask         = gmp_sub(gmp_pow(2, $customer_bits), 1);
        $customer_int = gmp_and($shifted, $mask);
    }

    // The masked-off (high) region is unknown — surface as zero per RFC 5969 §4
    // (the SP-side reconstruction step requires the SP's known v4 prefix).
    $v4_int_full = $customer_int; // top sp_ipv4_mask_len bits already zero.

    $v4Hex = str_pad(gmp_strval($v4_int_full, 16), 8, '0', STR_PAD_LEFT);
    $v4Bin = hex2bin($v4Hex);
    if ($v4Bin === false || strlen($v4Bin) !== 4) {
        throw new InvalidArgumentException('Internal error packing customer IPv4.');
    }
    $v4 = @inet_ntop($v4Bin);
    if ($v4 === false) {
        throw new InvalidArgumentException('Internal error formatting customer IPv4.');
    }
    return $v4;
}

/**
 * Parse an SP IPv6 prefix string ("2001:db8::/32") into the 16-byte network
 * address and the prefix length.
 *
 * @return array{0: string, 1: int}
 *
 * @throws InvalidArgumentException
 */
function sixrd_parse_sp_prefix(string $sp_ipv6_prefix): array
{
    $raw = trim($sp_ipv6_prefix);
    if ($raw === '') {
        throw new InvalidArgumentException('SP IPv6 prefix is required.');
    }
    if (strpos($raw, '/') === false) {
        throw new InvalidArgumentException(
            'SP IPv6 prefix must include a length (e.g. 2001:db8::/32): ' . $raw
        );
    }
    [$addr, $lenStr] = explode('/', $raw, 2);
    if ($addr === '' || $lenStr === '' || !ctype_digit($lenStr)) {
        throw new InvalidArgumentException('Invalid SP IPv6 prefix: ' . $raw);
    }
    if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
        throw new InvalidArgumentException('Invalid SP IPv6 address: ' . $addr);
    }
    $len = (int) $lenStr;
    if ($len < 0 || $len > 128) {
        throw new InvalidArgumentException(
            'SP IPv6 prefix length must be 0..128: ' . $len
        );
    }
    $bin = @inet_pton($addr);
    if ($bin === false || strlen($bin) !== 16) {
        throw new InvalidArgumentException('Invalid SP IPv6 address: ' . $addr);
    }
    return [$bin, $len];
}
