<?php

declare(strict_types=1);

// ─── SLAAC privacy addresses (RFC 8981) ──────────────────────────────────────
//
// Extracted from functions-derive6.php in v3.4.0 (carry-forward item #1).
// Public function names unchanged.
//
// Generate a stable-but-pseudo-random 64-bit interface identifier and combine
// it with a /64 prefix. Per RFC 8981 §3.3.2 the U/L bit (bit 6 of byte 8 of
// the address, mask 0x02) MUST be cleared so the address is recognisably a
// privacy IID rather than a Modified EUI-64. Two modes are supported:
//
//   * Unseeded — `random_bytes(8)` provides cryptographically strong entropy.
//   * Seeded — caller supplies a 16-hex-char string for reproducible output
//              (intended for documentation, regression tests, and operator
//              "show me what this prefix would yield" workflows).
//
// `random_bytes` failures are intentionally NOT caught: per the spec, falling
// back to `mt_rand` would silently weaken the privacy guarantee.

/**
 * Generate a SLAAC privacy address (RFC 8981) by combining a /64 prefix with
 * either a caller-supplied or randomly generated 64-bit interface identifier.
 * The U/L bit on the IID is cleared per RFC 8981 §3.3.2.
 *
 * @param string      $prefix Canonical or compressed IPv6 /64 prefix
 *                            (e.g. `2001:db8:1:2::/64`). Host bits in the
 *                            address portion are ignored / zeroed.
 * @param string|null $seed   Optional 16 hexadecimal characters; if omitted a
 *                            cryptographically random seed is generated via
 *                            `random_bytes(8)`.
 *
 * @return array{
 *     prefix: string,
 *     address: string,
 *     interface_id: string,
 *     seed_used: string,
 *     seed_was_provided: bool
 * }
 *
 * @throws \InvalidArgumentException on empty/invalid prefix, non-/64 prefix,
 *                                   or malformed seed.
 */
function slaac_privacy_address(string $prefix, ?string $seed = null): array
{
    $trimmed = trim($prefix);
    if ($trimmed === '') {
        throw new \InvalidArgumentException('Enter a /64 IPv6 prefix.');
    }
    if (!str_contains($trimmed, '/')) {
        throw new \InvalidArgumentException('Invalid IPv6 prefix: ' . $prefix);
    }
    [$addr_part, $len_part] = explode('/', $trimmed, 2);
    if ($len_part !== '64') {
        throw new \InvalidArgumentException('SLAAC privacy addresses require a /64 prefix.');
    }

    $prefix_bin = @inet_pton($addr_part);
    if ($prefix_bin === false || strlen($prefix_bin) !== 16) {
        throw new \InvalidArgumentException('Invalid IPv6 prefix: ' . $prefix);
    }

    // Network-canonicalize: zero out host bits (defensive; we only consume the
    // first 8 bytes anyway).
    $network_bin = substr($prefix_bin, 0, 8) . str_repeat("\0", 8);
    $canonical_addr = inet_ntop($network_bin);
    if ($canonical_addr === false) {
        throw new \InvalidArgumentException('Invalid IPv6 prefix: ' . $prefix);
    }
    $canonical_prefix = $canonical_addr . '/64';

    if ($seed === null) {
        $seed_bytes        = random_bytes(8);
        $seed_used         = bin2hex($seed_bytes);
        $seed_was_provided = false;
    } else {
        $seed_lc = strtolower($seed);
        if (preg_match('/^[0-9a-f]{16}$/', $seed_lc) !== 1) {
            throw new \InvalidArgumentException('Seed must be 16 hexadecimal characters.');
        }
        $bin_seed = hex2bin($seed_lc);
        if ($bin_seed === false || strlen($bin_seed) !== 8) {
            // Unreachable given the regex above, but guard for static analysis.
            throw new \InvalidArgumentException('Seed must be 16 hexadecimal characters.');
        }
        $seed_bytes        = $bin_seed;
        $seed_used         = $seed_lc;
        $seed_was_provided = true;
    }

    // Clear the U/L bit on the first byte of the interface ID (mask 0x02,
    // bit 6 in network byte order per RFC 4291 §2.5.1). EUI-64 sets it;
    // SLAAC privacy clears it.
    $seed_bytes[0] = chr(ord($seed_bytes[0]) & ~0x02);

    $addr_bytes = substr($prefix_bin, 0, 8) . $seed_bytes;
    $address    = inet_ntop($addr_bytes);
    if ($address === false) {
        // Unreachable — 16-byte input always renders.
        throw new \InvalidArgumentException('Failed to assemble SLAAC privacy address.');
    }

    // interface_id rendered as four colon-separated hextets from the modified
    // seed bytes (lowercase, no zero-suppression).
    $iid_hex = bin2hex($seed_bytes);
    $interface_id = substr($iid_hex, 0, 4) . ':'
        . substr($iid_hex, 4, 4) . ':'
        . substr($iid_hex, 8, 4) . ':'
        . substr($iid_hex, 12, 4);

    return [
        'prefix'            => $canonical_prefix,
        'address'           => $address,
        'interface_id'      => $interface_id,
        'seed_used'         => $seed_used,
        'seed_was_provided' => $seed_was_provided,
    ];
}
