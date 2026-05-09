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
    // v3.4.0
    'range6',
    'supernet6',
    'zone-id',
    'derive',
    'slaac-privacy',
    'rdns6',
    'mapped6',
    // v3.5.0
    'embedded-v4',
    '6to4',
    'teredo',
    'isatap',
    '6rd',
    'nat64',
    'prefix-plan6',
    'nibble6',
    'rfc3531',
    // v3.6.0
    'multicast6',
    'ssm6',
    'embedded-rp6',
    'pmtu6',
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
            case 'embedded-v4':
                return _bulk_op_embedded_v4($params);
            case '6to4':
                return _bulk_op_6to4($params);
            case 'teredo':
                return _bulk_op_teredo($params);
            case 'isatap':
                return _bulk_op_isatap($params);
            case '6rd':
                return _bulk_op_6rd($params);
            case 'nat64':
                return _bulk_op_nat64($params);
            case 'prefix-plan6':
                return _bulk_op_prefix_plan6($params);
            case 'nibble6':
                return _bulk_op_nibble6($params);
            case 'rfc3531':
                return _bulk_op_rfc3531($params);
            case 'multicast6':
                return _bulk_op_multicast6($params);
            case 'ssm6':
                return _bulk_op_ssm6($params);
            case 'embedded-rp6':
                return _bulk_op_embedded_rp6($params);
            case 'pmtu6':
                return _bulk_op_pmtu6($params);
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

// ── v3.5.0 adapters ──────────────────────────────────────────────────────────

/**
 * @param array<string, mixed> $p
 * @return array<string, mixed>
 */
function _bulk_op_embedded_v4(array $p): array
{
    $input = isset($p['input']) && is_string($p['input']) ? trim($p['input']) : '';
    if ($input === '') {
        throw new \InvalidArgumentException('Field "input" is required.');
    }
    $r = detect_embedded_v4($input);
    return [
        'op'           => 'embedded-v4',
        'ok'           => true,
        'input'        => $input,
        'scheme'       => $r['scheme'],
        'ipv4'         => $r['ipv4'],
        'deprecated'   => $r['deprecated'],
        'detail_route' => $r['detail_route'],
        'extra'        => $r['extra'],
    ];
}

/**
 * @param array<string, mixed> $p
 * @return array<string, mixed>
 */
function _bulk_op_6to4(array $p): array
{
    $mode = isset($p['mode']) && is_string($p['mode']) ? strtolower(trim($p['mode'])) : 'encode';
    if ($mode === '') {
        $mode = 'encode';
    }
    if ($mode !== 'encode' && $mode !== 'decode') {
        throw new \InvalidArgumentException('Field "mode" must be "encode" or "decode".');
    }
    if ($mode === 'encode') {
        $v4 = isset($p['ipv4']) && is_string($p['ipv4']) ? trim($p['ipv4']) : '';
        if ($v4 === '') {
            throw new \InvalidArgumentException('Field "ipv4" is required when mode=encode.');
        }
        $prefix = ipv4_to_6to4($v4);
        return ['op' => '6to4', 'ok' => true, 'mode' => 'encode', 'ipv4' => $v4, 'prefix' => $prefix];
    }
    $v6 = isset($p['ipv6']) && is_string($p['ipv6']) ? trim($p['ipv6']) : '';
    if ($v6 === '') {
        throw new \InvalidArgumentException('Field "ipv6" is required when mode=decode.');
    }
    $r = decode_6to4($v6);
    return [
        'op'           => '6to4',
        'ok'           => true,
        'mode'         => 'decode',
        'ipv6'         => $v6,
        'ipv4'         => $r['ipv4'],
        'subnet_id'    => $r['subnet_id'],
        'interface_id' => $r['interface_id'],
    ];
}

/**
 * @param array<string, mixed> $p
 * @return array<string, mixed>
 */
function _bulk_op_teredo(array $p): array
{
    $mode = isset($p['mode']) && is_string($p['mode']) ? strtolower(trim($p['mode'])) : 'decode';
    if ($mode === '') {
        $mode = 'decode';
    }
    if ($mode !== 'encode' && $mode !== 'decode') {
        throw new \InvalidArgumentException('Field "mode" must be "encode" or "decode".');
    }
    if ($mode === 'decode') {
        $v6 = isset($p['ipv6']) && is_string($p['ipv6']) ? trim($p['ipv6']) : '';
        if ($v6 === '') {
            throw new \InvalidArgumentException('Field "ipv6" is required when mode=decode.');
        }
        $r = decode_teredo($v6);
        return [
            'op'          => 'teredo',
            'ok'          => true,
            'mode'        => 'decode',
            'ipv6'        => $v6,
            'server_ipv4' => $r['server_ipv4'],
            'flags'       => $r['flags'],
            'cone'        => $r['cone'],
            'port'        => $r['port'],
            'client_ipv4' => $r['client_ipv4'],
        ];
    }
    $server = isset($p['server_ipv4']) && is_string($p['server_ipv4']) ? trim($p['server_ipv4']) : '';
    $client = isset($p['client_ipv4']) && is_string($p['client_ipv4']) ? trim($p['client_ipv4']) : '';
    if ($server === '') {
        throw new \InvalidArgumentException('Field "server_ipv4" is required when mode=encode.');
    }
    if ($client === '') {
        throw new \InvalidArgumentException('Field "client_ipv4" is required when mode=encode.');
    }
    if (!isset($p['port']) || !is_int($p['port'])) {
        throw new \InvalidArgumentException('Field "port" (integer 0..65535) is required when mode=encode.');
    }
    $port  = $p['port'];
    $flags = 0x8000;
    if (array_key_exists('flags', $p)) {
        if (!is_int($p['flags'])) {
            throw new \InvalidArgumentException('Field "flags" must be an integer.');
        }
        if ($p['flags'] < 0 || $p['flags'] > 0xFFFF) {
            throw new \InvalidArgumentException('Field "flags" must be in 16-bit range (0..65535).');
        }
        $flags = $p['flags'];
    }
    $addr = encode_teredo($server, $client, $port, $flags);
    return [
        'op'          => 'teredo',
        'ok'          => true,
        'mode'        => 'encode',
        'server_ipv4' => $server,
        'client_ipv4' => $client,
        'port'        => $port,
        'flags'       => $flags & 0xFFFF,
        'cone'        => ($flags & 0x8000) !== 0,
        'ipv6'        => $addr,
    ];
}

/**
 * @param array<string, mixed> $p
 * @return array<string, mixed>
 */
function _bulk_op_isatap(array $p): array
{
    $mode = isset($p['mode']) && is_string($p['mode']) ? strtolower(trim($p['mode'])) : 'encode';
    if ($mode === '') {
        $mode = 'encode';
    }
    if ($mode !== 'encode' && $mode !== 'decode') {
        throw new \InvalidArgumentException('Field "mode" must be "encode" or "decode".');
    }
    if ($mode === 'encode') {
        $v4 = isset($p['ipv4']) && is_string($p['ipv4']) ? trim($p['ipv4']) : '';
        if ($v4 === '') {
            throw new \InvalidArgumentException('Field "ipv4" is required when mode=encode.');
        }
        $globally_unique = null;
        if (array_key_exists('globally_unique', $p)) {
            if (!is_bool($p['globally_unique'])) {
                throw new \InvalidArgumentException('Field "globally_unique" must be a boolean.');
            }
            $globally_unique = $p['globally_unique'];
        }
        $iid = ipv4_to_isatap_iid($v4, $globally_unique);
        $decoded = decode_isatap_iid($iid);
        return [
            'op'              => 'isatap',
            'ok'              => true,
            'mode'            => 'encode',
            'ipv4'            => $v4,
            'iid'             => $iid,
            'globally_unique' => $decoded['globally_unique'],
        ];
    }
    $iid = isset($p['iid']) && is_string($p['iid']) ? trim($p['iid']) : '';
    if ($iid === '') {
        throw new \InvalidArgumentException('Field "iid" is required when mode=decode.');
    }
    $r = decode_isatap_iid($iid);
    return [
        'op'              => 'isatap',
        'ok'              => true,
        'mode'            => 'decode',
        'iid'             => $iid,
        'ipv4'            => $r['ipv4'],
        'globally_unique' => $r['globally_unique'],
    ];
}

/**
 * @param array<string, mixed> $p
 * @return array<string, mixed>
 */
function _bulk_op_6rd(array $p): array
{
    $mode = isset($p['mode']) && is_string($p['mode']) ? strtolower(trim($p['mode'])) : 'encode';
    if ($mode === '') {
        $mode = 'encode';
    }
    if ($mode !== 'encode' && $mode !== 'decode') {
        throw new \InvalidArgumentException('Field "mode" must be "encode" or "decode".');
    }
    $sp = isset($p['sp_ipv6_prefix']) && is_string($p['sp_ipv6_prefix']) ? trim($p['sp_ipv6_prefix']) : '';
    if ($sp === '') {
        throw new \InvalidArgumentException('Field "sp_ipv6_prefix" is required.');
    }
    $mask = 0;
    if (array_key_exists('sp_ipv4_mask_len', $p)) {
        if (!is_int($p['sp_ipv4_mask_len'])) {
            throw new \InvalidArgumentException('Field "sp_ipv4_mask_len" must be an integer 0..32.');
        }
        if ($p['sp_ipv4_mask_len'] < 0 || $p['sp_ipv4_mask_len'] > 32) {
            throw new \InvalidArgumentException('Field "sp_ipv4_mask_len" must be 0..32.');
        }
        $mask = $p['sp_ipv4_mask_len'];
    }
    if ($mode === 'encode') {
        $v4 = isset($p['customer_ipv4']) && is_string($p['customer_ipv4']) ? trim($p['customer_ipv4']) : '';
        if ($v4 === '' && isset($p['ipv4']) && is_string($p['ipv4'])) {
            $v4 = trim($p['ipv4']);
        }
        if ($v4 === '') {
            throw new \InvalidArgumentException('Field "customer_ipv4" (or "ipv4") is required when mode=encode.');
        }
        $r = compute_6rd_delegation($sp, $mask, $v4);
        return [
            'op'               => '6rd',
            'ok'               => true,
            'mode'             => 'encode',
            'sp_ipv6_prefix'   => $sp,
            'sp_ipv4_mask_len' => $mask,
            'ipv4'             => $v4,
            'prefix'           => $r['prefix'],
            'prefix_length'    => $r['prefix_length'],
        ];
    }
    $addr = isset($p['rd_ipv6_address']) && is_string($p['rd_ipv6_address']) ? trim($p['rd_ipv6_address']) : '';
    if ($addr === '' && isset($p['ipv6']) && is_string($p['ipv6'])) {
        $addr = trim($p['ipv6']);
    }
    if ($addr === '') {
        throw new \InvalidArgumentException('Field "rd_ipv6_address" (or "ipv6") is required when mode=decode.');
    }
    $v4 = extract_6rd_ipv4($sp, $mask, $addr);
    return [
        'op'               => '6rd',
        'ok'               => true,
        'mode'             => 'decode',
        'sp_ipv6_prefix'   => $sp,
        'sp_ipv4_mask_len' => $mask,
        'ipv6'             => $addr,
        'ipv4'             => $v4,
    ];
}

/**
 * @param array<string, mixed> $p
 * @return array<string, mixed>
 */
function _bulk_op_nat64(array $p): array
{
    $mode = isset($p['mode']) && is_string($p['mode']) ? strtolower(trim($p['mode'])) : 'encode';
    if ($mode === '') {
        $mode = 'encode';
    }
    if ($mode !== 'encode' && $mode !== 'decode' && $mode !== 'dns64') {
        throw new \InvalidArgumentException('Field "mode" must be "encode", "decode", or "dns64".');
    }
    $prefix = NAT64_WELL_KNOWN_PREFIX;
    if (array_key_exists('nat64_prefix', $p) && $p['nat64_prefix'] !== null && $p['nat64_prefix'] !== '') {
        if (!is_string($p['nat64_prefix'])) {
            throw new \InvalidArgumentException('Field "nat64_prefix" must be a string.');
        }
        $prefix = trim($p['nat64_prefix']);
    }
    $pl = 96;
    if (array_key_exists('prefix_length', $p) && $p['prefix_length'] !== null) {
        if (!is_int($p['prefix_length'])) {
            throw new \InvalidArgumentException(
                'Field "prefix_length" must be an integer (32, 40, 48, 56, 64, or 96).'
            );
        }
        $pl = $p['prefix_length'];
    }
    if ($mode === 'encode' || $mode === 'dns64') {
        $field = $mode === 'dns64' ? 'a_record' : 'ipv4';
        $raw   = isset($p[$field]) && is_string($p[$field]) ? trim($p[$field]) : '';
        if ($raw === '') {
            throw new \InvalidArgumentException("Field \"{$field}\" is required when mode={$mode}.");
        }
        $ipv6 = $mode === 'dns64'
            ? dns64_synthesize($raw, $prefix, $pl)
            : nat64_embed($raw, $prefix, $pl);
        return [
            'op'            => 'nat64',
            'ok'            => true,
            'mode'          => $mode,
            'nat64_prefix'  => $prefix,
            'prefix_length' => $pl,
            'ipv4'          => $raw,
            'ipv6'          => $ipv6,
        ];
    }
    $v6 = isset($p['ipv6']) && is_string($p['ipv6']) ? trim($p['ipv6']) : '';
    if ($v6 === '') {
        throw new \InvalidArgumentException('Field "ipv6" is required when mode=decode.');
    }
    $v4 = nat64_extract($v6, $prefix, $pl);
    return [
        'op'            => 'nat64',
        'ok'            => true,
        'mode'          => 'decode',
        'nat64_prefix'  => $prefix,
        'prefix_length' => $pl,
        'ipv6'          => $v6,
        'ipv4'          => $v4,
    ];
}

/**
 * @param array<string, mixed> $p
 * @return array<string, mixed>
 */
function _bulk_op_prefix_plan6(array $p): array
{
    $parent = isset($p['parent_prefix']) && is_string($p['parent_prefix']) ? trim($p['parent_prefix']) : '';
    if ($parent === '') {
        throw new \InvalidArgumentException('Field "parent_prefix" is required.');
    }
    if (
        !isset($p['child_length']) || !is_int($p['child_length'])
        || $p['child_length'] < 1 || $p['child_length'] > 128
    ) {
        throw new \InvalidArgumentException('Field "child_length" (integer 1..128) is required.');
    }
    $cap = isset($GLOBALS['prefix_plan6_max_count']) && is_int($GLOBALS['prefix_plan6_max_count'])
        ? $GLOBALS['prefix_plan6_max_count']
        : 256;
    if (!isset($p['count']) || !is_int($p['count']) || $p['count'] < 1 || $p['count'] > $cap) {
        throw new \InvalidArgumentException(sprintf('Field "count" (integer 1..%d) is required.', $cap));
    }
    $start = 0;
    if (array_key_exists('start_offset', $p)) {
        if (!is_int($p['start_offset']) || $p['start_offset'] < 0) {
            throw new \InvalidArgumentException('Field "start_offset" must be a non-negative integer.');
        }
        $start = $p['start_offset'];
    }
    $align = true;
    if (array_key_exists('nibble_align', $p)) {
        if (!is_bool($p['nibble_align'])) {
            throw new \InvalidArgumentException('Field "nibble_align" must be a boolean.');
        }
        $align = $p['nibble_align'];
    }
    $r = plan_prefix_delegation($parent, $p['child_length'], $p['count'], $start, $align);
    return [
        'op'                      => 'prefix-plan6',
        'ok'                      => true,
        'parent'                  => $r['parent'],
        'children'                => $r['children'],
        'free'                    => $r['free'],
        'normalized_child_length' => $r['normalized_child_length'],
    ];
}

/**
 * @param array<string, mixed> $p
 * @return array<string, mixed>
 */
function _bulk_op_nibble6(array $p): array
{
    $prefix = isset($p['prefix']) && is_string($p['prefix']) ? trim($p['prefix']) : '';
    if ($prefix === '') {
        throw new \InvalidArgumentException('Field "prefix" is required.');
    }
    $r = nibble_neighbours($prefix);
    return [
        'op'    => 'nibble6',
        'ok'    => true,
        'input' => $r['input'],
        'above' => $r['above'],
        'below' => $r['below'],
    ];
}

/**
 * @param array<string, mixed> $p
 * @return array<string, mixed>
 */
function _bulk_op_rfc3531(array $p): array
{
    $parent = isset($p['parent_prefix']) && is_string($p['parent_prefix']) ? trim($p['parent_prefix']) : '';
    if ($parent === '') {
        throw new \InvalidArgumentException('Field "parent_prefix" is required.');
    }
    $cap = isset($GLOBALS['rfc3531_max_bits']) && is_int($GLOBALS['rfc3531_max_bits'])
        ? $GLOBALS['rfc3531_max_bits']
        : 8;
    if ($cap < 1 || $cap > 12) {
        $cap = 8;
    }
    if (
        !isset($p['reservation_bits']) || !is_int($p['reservation_bits'])
        || $p['reservation_bits'] < 1 || $p['reservation_bits'] > $cap
    ) {
        throw new \InvalidArgumentException(
            sprintf('Field "reservation_bits" (integer 1..%d) is required.', $cap)
        );
    }
    $strategy = isset($p['strategy']) && is_string($p['strategy']) ? $p['strategy'] : 'centermost';
    if (!in_array($strategy, ['leftmost', 'centermost', 'rightmost'], true)) {
        throw new \InvalidArgumentException('Field "strategy" must be one of "leftmost", "centermost", "rightmost".');
    }
    $r = rfc3531_apply($parent, $p['reservation_bits'], $strategy);
    return [
        'op'               => 'rfc3531',
        'ok'               => true,
        'parent'           => $r['parent'],
        'strategy'         => $r['strategy'],
        'reservation_bits' => $r['reservation_bits'],
        'allocation_order' => $r['allocation_order'],
        'children'         => $r['children'],
    ];
}

// ── v3.6.0 adapters ──────────────────────────────────────────────────────────

/**
 * @param array<string, mixed> $p
 * @return array<string, mixed>
 */
function _bulk_op_multicast6(array $p): array
{
    $input = isset($p['ipv6']) && is_string($p['ipv6']) ? trim($p['ipv6']) : '';
    if ($input === '') {
        throw new \InvalidArgumentException('Field "ipv6" is required.');
    }
    $r = decode_multicast($input);
    return [
        'op'           => 'multicast6',
        'ok'           => true,
        'input'        => $input,
        'address'      => $r['address'],
        'scope'        => $r['scope'],
        'scope_name'   => $r['scope_name'],
        'flags'        => $r['flags'],
        'transient'    => $r['transient'],
        'prefix_based' => $r['prefix_based'],
        'embedded_rp'  => $r['embedded_rp'],
        'group_id'     => $r['group_id'],
        'scheme'       => $r['scheme'],
        'detail_route' => $r['detail_route'],
        'well_known'   => $r['well_known'],
    ];
}

/**
 * @param array<string, mixed> $p
 * @return array<string, mixed>
 */
function _bulk_op_ssm6(array $p): array
{
    $mode = isset($p['mode']) && is_string($p['mode']) ? strtolower(trim($p['mode'])) : 'encode';
    if ($mode === '') {
        $mode = 'encode';
    }
    if ($mode !== 'encode' && $mode !== 'decode') {
        throw new \InvalidArgumentException('Field "mode" must be "encode" or "decode".');
    }

    if ($mode === 'encode') {
        $prefix = isset($p['unicast_prefix']) && is_string($p['unicast_prefix'])
            ? trim($p['unicast_prefix']) : '';
        if ($prefix === '') {
            throw new \InvalidArgumentException('Field "unicast_prefix" is required when mode=encode.');
        }
        if (!isset($p['scope']) || !is_int($p['scope'])) {
            throw new \InvalidArgumentException('Field "scope" (integer 1..15) is required when mode=encode.');
        }
        if (!isset($p['group_id']) || !is_int($p['group_id'])) {
            throw new \InvalidArgumentException(
                'Field "group_id" (32-bit unsigned integer) is required when mode=encode.'
            );
        }
        if ($p['group_id'] < 0 || $p['group_id'] > 0xFFFFFFFF) {
            throw new \InvalidArgumentException('Field "group_id" must be 0..2^32-1.');
        }
        $r = build_ssm_group($prefix, $p['scope'], $p['group_id']);
        return [
            'op'             => 'ssm6',
            'ok'             => true,
            'mode'           => 'encode',
            'address'        => $r['address'],
            'scope'          => $r['scope'],
            'prefix_length'  => $r['prefix_length'],
            'unicast_prefix' => $r['unicast_prefix'],
            'group_id'       => $r['group_id'],
        ];
    }

    $v6 = isset($p['ipv6']) && is_string($p['ipv6']) ? trim($p['ipv6']) : '';
    if ($v6 === '') {
        throw new \InvalidArgumentException('Field "ipv6" is required when mode=decode.');
    }
    $r = decode_ssm_group($v6);
    return [
        'op'             => 'ssm6',
        'ok'             => true,
        'mode'           => 'decode',
        'input'          => $v6,
        'address'        => $r['address'],
        'scope'          => $r['scope'],
        'prefix_length'  => $r['prefix_length'],
        'unicast_prefix' => $r['unicast_prefix'],
        'group_id'       => $r['group_id'],
    ];
}

/**
 * @param array<string, mixed> $p
 * @return array<string, mixed>
 */
function _bulk_op_embedded_rp6(array $p): array
{
    $mode = isset($p['mode']) && is_string($p['mode']) ? strtolower(trim($p['mode'])) : 'encode';
    if ($mode === '') {
        $mode = 'encode';
    }
    if ($mode !== 'encode' && $mode !== 'decode') {
        throw new \InvalidArgumentException('Field "mode" must be "encode" or "decode".');
    }

    if ($mode === 'encode') {
        $rp_addr = isset($p['rp_address']) && is_string($p['rp_address']) ? trim($p['rp_address']) : '';
        if ($rp_addr === '') {
            throw new \InvalidArgumentException('Field "rp_address" is required when mode=encode.');
        }
        if (!isset($p['rp_prefix_length']) || !is_int($p['rp_prefix_length'])) {
            throw new \InvalidArgumentException(
                'Field "rp_prefix_length" (integer 0..64) is required when mode=encode.'
            );
        }
        if (!isset($p['riid']) || !is_int($p['riid'])) {
            throw new \InvalidArgumentException('Field "riid" (integer 0..15) is required when mode=encode.');
        }
        if (!isset($p['scope']) || !is_int($p['scope'])) {
            throw new \InvalidArgumentException('Field "scope" (integer 1..15) is required when mode=encode.');
        }
        if (!isset($p['group_id']) || !is_int($p['group_id'])) {
            throw new \InvalidArgumentException(
                'Field "group_id" (32-bit unsigned integer) is required when mode=encode.'
            );
        }
        if ($p['group_id'] < 0 || $p['group_id'] > 0xFFFFFFFF) {
            throw new \InvalidArgumentException('Field "group_id" must be 0..2^32-1.');
        }
        $r = build_embedded_rp_group(
            $rp_addr,
            $p['rp_prefix_length'],
            $p['riid'],
            $p['scope'],
            $p['group_id']
        );
        return [
            'op'               => 'embedded-rp6',
            'ok'               => true,
            'mode'             => 'encode',
            'address'          => $r['address'],
            'scope'            => $r['scope'],
            'rp_prefix'        => $r['rp_prefix'],
            'rp_prefix_length' => $r['rp_prefix_length'],
            'rp_address'       => $r['rp_address'],
            'riid'             => $r['riid'],
            'group_id'         => $r['group_id'],
        ];
    }

    $v6 = isset($p['ipv6']) && is_string($p['ipv6']) ? trim($p['ipv6']) : '';
    if ($v6 === '') {
        throw new \InvalidArgumentException('Field "ipv6" is required when mode=decode.');
    }
    $r = decode_embedded_rp_group($v6);
    return [
        'op'               => 'embedded-rp6',
        'ok'               => true,
        'mode'             => 'decode',
        'input'            => $v6,
        'address'          => $r['address'],
        'scope'            => $r['scope'],
        'rp_prefix'        => $r['rp_prefix'],
        'rp_prefix_length' => $r['rp_prefix_length'],
        'rp_address'       => $r['rp_address'],
        'riid'             => $r['riid'],
        'group_id'         => $r['group_id'],
    ];
}

/**
 * @param array<string, mixed> $p
 * @return array<string, mixed>
 */
function _bulk_op_pmtu6(array $p): array
{
    if (!isset($p['path_mtu']) || !is_int($p['path_mtu'])) {
        throw new \InvalidArgumentException('Field "path_mtu" (positive integer) is required.');
    }
    if (!isset($p['payload_size']) || !is_int($p['payload_size'])) {
        throw new \InvalidArgumentException('Field "payload_size" (non-negative integer) is required.');
    }
    $ext = [];
    if (array_key_exists('extension_headers', $p) && $p['extension_headers'] !== null) {
        if (!is_array($p['extension_headers'])) {
            throw new \InvalidArgumentException('Field "extension_headers" must be an array of integers.');
        }
        foreach ($p['extension_headers'] as $h) {
            if (!is_int($h)) {
                throw new \InvalidArgumentException('Field "extension_headers" must be an array of integers.');
            }
            $ext[] = $h;
        }
    }
    $r = pmtu_compute($p['path_mtu'], $p['payload_size'], $ext);
    return [
        'op'                  => 'pmtu6',
        'ok'                  => true,
        'path_mtu'            => $r['path_mtu'],
        'meets_minimum'       => $r['meets_minimum'],
        'fixed_header'        => $r['fixed_header'],
        'extension_overhead'  => $r['extension_overhead'],
        'total_overhead'      => $r['total_overhead'],
        'effective_payload'   => $r['effective_payload'],
        'payload_size'        => $r['payload_size'],
        'needs_fragmentation' => $r['needs_fragmentation'],
        'fragment_count'      => $r['fragment_count'],
        'fragments'           => $r['fragments'],
        'notes'               => $r['notes'],
    ];
}
