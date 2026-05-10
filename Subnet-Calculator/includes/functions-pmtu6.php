<?php

declare(strict_types=1);

// IPv6 PMTU + fragmentation helper (v3.6.0 T5, #399).
//
// Computes effective payload size and the per-fragment breakdown that an
// end host would emit when sending payload_size bytes over a path with
// the given path MTU and extension-header overhead. Surfaces the
// RFC 8200 §5 minimum-link-MTU warning (1280) and the rule that routers
// do not fragment IPv6.
//
// The IPv6 fixed header is always 40 bytes (RFC 8200 §3). Extension
// headers are independently sized (each must be a positive multiple of
// 8). When fragmentation is required, an additional 8-byte Fragment
// header (RFC 8200 §4.5) is added per fragment; the per-fragment
// payload max is rounded down to an 8-byte boundary so that every
// fragment except the last carries an 8-byte-aligned chunk. The last
// fragment's payload is unrestricted (M=0).

/**
 * Compute IPv6 PMTU + fragmentation breakdown.
 *
 * @param int                  $path_mtu          Path MTU in bytes (must be > 0).
 * @param int                  $payload_size      Payload size in bytes (>= 0).
 * @param list<int>            $extension_headers List of extension-header byte sizes
 *                                                 (each must be > 0 and a multiple of 8).
 *
 * @return array{
 *     path_mtu: int,
 *     meets_minimum: bool,
 *     fixed_header: int,
 *     extension_overhead: int,
 *     total_overhead: int,
 *     effective_payload: int,
 *     payload_size: int,
 *     needs_fragmentation: bool,
 *     fragment_count: int,
 *     fragments: list<array{offset: int, m_bit: int, payload_bytes: int}>,
 *     notes: list<string>
 * }
 *
 * @throws InvalidArgumentException on invalid inputs.
 */
function pmtu_compute(int $path_mtu, int $payload_size, array $extension_headers = []): array
{
    if ($path_mtu <= 0) {
        throw new InvalidArgumentException('Path MTU must be a positive integer.');
    }
    if ($payload_size < 0) {
        throw new InvalidArgumentException('Payload size must be zero or a positive integer.');
    }
    if ($payload_size > 65535) {
        throw new InvalidArgumentException(
            'payload_size must not exceed 65535 bytes (max IPv6 packet payload)'
        );
    }
    if (count($extension_headers) > 16) {
        throw new InvalidArgumentException(
            'extension_headers must not contain more than 16 entries'
        );
    }

    $extension_overhead = 0;
    foreach ($extension_headers as $ext) {
        if (!is_int($ext) || $ext <= 0 || ($ext % 8) !== 0) {
            throw new InvalidArgumentException(
                'Each extension-header size must be a positive integer multiple of 8 bytes.'
            );
        }
        $extension_overhead += $ext;
    }

    $fixed_header   = 40;
    $total_overhead = $fixed_header + $extension_overhead;

    if ($total_overhead >= $path_mtu) {
        throw new InvalidArgumentException(
            'Total header overhead (' . $total_overhead .
            ' bytes) meets or exceeds the path MTU (' . $path_mtu . ' bytes).'
        );
    }

    $effective_payload = $path_mtu - $total_overhead;
    $meets_minimum     = $path_mtu >= 1280;

    $notes = [];
    if (!$meets_minimum) {
        $notes[] = 'PMTU ' . $path_mtu .
            ' is below the IPv6 minimum link MTU (1280) per RFC 8200 §5.';
    }

    $fragments = [];
    if ($payload_size <= $effective_payload) {
        // No fragmentation: one virtual fragment carrying the full payload.
        $fragments[] = [
            'offset'        => 0,
            'm_bit'         => 0,
            'payload_bytes' => $payload_size,
        ];
        $needs_fragmentation = false;
        $fragment_count      = 1;
    } else {
        // Fragmentation required. Per-fragment max payload subtracts the
        // 8-byte Fragment header and rounds down to an 8-byte boundary
        // (RFC 8200 §4.5: fragment offset is in 8-byte units).
        $per_fragment_max = intdiv($path_mtu - $total_overhead - 8, 8) * 8;
        if ($per_fragment_max <= 0) {
            throw new InvalidArgumentException(
                'Path MTU is too small to carry any fragmented payload after ' .
                'fixed and extension headers and the 8-byte Fragment header.'
            );
        }
        $projected_count = (int)ceil($payload_size / $per_fragment_max);
        if ($projected_count > 4096) {
            throw new InvalidArgumentException(
                'Result would produce more than 4096 fragments'
            );
        }

        $remaining   = $payload_size;
        $byte_offset = 0;
        while ($remaining > $per_fragment_max) {
            $fragments[] = [
                'offset'        => intdiv($byte_offset, 8),
                'm_bit'         => 1,
                'payload_bytes' => $per_fragment_max,
            ];
            $byte_offset += $per_fragment_max;
            $remaining   -= $per_fragment_max;
        }
        // Last fragment — payload doesn't need to be 8-byte aligned.
        $fragments[] = [
            'offset'        => intdiv($byte_offset, 8),
            'm_bit'         => 0,
            'payload_bytes' => $remaining,
        ];

        $needs_fragmentation = true;
        $fragment_count      = count($fragments);
        $notes[] = 'End-host fragmentation only; routers do not fragment IPv6 per RFC 8200 §5.';
    }

    $notes[] = 'IPv6 fixed header is 40 bytes (RFC 8200 §3).';

    return [
        'path_mtu'             => $path_mtu,
        'meets_minimum'        => $meets_minimum,
        'fixed_header'         => $fixed_header,
        'extension_overhead'   => $extension_overhead,
        'total_overhead'       => $total_overhead,
        'effective_payload'    => $effective_payload,
        'payload_size'         => $payload_size,
        'needs_fragmentation'  => $needs_fragmentation,
        'fragment_count'       => $fragment_count,
        'fragments'            => $fragments,
        'notes'                => $notes,
    ];
}
