<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * HTTP-level smoke tests for the REST API v1.
 *
 * These tests call the API directly via file_get_contents + stream_context.
 * They require a running web server at the URL specified by API_BASE_URL.
 *
 * The tests are skipped automatically when:
 *   - API_BASE_URL env var is not set, OR
 *   - allow_url_fopen is disabled in php.ini
 *
 * Run with the full suite:
 *   API_BASE_URL=https://dev-direct.seanmousseau.com:8343/claude/subnet-calculator/api/v1/ \
 *   vendor/bin/phpunit
 */
class ApiTest extends TestCase
{
    private static string $base = '';
    private static string $authUser = '';
    private static string $authPass = '';

    public static function setUpBeforeClass(): void
    {
        $url = (string)(getenv('API_BASE_URL') ?: '');
        if ($url === '') {
            return;
        }
        self::$base     = rtrim($url, '/') . '/';
        self::$authUser = (string)(getenv('IPAM_BASIC_USER') ?: '');
        self::$authPass = (string)(getenv('IPAM_BASIC_PASS') ?: '');
    }

    private function requireBase(): void
    {
        if (self::$base === '') {
            $this->markTestSkipped('API_BASE_URL not set — skipping HTTP smoke tests.');
        }
        if (!ini_get('allow_url_fopen')) {
            $this->markTestSkipped('allow_url_fopen is disabled — skipping HTTP smoke tests.');
        }
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, data:mixed}
     */
    private function post(string $path, array $body): array
    {
        $json = json_encode($body);
        $ctx  = stream_context_create([
            'http' => [
                'method'           => 'POST',
                'header'           => implode("\r\n", [
                    'Content-Type: application/json',
                    'Authorization: Basic ' . base64_encode(self::$authUser . ':' . self::$authPass),
                ]),
                'content'          => $json !== false ? $json : '{}',
                'ignore_errors'    => true,
                'timeout'          => 10,
            ],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $raw  = file_get_contents(self::$base . ltrim($path, '/'), false, $ctx);
        $status = 0;
        $response_headers = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : (isset($http_response_header) && is_array($http_response_header) ? $http_response_header : []);
        if (is_array($response_headers) && $response_headers !== []) {
            preg_match('/HTTP\/\S+\s+(\d+)/', $response_headers[0] ?? '', $m);
            $status = isset($m[1]) ? (int)$m[1] : 0;
        }
        return ['status' => $status, 'data' => json_decode((string)$raw, true)];
    }

    /**
     * @return array{status:int, data:mixed}
     */
    private function get(string $path): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => 'Authorization: Basic ' . base64_encode(self::$authUser . ':' . self::$authPass),
                'ignore_errors' => true,
                'timeout'       => 10,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $raw  = file_get_contents(self::$base . ltrim($path, '/'), false, $ctx);
        $status = 0;
        $response_headers = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : (isset($http_response_header) && is_array($http_response_header) ? $http_response_header : []);
        if (is_array($response_headers) && $response_headers !== []) {
            preg_match('/HTTP\/\S+\s+(\d+)/', $response_headers[0] ?? '', $m);
            $status = isset($m[1]) ? (int)$m[1] : 0;
        }
        return ['status' => $status, 'data' => json_decode((string)$raw, true)];
    }

    public function testMetaEndpoint(): void
    {
        $this->requireBase();
        $r = $this->get('/');
        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['data']['ok'] ?? false);
        $this->assertIsArray($r['data']['data']['endpoints'] ?? null);
    }

    public function testIpv4Calculation(): void
    {
        $this->requireBase();
        $r = $this->post('/ipv4', ['ip' => '10.0.0.1', 'mask' => '24']);
        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['data']['ok'] ?? false);
        $this->assertSame('10.0.0.0/24', $r['data']['data']['network_cidr'] ?? '');
        $this->assertSame('255.255.255.0', $r['data']['data']['netmask'] ?? '');
    }

    public function testIpv4MissingIpReturns400(): void
    {
        $this->requireBase();
        $r = $this->post('/ipv4', []);
        $this->assertSame(400, $r['status']);
        $this->assertFalse($r['data']['ok'] ?? true);
    }

    public function testIpv6Calculation(): void
    {
        $this->requireBase();
        $r = $this->post('/ipv6', ['ipv6' => '2001:db8::', 'prefix' => '32']);
        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['data']['ok'] ?? false);
        $this->assertSame('2001:db8::/32', $r['data']['data']['network_cidr'] ?? '');
    }

    public function testVlsmAllocation(): void
    {
        $this->requireBase();
        $r = $this->post('/vlsm', [
            'network'      => '10.0.0.0',
            'cidr'         => '24',
            'requirements' => [
                ['name' => 'LAN', 'hosts' => 50],
                ['name' => 'DMZ', 'hosts' => 10],
            ],
        ]);
        $this->assertSame(200, $r['status']);
        $allocs = $r['data']['data']['allocations'] ?? [];
        $this->assertCount(2, $allocs);
        $this->assertArrayHasKey('subnet', $allocs[0]);
    }

    public function testOverlapContains(): void
    {
        $this->requireBase();
        $r = $this->post('/overlap', ['cidr_a' => '10.0.0.0/24', 'cidr_b' => '10.0.0.128/25']);
        $this->assertSame(200, $r['status']);
        $this->assertSame('a_contains_b', $r['data']['data']['relation'] ?? '');
    }

    public function testOverlapNone(): void
    {
        $this->requireBase();
        $r = $this->post('/overlap', ['cidr_a' => '10.0.0.0/24', 'cidr_b' => '192.168.1.0/24']);
        $this->assertSame(200, $r['status']);
        $this->assertSame('none', $r['data']['data']['relation'] ?? '');
    }

    public function testSplitIpv4(): void
    {
        $this->requireBase();
        $r = $this->post('/split/ipv4', ['ip' => '10.0.0.0', 'mask' => '24', 'split_prefix' => 26]);
        $this->assertSame(200, $r['status']);
        $this->assertSame(4, $r['data']['data']['total'] ?? 0);
        $this->assertSame('10.0.0.0/26', $r['data']['data']['subnets'][0] ?? '');
    }

    public function testSupernet(): void
    {
        $this->requireBase();
        $r = $this->post('/supernet', ['cidrs' => ['10.0.0.0/24', '10.0.1.0/24'], 'action' => 'find']);
        $this->assertSame(200, $r['status']);
        $this->assertSame('10.0.0.0/23', $r['data']['data']['supernet'] ?? '');
    }

    public function testSummarise(): void
    {
        $this->requireBase();
        // /25 is contained in /24 → removed; the two /24s are siblings → merge to /23
        $r = $this->post('/supernet', [
            'cidrs'  => ['10.0.0.0/24', '10.0.0.0/25', '10.0.1.0/24'],
            'action' => 'summarise',
        ]);
        $this->assertSame(200, $r['status']);
        $this->assertCount(1, $r['data']['data']['summaries'] ?? []);
    }

    public function testUla(): void
    {
        $this->requireBase();
        $r = $this->post('/ula', ['global_id' => 'aabbccddee']);
        $this->assertSame(200, $r['status']);
        $prefix = $r['data']['data']['prefix'] ?? '';
        $this->assertStringEndsWith('/48', $prefix);
        $this->assertStringStartsWith('fd', strtolower($prefix));
    }

    // ── v2.2.0 new fields ─────────────────────────────────────────────────────

    public function testIpv4ResponseIncludesNetworkHexAndDecimal(): void
    {
        $this->requireBase();
        $r = $this->post('/ipv4', ['ip' => '192.168.1.0', 'mask' => '24']);
        $this->assertSame(200, $r['status']);
        $data = $r['data']['data'] ?? [];
        // network_hex: dotted-hex of 192.168.1.0 = C0.A8.01.00
        $this->assertArrayHasKey('network_hex', $data);
        $this->assertSame('C0.A8.01.00', $data['network_hex']);
        // network_decimal: unsigned 32-bit integer
        $this->assertArrayHasKey('network_decimal', $data);
        $this->assertSame(3232235776, $data['network_decimal']);
    }

    public function testIpv6ResponseIncludesAddressForms(): void
    {
        $this->requireBase();
        $r = $this->post('/ipv6', ['ipv6' => '2001:db8::', 'prefix' => '32']);
        $this->assertSame(200, $r['status']);
        $data = $r['data']['data'] ?? [];
        $this->assertArrayHasKey('address_expanded', $data);
        $this->assertArrayHasKey('address_compressed', $data);
        $this->assertSame('2001:0db8:0000:0000:0000:0000:0000:0000', $data['address_expanded']);
        $this->assertSame('2001:db8::', $data['address_compressed']);
    }

    public function testIpv4CalculationAtClassBBoundary(): void
    {
        $this->requireBase();
        // 10.0.0.0/8 — verify hex/decimal for a class A network
        $r = $this->post('/ipv4', ['ip' => '10.5.6.7', 'mask' => '8']);
        $this->assertSame(200, $r['status']);
        $data = $r['data']['data'] ?? [];
        $this->assertSame('0A.00.00.00', $data['network_hex'] ?? '');
        $this->assertSame(167772160, $data['network_decimal'] ?? 0);
    }
}
