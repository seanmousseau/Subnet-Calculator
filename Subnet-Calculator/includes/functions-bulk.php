<?php

declare(strict_types=1);

// Bulk multi-op dispatcher (v3.4.0).
//
// The legacy /bulk endpoint accepts a flat `cidrs[]` array and resolves each
// entry as IPv4 or IPv6 via resolve_*_input(). This dispatcher complements
// that mode by accepting a heterogeneous `items[]` array where each item
// carries its own `op` slug + `params` payload, so callers can mix the
// v3.3.0 IPv6 endpoints (range6, supernet6, zone-id, derive, slaac-privacy)
// and the v3.4.0 endpoints (rdns6, mapped6) in a single round-trip.
//
// Per-item envelope on success: { op, ok: true, ...result }
// Per-item envelope on error:   { op, ok: false, error: <string> }
//
// Errors thrown by underlying functions (InvalidArgumentException) and
// shape-level errors (`['error' => ...]` returns) are normalised into the
// `ok: false` envelope. The dispatcher never throws on per-item failures —
// it always returns one envelope per input item. Top-level shape errors
// (e.g. missing `op`, unsupported op) are also reported per-item.

const BULK_SUPPORTED_OPS = [
    'range6',
    'supernet6',
    'zone-id',
    'derive',
    'slaac-privacy',
    'rdns6',
    'mapped6',
];

/**
 * Dispatch a list of bulk items to their per-op handlers.
 *
 * @param array<int, mixed> $items
 * @return array<int, array<string, mixed>>
 */
function bulk_dispatch_ops(array $items): array
{
    $out = [];
    foreach ($items as $item) {
        $out[] = bulk_dispatch_one($item);
    }
    return $out;
}

/**
 * @param mixed $item
 * @return array<string, mixed>
 */
function bulk_dispatch_one($item): array
{
    if (!is_array($item)) {
        return ['op' => '', 'ok' => false, 'error' => 'Item must be an object.'];
    }

    $op_raw = $item['op'] ?? '';
    $op     = is_string($op_raw) ? trim($op_raw) : '';
    if ($op === '') {
        return ['op' => '', 'ok' => false, 'error' => 'Field "op" is required.'];
    }

    if (!in_array($op, BULK_SUPPORTED_OPS, true)) {
        return [
            'op'    => $op,
            'ok'    => false,
            'error' => 'Unsupported op "' . $op . '". Supported: '
                . implode(', ', BULK_SUPPORTED_OPS) . '.',
        ];
    }

    $params_raw = $item['params'] ?? [];
    $params     = is_array($params_raw) ? $params_raw : [];

    try {
        switch ($op) {
            case 'range6':
                return _bulk_op_range6($params);
            case 'supernet6':
                return _bulk_op_supernet6($params);
            case 'zone-id':
                return _bulk_op_zone_id($params);
            case 'derive':
                return _bulk_op_derive($params);
            case 'slaac-privacy':
                return _bulk_op_slaac_privacy($params);
            case 'rdns6':
                return _bulk_op_rdns6($params);
            case 'mapped6':
                return _bulk_op_mapped6($params);
        }
    } catch (\InvalidArgumentException $e) {
        return ['op' => $op, 'ok' => false, 'error' => $e->getMessage()];
    } catch (\Throwable $e) {
        return ['op' => $op, 'ok' => false, 'error' => $e->getMessage()];
    }

    // Unreachable — switch is exhaustive over the supported ops.
    return ['op' => $op, 'ok' => false, 'error' => 'Internal dispatcher error.'];
}

// ── Per-op adapters ──────────────────────────────────────────────────────────
//
// Each adapter takes the per-item `params` array, validates the minimal fields
// the underlying pure function expects, calls it, and returns the success
// envelope. Validation errors throw InvalidArgumentException so the wrapping
// switch catches them uniformly.

/**
 * @param array<string, mixed> $p
 * @return array<string, mixed>
 */
function _bulk_op_range6(array $p): array
{
    $start = isset($p['start']) && is_string($p['start']) ? trim($p['start']) : '';
    $end   = isset($p['end'])   && is_string($p['end'])   ? trim($p['end'])   : '';
    if ($start === '') {
        throw new \InvalidArgumentException('Field "start" is required.');
    }
    if ($end === '') {
        throw new \InvalidArgumentException('Field "end" is required.');
    }
    $r = range6_to_cidrs($start, $end);
    if (isset($r['error'])) {
        throw new \InvalidArgumentException((string)$r['error']);
    }
    return [
        'op'              => 'range6',
        'ok'              => true,
        'cidrs'           => $r['cidrs'] ?? [],
        'count'           => $r['count'] ?? 0,
        'total_addresses' => $r['total_addresses'] ?? 0,
        'truncated'       => $r['truncated'] ?? false,
        'cap'             => $r['cap'] ?? 256,
    ];
}

/**
 * @param array<string, mixed> $p
 * @return array<string, mixed>
 */
function _bulk_op_supernet6(array $p): array
{
    $action_raw = $p['action'] ?? 'find';
    $action     = is_string($action_raw) ? trim($action_raw) : 'find';
    if (!in_array($action, ['find', 'summarise'], true)) {
        throw new \InvalidArgumentException('Field "action" must be "find" or "summarise".');
    }
    $cidrs_raw = $p['cidrs'] ?? [];
    if (!is_array($cidrs_raw) || count($cidrs_raw) === 0) {
        throw new \InvalidArgumentException('Field "cidrs" must be a non-empty array.');
    }
    if (count($cidrs_raw) > 50) {
        throw new \InvalidArgumentException('Maximum 50 CIDRs per item.');
    }
    $lines = array_values(array_filter(array_map(
        static fn($c) => trim((string)$c),
        $cidrs_raw
    )));
    if (count($lines) === 0) {
        throw new \InvalidArgumentException('Field "cidrs" must contain at least one non-empty entry.');
    }

    if ($action === 'find') {
        $r = supernet6_find($lines);
        if (isset($r['error'])) {
            throw new \InvalidArgumentException((string)$r['error']);
        }
        return ['op' => 'supernet6', 'ok' => true, 'supernet' => $r['supernet'] ?? ''];
    }

    $r = summarise6_cidrs($lines);
    if (isset($r['error'])) {
        throw new \InvalidArgumentException((string)$r['error']);
    }
    return ['op' => 'supernet6', 'ok' => true, 'summaries' => $r['summaries'] ?? []];
}

/**
 * @param array<string, mixed> $p
 * @return array<string, mixed>
 */
function _bulk_op_zone_id(array $p): array
{
    $input = isset($p['input']) && is_string($p['input']) ? trim($p['input']) : '';
    if ($input === '') {
        throw new \InvalidArgumentException('Field "input" is required.');
    }
    $r = parse_zone_id($input);
    return [
        'op'            => 'zone-id',
        'ok'            => true,
        'address'       => $r['address'],
        'zone_id'       => $r['zone_id'],
        'is_link_local' => $r['is_link_local'],
        'warning'       => $r['warning'],
    ];
}

/**
 * @param array<string, mixed> $p
 * @return array<string, mixed>
 */
function _bulk_op_derive(array $p): array
{
    $mac = isset($p['mac']) && is_string($p['mac']) ? trim($p['mac']) : '';
    if ($mac === '') {
        throw new \InvalidArgumentException('Field "mac" is required.');
    }
    $r = derive_from_mac($mac);
    return [
        'op'             => 'derive',
        'ok'             => true,
        'mac_canonical'  => $r['mac_canonical'],
        'eui64'          => $r['eui64'],
        'ul_bit_flipped' => $r['ul_bit_flipped'],
        'link_local'     => $r['link_local'],
        'solicited_node' => $r['solicited_node'],
        'warning'        => $r['warning'],
    ];
}

/**
 * @param array<string, mixed> $p
 * @return array<string, mixed>
 */
function _bulk_op_slaac_privacy(array $p): array
{
    $prefix = isset($p['prefix']) && is_string($p['prefix']) ? trim($p['prefix']) : '';
    if ($prefix === '') {
        throw new \InvalidArgumentException('Field "prefix" is required.');
    }
    $seed = null;
    if (array_key_exists('seed', $p) && $p['seed'] !== null) {
        if (!is_string($p['seed'])) {
            throw new \InvalidArgumentException('Field "seed" must be a string.');
        }
        $seed_raw = trim($p['seed']);
        if ($seed_raw !== '') {
            $seed = $seed_raw;
        }
    }
    $r = slaac_privacy_address($prefix, $seed);
    return [
        'op'                => 'slaac-privacy',
        'ok'                => true,
        'prefix'            => $r['prefix'],
        'address'           => $r['address'],
        'interface_id'      => $r['interface_id'],
        'seed_used'         => $r['seed_used'],
        'seed_was_provided' => $r['seed_was_provided'],
    ];
}

/**
 * @param array<string, mixed> $p
 * @return array<string, mixed>
 */
function _bulk_op_rdns6(array $p): array
{
    $address = isset($p['address']) && is_string($p['address']) ? trim($p['address']) : '';
    if ($address === '') {
        throw new \InvalidArgumentException('Field "address" is required.');
    }
    $prefix = null;
    if (array_key_exists('prefix', $p) && $p['prefix'] !== null && $p['prefix'] !== '') {
        if (is_int($p['prefix'])) {
            $prefix = $p['prefix'];
        } elseif (is_string($p['prefix']) && preg_match('/^-?\d+$/', $p['prefix']) === 1) {
            $prefix = (int)$p['prefix'];
        } else {
            throw new \InvalidArgumentException('Field "prefix" must be an integer 0..128 (multiple of 4).');
        }
    }
    $arpa = ipv6_to_arpa($address, $prefix);
    return [
        'op'      => 'rdns6',
        'ok'      => true,
        'address' => $address,
        'prefix'  => $prefix ?? 128,
        'arpa'    => $arpa,
    ];
}

/**
 * @param array<string, mixed> $p
 * @return array<string, mixed>
 */
function _bulk_op_mapped6(array $p): array
{
    $input = isset($p['input']) && is_string($p['input']) ? trim($p['input']) : '';
    if ($input === '') {
        throw new \InvalidArgumentException('Field "input" is required.');
    }
    $prefix = NAT64_DEFAULT_PREFIX;
    if (array_key_exists('nat64_prefix', $p) && $p['nat64_prefix'] !== null && $p['nat64_prefix'] !== '') {
        if (!is_string($p['nat64_prefix'])) {
            throw new \InvalidArgumentException('Field "nat64_prefix" must be a string in /96 form.');
        }
        $prefix = trim($p['nat64_prefix']);
    }

    if (filter_var($input, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        $v4     = $input;
        $mapped = ipv4_to_mapped($v4);
        $nat64  = ipv4_to_nat64($v4, $prefix);
    } elseif (is_ipv4_mapped($input)) {
        $v4     = mapped_to_ipv4($input);
        $mapped = $input;
        $nat64  = ipv4_to_nat64($v4, $prefix);
    } elseif (is_nat64($input, $prefix)) {
        $v4     = nat64_to_ipv4($input, $prefix);
        $mapped = ipv4_to_mapped($v4);
        $nat64  = $input;
    } else {
        throw new \InvalidArgumentException(
            'Input is not IPv4, IPv4-mapped IPv6, or NAT64 IPv6 within the supplied prefix.'
        );
    }

    return [
        'op'           => 'mapped6',
        'ok'           => true,
        'input'        => $input,
        'ipv4'         => $v4,
        'ipv4_mapped'  => $mapped,
        'nat64'        => $nat64,
        'nat64_prefix' => $prefix,
    ];
}
