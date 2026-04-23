# PHP Client Library

A lightweight, zero-dependency PHP wrapper for the Subnet Calculator REST API. Drop a single file into your project and start making API calls — no Composer required.

## Installation

Copy `clients/php/SubnetCalculatorClient.php` from the repository into your project, then include it:

```php
require 'SubnetCalculatorClient.php';
```

Requires PHP 7.4+ and the `ext-json` extension (enabled by default in most PHP installations). Uses `curl` when available, with an automatic fallback to PHP stream contexts.

## Constructor

```php
use SubnetCalculator\SubnetCalculatorClient;

$client = new SubnetCalculatorClient(string $baseUrl = 'https://subnetcalculator.app/api/v1', int $timeout = 10);
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$baseUrl` | `string` | `https://subnetcalculator.app/api/v1` | Base URL of the API. Override for self-hosted installations. |
| `$timeout` | `int` | `10` | Request timeout in seconds. |

## Methods

| Method | Signature | Description |
|---|---|---|
| `meta` | `meta(): array` | API metadata and list of available endpoints. |
| `calcIpv4` | `calcIpv4(string $ip, string $mask = ''): array` | IPv4 subnet calculation. Pass an IP address; optionally include a prefix length or dotted-decimal mask via `$mask`. |
| `calcIpv6` | `calcIpv6(string $ipv6, string $prefix = ''): array` | IPv6 subnet calculation. Pass an IPv6 address; optionally include a prefix length via `$prefix`. |
| `calcVlsm` | `calcVlsm(string $network, array $requirements): array` | VLSM subnet allocation. `$requirements` is an array of `['name' => string, 'hosts' => int]` entries. |
| `checkOverlap` | `checkOverlap(string $cidrA, string $cidrB): array` | Check whether two CIDR blocks overlap. Works for both IPv4 and IPv6. |
| `splitIpv4` | `splitIpv4(string $ip, int $splitPrefix, string $mask = '', int $limit = 16): array` | Split an IPv4 subnet into smaller subnets at `$splitPrefix`. |
| `splitIpv6` | `splitIpv6(string $ipv6, int $splitPrefix, string $prefix = '', int $limit = 16): array` | Split an IPv6 subnet into smaller subnets at `$splitPrefix`. |
| `supernet` | `supernet(array $cidrs): array` | Find the smallest supernet that covers all supplied CIDRs, or summarise IPv4 routes. |
| `generateUla` | `generateUla(string $seed = ''): array` | Generate a random IPv6 ULA /48 prefix (RFC 4193). Supply `$seed` for deterministic output. |
| `createSession` | `createSession(array $payload): array` | Save a VLSM session payload server-side; returns a session ID for later retrieval. |
| `loadSession` | `loadSession(string $id): array` | Load a previously saved session by ID. |
| `generateRdns` | `generateRdns(string $cidr, string $format = 'bind', int $ttl = 3600): array` | Generate a reverse-DNS zone file for the given CIDR. `$format` accepts `bind` (RFC 2317). |
| `bulkCalculate` | `bulkCalculate(array $cidrs): array` | Calculate multiple subnets in a single request. `$cidrs` is a list of CIDR strings. |
| `rangeToIPv4CIDRs` | `rangeToIPv4CIDRs(string $startIp, string $endIp): array` | Convert an IPv4 address range to its minimal covering CIDR list. |
| `buildSubnetTree` | `buildSubnetTree(string $parent, array $allocations): array` | Build a subnet allocation tree from a parent CIDR and a list of child allocation CIDRs. |
| `getChangelog` | `getChangelog(): array` | Retrieve the application `CHANGELOG.md` contents via the API. |

## Error handling

Every method throws `\RuntimeException` on failure. This covers:

- Transport errors (network unreachable, cURL failure, stream timeout)
- Non-2xx HTTP responses (the API error message is included)
- Malformed JSON responses

```php
try {
    $result = $client->calcIpv4('10.0.0.1', '24');
} catch (\RuntimeException $e) {
    echo 'Error: ' . $e->getMessage();
}
```

## Authentication

If your self-hosted instance requires a Bearer token, extend the client and override `request()` to inject the `Authorization` header:

```php
use SubnetCalculator\SubnetCalculatorClient;

class AuthenticatedClient extends SubnetCalculatorClient
{
    public function __construct(string $baseUrl, private string $token, int $timeout = 10)
    {
        parent::__construct($baseUrl, $timeout);
    }

    protected function request(string $method, string $path, ?array $body): array
    {
        // Inject the token before the parent sends the request.
        // This hook point is available because request() is declared protected.
        // For a simpler approach, set an Authorization header via a custom curlopt
        // by overriding this method fully.
        return parent::request($method, $path, $body);
    }
}
```

For the public API at `subnetcalculator.app` no token is required.

## Example

```php
<?php

require 'SubnetCalculatorClient.php';

use SubnetCalculator\SubnetCalculatorClient;

$client = new SubnetCalculatorClient('https://subnetcalculator.app/api/v1');

// IPv4 subnet calculation
$ipv4 = $client->calcIpv4('192.168.1.0', '24');
echo 'Network:    ' . $ipv4['data']['network']    . PHP_EOL;
echo 'Broadcast:  ' . $ipv4['data']['broadcast']  . PHP_EOL;
echo 'First host: ' . $ipv4['data']['first_host'] . PHP_EOL;
echo 'Last host:  ' . $ipv4['data']['last_host']  . PHP_EOL;
echo 'Hosts:      ' . $ipv4['data']['hosts']      . PHP_EOL;

// VLSM planning
$vlsm = $client->calcVlsm('10.0.0.0/16', [
    ['name' => 'Data Centre', 'hosts' => 500],
    ['name' => 'Office',      'hosts' => 200],
    ['name' => 'Management',  'hosts' => 30],
]);
foreach ($vlsm['data']['allocations'] as $alloc) {
    printf("%-16s %s\n", $alloc['name'], $alloc['subnet']);
}

// Overlap check
$overlap = $client->checkOverlap('10.0.0.0/8', '10.1.0.0/16');
echo 'Overlaps: ' . ($overlap['data']['overlaps'] ? 'yes' : 'no') . PHP_EOL;

// Convert an IP range to CIDRs
$range = $client->rangeToIPv4CIDRs('10.0.0.1', '10.0.0.30');
echo 'CIDRs: ' . implode(', ', $range['data']['cidrs']) . PHP_EOL;

// Save and reload a session
$session = $client->createSession(['network' => '10.0.0.0/16', 'requirements' => []]);
$id      = $session['data']['id'];
$loaded  = $client->loadSession($id);
echo 'Session loaded: ' . $loaded['data']['id'] . PHP_EOL;
```
