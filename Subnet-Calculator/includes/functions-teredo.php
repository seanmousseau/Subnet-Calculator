<?php

declare(strict_types=1);

// Teredo address tool (RFC 4380) — bidirectional decoding/encoding of
// IPv6 addresses in the 2001:0::/32 prefix.
//
//   | 32 bits | 32 bits      | 16 bits | 16 bits      | 32 bits          |
//   | 2001:0  | server IPv4  | flags   | port (XOR)   | client IPv4 (XOR)|
//
// The high-bit of `flags` (0x8000) is the "cone" indicator. Port and
// client IPv4 are obfuscated by XOR-ing every bit with 1 (i.e. XOR
// 0xFFFF for the 16-bit port, XOR 0xFFFFFFFF byte-by-byte for the
// 32-bit client v4) so that NAT devices won't rewrite the embedded
// values when they see them inside the IPv6 payload. Server IPv4 is
// stored raw because Teredo servers terminate the relay tunnel and
// don't need protection from upstream NAT mangling.
//
// RFC 4380 was published in 2006 and Teredo deployment has steadily
// declined as native IPv6 has rolled out; Microsoft retired the
// public Teredo service for consumer Windows in 2019. This tool is
// provided for analysis of historical traffic captures and audits of
// addresses that still appear in legacy logs.

const TEREDO_PREFIX_BIN = "\x20\x01\x00\x00";

/**
 * Decode a Teredo IPv6 address per RFC 4380.
 *
 * @return array{
 *     server_ipv4: string,
 *     flags: int,
 *     cone: bool,
 *     port: int,
 *     client_ipv4: string,
 * }
 *
 * @throws InvalidArgumentException if the address is malformed or not
 *         under 2001:0::/32.
 */
function decode_teredo(string $ipv6): array
{
    $raw = trim($ipv6);
    if ($raw === '') {
        throw new InvalidArgumentException('IPv6 address is required.');
    }

    $bin = @inet_pton($raw);
    if ($bin === false || strlen($bin) !== 16) {
        throw new InvalidArgumentException('Invalid IPv6 address: ' . $raw);
    }

    if (substr($bin, 0, 4) !== TEREDO_PREFIX_BIN) {
        throw new InvalidArgumentException(
            'Address is not under 2001:0::/32 (Teredo): ' . $raw
        );
    }

    $serverV4 = @inet_ntop(substr($bin, 4, 4));
    if ($serverV4 === false) {
        throw new InvalidArgumentException('Internal error decoding server IPv4.');
    }

    $flags = (ord($bin[8]) << 8) | ord($bin[9]);
    $cone  = ($flags & 0x8000) !== 0;

    $port = ((ord($bin[10]) << 8) | ord($bin[11])) ^ 0xFFFF;

    // Client IPv4: XOR each byte with 0xFF.
    $clientPlain = '';
    for ($i = 12; $i < 16; $i++) {
        $clientPlain .= chr(ord($bin[$i]) ^ 0xFF);
    }
    $clientV4 = @inet_ntop($clientPlain);
    if ($clientV4 === false) {
        throw new InvalidArgumentException('Internal error decoding client IPv4.');
    }

    return [
        'server_ipv4' => $serverV4,
        'flags'       => $flags,
        'cone'        => $cone,
        'port'        => $port,
        'client_ipv4' => $clientV4,
    ];
}

/**
 * Encode a Teredo IPv6 address from its parts.
 *
 * @throws InvalidArgumentException if either IPv4 is malformed or port
 *         is outside 0..65535.
 */
function encode_teredo(string $server_ipv4, string $client_ipv4, int $port, int $flags = 0x8000): string
{
    $server = trim($server_ipv4);
    $client = trim($client_ipv4);

    if ($port < 0 || $port > 65535) {
        throw new InvalidArgumentException('Port must be between 0 and 65535: ' . $port);
    }

    if (filter_var($server, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        throw new InvalidArgumentException('Invalid server IPv4 address: ' . $server);
    }
    if (filter_var($client, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        throw new InvalidArgumentException('Invalid client IPv4 address: ' . $client);
    }

    $serverBin = inet_pton($server);
    $clientBin = inet_pton($client);
    if ($serverBin === false || strlen($serverBin) !== 4) {
        throw new InvalidArgumentException('Invalid server IPv4 address: ' . $server);
    }
    if ($clientBin === false || strlen($clientBin) !== 4) {
        throw new InvalidArgumentException('Invalid client IPv4 address: ' . $client);
    }

    // XOR the client v4 byte-by-byte with 0xFF.
    $clientObf = '';
    for ($i = 0; $i < 4; $i++) {
        $clientObf .= chr(ord($clientBin[$i]) ^ 0xFF);
    }

    // XOR the port with 0xFFFF (16-bit big-endian).
    $portObf = $port ^ 0xFFFF;

    // Mask flags into 16 bits — defensive; callers commonly pass 0 or 0x8000.
    $flags &= 0xFFFF;

    $packed = TEREDO_PREFIX_BIN
        . $serverBin
        . chr(($flags >> 8) & 0xFF) . chr($flags & 0xFF)
        . chr(($portObf >> 8) & 0xFF) . chr($portObf & 0xFF)
        . $clientObf;

    $addr = @inet_ntop($packed);
    if ($addr === false) {
        throw new InvalidArgumentException('Internal error encoding Teredo address.');
    }
    return $addr;
}
