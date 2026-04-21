<?php

declare(strict_types=1);

namespace SubnetCalculator;

/**
 * Subnet Calculator API client — zero dependencies, single file.
 * Generated from api/openapi.yaml.  Requires PHP 7.4+ and ext-json.
 *
 * Usage:
 *   $client = new SubnetCalculatorClient('https://subnetcalculator.app/api/v1');
 *   $result = $client->calcIpv4('10.0.0.1', '24');
 */
class SubnetCalculatorClient
{
    private string $baseUrl;
    private int $timeout;

    public function __construct(string $baseUrl = 'https://subnetcalculator.app/api/v1', int $timeout = 10)
    {
        $this->baseUrl  = rtrim($baseUrl, '/');
        $this->timeout  = $timeout;
    }

    // ── Endpoints ─────────────────────────────────────────────────────────

    /**
     * API metadata and available endpoints.
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->get('/');
    }

    /**
     * IPv4 subnet calculation.
     *
     * @return array<string, mixed>
     */
    public function calcIpv4(string $ip, string $mask = ''): array
    {
        $body = ['ip' => $ip];
        if ($mask !== '') {
            $body['mask'] = $mask;
        }
        return $this->post('/ipv4', $body);
    }

    /**
     * IPv6 subnet calculation.
     *
     * @return array<string, mixed>
     */
    public function calcIpv6(string $ipv6, string $prefix = ''): array
    {
        $body = ['ipv6' => $ipv6];
        if ($prefix !== '') {
            $body['prefix'] = $prefix;
        }
        return $this->post('/ipv6', $body);
    }

    /**
     * VLSM subnet allocation.
     *
     * @param array<array{name: string, hosts: int}> $requirements
     * @return array<string, mixed>
     */
    public function calcVlsm(string $network, array $requirements): array
    {
        return $this->post('/vlsm', ['network' => $network, 'requirements' => $requirements]);
    }

    /**
     * Subnet overlap check (IPv4 or IPv6).
     *
     * @return array<string, mixed>
     */
    public function checkOverlap(string $cidrA, string $cidrB): array
    {
        return $this->post('/overlap', ['cidr_a' => $cidrA, 'cidr_b' => $cidrB]);
    }

    /**
     * Split an IPv4 subnet into smaller subnets.
     *
     * @return array<string, mixed>
     */
    public function splitIpv4(string $ip, int $splitPrefix, string $mask = '', int $limit = 16): array
    {
        $body = ['ip' => $ip, 'split_prefix' => $splitPrefix, 'limit' => $limit];
        if ($mask !== '') {
            $body['mask'] = $mask;
        }
        return $this->post('/split/ipv4', $body);
    }

    /**
     * Split an IPv6 subnet into smaller subnets.
     *
     * @return array<string, mixed>
     */
    public function splitIpv6(string $ipv6, int $splitPrefix, string $prefix = '', int $limit = 16): array
    {
        $body = ['ipv6' => $ipv6, 'split_prefix' => $splitPrefix, 'limit' => $limit];
        if ($prefix !== '') {
            $body['prefix'] = $prefix;
        }
        return $this->post('/split/ipv6', $body);
    }

    /**
     * Find supernet or summarise IPv4 routes.
     *
     * @param string[] $cidrs
     * @return array<string, mixed>
     */
    public function supernet(array $cidrs): array
    {
        return $this->post('/supernet', ['cidrs' => $cidrs]);
    }

    /**
     * Generate an IPv6 ULA /48 prefix (RFC 4193).
     *
     * @return array<string, mixed>
     */
    public function generateUla(string $seed = ''): array
    {
        $body = $seed !== '' ? ['seed' => $seed] : [];
        return $this->post('/ula', $body);
    }

    /**
     * Save a VLSM session payload; returns session ID.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createSession(array $payload): array
    {
        return $this->post('/sessions', ['payload' => $payload]);
    }

    /**
     * Load a saved session by ID.
     *
     * @return array<string, mixed>
     */
    public function loadSession(string $id): array
    {
        return $this->get('/sessions/' . rawurlencode($id));
    }

    /**
     * Generate a reverse-DNS zone file (BIND / RFC 2317).
     *
     * @return array<string, mixed>
     */
    public function generateRdns(string $cidr, string $format = 'bind', int $ttl = 3600): array
    {
        return $this->post('/rdns', ['cidr' => $cidr, 'format' => $format, 'ttl' => $ttl]);
    }

    /**
     * Calculate multiple subnets in a single request.
     *
     * @param string[] $cidrs
     * @return array<string, mixed>
     */
    public function bulkCalculate(array $cidrs): array
    {
        return $this->post('/bulk', ['cidrs' => $cidrs]);
    }

    /**
     * Convert an IPv4 address range to its minimal covering CIDR list.
     *
     * @return array<string, mixed>
     */
    public function rangeToIPv4CIDRs(string $startIp, string $endIp): array
    {
        return $this->post('/range/ipv4', ['start_ip' => $startIp, 'end_ip' => $endIp]);
    }

    /**
     * Build a subnet allocation tree from a parent CIDR and child allocations.
     *
     * @param string[] $allocations
     * @return array<string, mixed>
     */
    public function buildSubnetTree(string $parent, array $allocations): array
    {
        return $this->post('/tree', ['parent' => $parent, 'allocations' => $allocations]);
    }

    /**
     * Get CHANGELOG.md contents.
     *
     * @return array<string, mixed>
     */
    public function getChangelog(): array
    {
        return $this->get('/changelog');
    }

    // ── HTTP transport ─────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        return $this->request('GET', $path, null);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     * @throws \RuntimeException on transport or API error
     */
    protected function request(string $method, string $path, ?array $body): array
    {
        $url  = $this->baseUrl . $path;
        $json = $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null;

        if (function_exists('curl_init')) {
            return $this->curlRequest($method, $url, $json);
        }

        return $this->streamRequest($method, $url, $json);
    }

    /**
     * @return array<string, mixed>
     */
    private function curlRequest(string $method, string $url, ?string $json): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        $headers = ['Accept: application/json'];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($json);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => ($method === 'POST'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FAILONERROR    => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("cURL error: $curlErr");
        }

        return $this->decode((string) $response, $httpCode, $url);
    }

    /**
     * @return array<string, mixed>
     */
    private function streamRequest(string $method, string $url, ?string $json): array
    {
        $opts = [
            'http' => [
                'method'        => $method,
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
                'header'        => ['Accept: application/json'],
            ],
        ];

        if ($json !== null) {
            $opts['http']['header'][] = 'Content-Type: application/json';
            $opts['http']['content']  = $json;
        }

        $ctx      = stream_context_create($opts);
        $response = @file_get_contents($url, false, $ctx);

        if ($response === false) {
            throw new \RuntimeException("Request to $url failed");
        }

        // PHP 8.5+ provides http_get_last_response_headers(); fall back to $http_response_header on older PHP
        $responseHeaders = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : $http_response_header;
        $httpCode = 200;
        foreach ($responseHeaders as $header) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $header, $m)) {
                $httpCode = (int) $m[1];
            }
        }

        return $this->decode($response, $httpCode, $url);
    }

    /**
     * @return array<string, mixed>
     * @throws \RuntimeException
     */
    protected function decode(string $body, int $httpCode, string $url): array
    {
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new \RuntimeException("Unexpected response from $url (HTTP $httpCode)");
        }

        if ($httpCode >= 400 || (isset($data['ok']) && $data['ok'] === false)) {
            $errMsg = isset($data['error']) && is_string($data['error'])
                ? $data['error']
                : "HTTP $httpCode";
            throw new \RuntimeException("API error: $errMsg");
        }

        return $data;
    }
}
