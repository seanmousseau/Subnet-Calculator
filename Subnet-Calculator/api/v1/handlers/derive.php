<?php

declare(strict_types=1);

if ($method !== 'POST') {
    json_err('Method not allowed.', 405);
}

$body = api_body();
$mac  = trim((string)($body['mac'] ?? ''));

try {
    $r = derive_from_mac($mac);
} catch (\InvalidArgumentException $e) {
    json_err($e->getMessage(), 400);
}

json_ok([
    'mac_canonical'  => $r['mac_canonical'],
    'eui64'          => $r['eui64'],
    'ul_bit_flipped' => $r['ul_bit_flipped'],
    'link_local'     => $r['link_local'],
    'solicited_node' => $r['solicited_node'],
    'warning'        => $r['warning'],
]);
