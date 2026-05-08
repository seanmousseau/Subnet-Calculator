<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body         = api_body();
$raw_input    = $body['input']        ?? null;
$raw_prefix   = $body['nat64_prefix'] ?? null;

if (!is_string($raw_input) || trim($raw_input) === '') {
    json_err('Field "input" (string) is required.', 400);
}
$input = trim($raw_input);

$prefix = NAT64_DEFAULT_PREFIX;
if ($raw_prefix !== null && $raw_prefix !== '') {
    if (!is_string($raw_prefix)) {
        json_err('Field "nat64_prefix" must be a string in /96 form.', 400);
    }
    $prefix = trim($raw_prefix);
}

try {
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
        json_err(
            'Input is not IPv4, IPv4-mapped IPv6, or NAT64 IPv6 within the supplied prefix.',
            400
        );
    }
} catch (\InvalidArgumentException $e) {
    json_err($e->getMessage(), 400);
}

json_ok([
    'input'        => $input,
    'ipv4'         => $v4,
    'ipv4_mapped'  => $mapped,
    'nat64'        => $nat64,
    'nat64_prefix' => $prefix,
]);
