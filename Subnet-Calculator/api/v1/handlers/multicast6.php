<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body      = api_body();
$raw_input = $body['ipv6'] ?? null;

if (!is_string($raw_input) || trim($raw_input) === '') {
    json_err('Field "ipv6" (string) is required.', 400);
}
$input = trim($raw_input);

try {
    $result = decode_multicast($input);
} catch (\InvalidArgumentException $e) {
    json_err($e->getMessage(), 400);
}

json_ok([
    'input'        => $input,
    'address'      => $result['address'],
    'scope'        => $result['scope'],
    'scope_name'   => $result['scope_name'],
    'flags'        => $result['flags'],
    'transient'    => $result['transient'],
    'prefix_based' => $result['prefix_based'],
    'embedded_rp'  => $result['embedded_rp'],
    'group_id'     => $result['group_id'],
    'scheme'       => $result['scheme'],
    'detail_route' => $result['detail_route'],
    'well_known'   => $result['well_known'],
]);
