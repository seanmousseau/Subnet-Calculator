<?php

declare(strict_types=1);

/**
 * IPv6 address classification helpers.
 *
 * Extracted from functions-util.php in v3.4.0 (architecture item C).
 * Owns pure classification logic (loopback, ULA, link-local,
 * multicast, GUA, IPv4-mapped, NAT64, etc.). The address-type
 * *badge* renderer stays in functions-util.php.
 *
 * Public function names unchanged.
 */

function get_ipv6_type(string $ip): string
{
    $bin = inet_pton($ip);
    if ($bin === false) {
        return 'Unknown';
    }
    $unpacked = unpack('C*', $bin);
    if ($unpacked === false || count($unpacked) < 16) {
        return 'Unknown';
    }
    $b = array_values($unpacked);
    if ($bin === str_repeat("\x00", 15) . "\x01") {
        return 'Loopback';
    }
    if ($bin === str_repeat("\x00", 16)) {
        return 'Unspecified';
    }
    if (substr($bin, 0, 10) === str_repeat("\x00", 10) && substr($bin, 10, 2) === "\xff\xff") {
        return 'IPv4-mapped';
    }
    if ($b[0] === 0xFF) {
        return 'Multicast';
    }
    if ($b[0] === 0xFE && ($b[1] & 0xC0) === 0x80) {
        return 'Link-local';
    }
    if (($b[0] & 0xFE) === 0xFC) {
        return 'Unique Local';
    }
    if ($b[0] === 0x20 && $b[1] === 0x01 && $b[2] === 0x0D && $b[3] === 0xB8) {
        return 'Documentation';
    }
    if ($b[0] === 0x20 && $b[1] === 0x01 && $b[2] === 0x00 && $b[3] === 0x00) {
        return 'Teredo';
    }
    if ($b[0] === 0x20 && $b[1] === 0x02) {
        return '6to4';
    }
    if ($b[0] === 0x00 && $b[1] === 0x64 && $b[2] === 0xFF && $b[3] === 0x9B) {
        if ($b[4] === 0x00 && $b[5] === 0x01) {
            return 'NAT64 (local)';
        }
                                                               return 'NAT64';
    }
    if (($b[0] & 0xE0) === 0x20) {
        return 'Global Unicast';
    }
    return 'Unknown';
}
