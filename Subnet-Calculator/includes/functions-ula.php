<?php

declare(strict_types=1);

// ─── IPv6 ULA prefix generator (RFC 4193) ─────────────────────────────────────

/**
 * Generate a random RFC 4193 ULA /48 prefix, or build one from a supplied global ID.
 *
 * ULA structure (fd prefix, L=1):
 *   fd[GG]:[GGGG]:[GG00]::/48    (fd + 40-bit global ID → /48 prefix)
 *
 * @param  string $global_id  Optional 40-bit global ID as 10 lowercase hex chars.
 *                            Omit or pass '' to generate randomly via random_bytes(5).
 * @return array{prefix?: string, global_id?: string, example_64s?: string[], available_64s?: int, error?: string}
 */
function generate_ula_prefix(string $global_id = ''): array
{
    if ($global_id === '') {
        $global_id = bin2hex(random_bytes(5));
    } else {
        $cleaned   = preg_replace('/[^0-9a-fA-F]/', '', $global_id);
        $global_id = strtolower($cleaned ?? '');
        if (strlen($global_id) !== 10) {
            return ['error' => 'Global ID must be exactly 10 hex characters (40 bits).'];
        }
    }

    // Build the /48 prefix: fd + 40-bit global ID spread across 3 × 16-bit groups
    // Group 1: fd + first byte of global ID   → e.g., "fdaa"
    // Group 2: bytes 2–3 of global ID         → e.g., "bbcc"
    // Group 3: bytes 4–5 of global ID         → e.g., "ddee"
    $g      = $global_id;
    $base   = sprintf('fd%s:%s:%s', substr($g, 0, 2), substr($g, 2, 4), substr($g, 6, 4));
    $prefix = $base . '::/48';

    // Validate that the constructed address is parseable
    $test = inet_pton($base . '::');
    if ($test === false) {
        return ['error' => 'Failed to construct a valid ULA prefix from the supplied global ID.'];
    }

    // Generate the first 5 example /64 subnets
    $example_64s = [];
    for ($i = 0; $i < 5; $i++) {
        $example_64s[] = sprintf('%s:%04x::/64', $base, $i);
    }

    return [
        'prefix'        => $prefix,
        'global_id'     => $g,
        'example_64s'   => $example_64s,
        'available_64s' => 65536, // 2^16 /64 subnets within a /48
    ];
}
