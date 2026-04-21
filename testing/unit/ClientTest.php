<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/Subnet-Calculator/clients/php/SubnetCalculatorClient.php';

use SubnetCalculator\SubnetCalculatorClient;

/**
 * Test double that intercepts request() so no network calls are made.
 */
class TestableClient extends SubnetCalculatorClient
{
    /** @var array<string, mixed> */
    private array $mockData = [];
    /** @var string */
    private string $capturedMethod = '';
    /** @var string */
    private string $capturedPath = '';
    /** @var array<string, mixed>|null */
    private ?array $capturedBody = null;

    /** @param array<string, mixed> $data */
    public function setMockResponse(array $data): void
    {
        $this->mockData = $data;
    }

    public function getCapturedMethod(): string  { return $this->capturedMethod; }
    public function getCapturedPath(): string    { return $this->capturedPath;   }

    /** @return array<string, mixed>|null */
    public function getCapturedBody(): ?array    { return $this->capturedBody;   }

    /**
     * Expose protected decode() for unit testing.
     *
     * @return array<string, mixed>
     */
    public function callDecode(string $body, int $httpCode, string $url): array
    {
        return $this->decode($body, $httpCode, $url);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    protected function request(string $method, string $path, ?array $body): array
    {
        $this->capturedMethod = $method;
        $this->capturedPath   = $path;
        $this->capturedBody   = $body;
        return $this->mockData;
    }
}

class ClientTest extends TestCase
{
    private TestableClient $client;

    protected function setUp(): void
    {
        $this->client = new TestableClient('https://example.com/api/v1');
        $this->client->setMockResponse(['ok' => true, 'data' => []]);
    }

    // ── Constructor ────────────────────────────────────────────────────────

    public function testConstructorStripsTrailingSlash(): void
    {
        $c = new TestableClient('https://example.com/api/v1/');
        $c->setMockResponse(['ok' => true, 'data' => []]);
        $c->meta();
        $this->assertSame('/', $c->getCapturedPath());
    }

    public function testConstructorDefaultUrl(): void
    {
        $c = new TestableClient();
        $c->setMockResponse(['ok' => true, 'data' => []]);
        $c->meta();
        $this->assertSame('/', $c->getCapturedPath());
    }

    // ── Endpoint routing ───────────────────────────────────────────────────

    public function testMetaSendsGet(): void
    {
        $this->client->meta();
        $this->assertSame('GET', $this->client->getCapturedMethod());
        $this->assertSame('/', $this->client->getCapturedPath());
        $this->assertNull($this->client->getCapturedBody());
    }

    public function testCalcIpv4SendsCorrectBody(): void
    {
        $this->client->calcIpv4('10.0.0.1', '24');
        $this->assertSame('POST', $this->client->getCapturedMethod());
        $this->assertSame('/ipv4', $this->client->getCapturedPath());
        $this->assertSame(['ip' => '10.0.0.1', 'mask' => '24'], $this->client->getCapturedBody());
    }

    public function testCalcIpv4OmitsMaskWhenEmpty(): void
    {
        $this->client->calcIpv4('10.0.0.1/24');
        $body = $this->client->getCapturedBody();
        $this->assertArrayNotHasKey('mask', $body ?? []);
    }

    public function testCalcIpv6SendsCorrectBody(): void
    {
        $this->client->calcIpv6('2001:db8::1', '32');
        $this->assertSame('/ipv6', $this->client->getCapturedPath());
        $this->assertSame(['ipv6' => '2001:db8::1', 'prefix' => '32'], $this->client->getCapturedBody());
    }

    public function testCalcVlsmSendsRequirements(): void
    {
        $reqs = [['name' => 'LAN-A', 'hosts' => 50], ['name' => 'LAN-B', 'hosts' => 20]];
        $this->client->calcVlsm('10.0.0.0/24', $reqs);
        $body = $this->client->getCapturedBody();
        $this->assertSame('10.0.0.0/24', $body['network'] ?? null);
        $this->assertSame($reqs, $body['requirements'] ?? null);
    }

    public function testCheckOverlapSendsBothCidrs(): void
    {
        $this->client->checkOverlap('10.0.0.0/24', '10.0.0.128/25');
        $body = $this->client->getCapturedBody();
        $this->assertSame('10.0.0.0/24', $body['cidr_a'] ?? null);
        $this->assertSame('10.0.0.128/25', $body['cidr_b'] ?? null);
    }

    public function testSupernetSendsCidrs(): void
    {
        $cidrs = ['10.0.0.0/25', '10.0.0.128/25'];
        $this->client->supernet($cidrs);
        $this->assertSame(['cidrs' => $cidrs], $this->client->getCapturedBody());
    }

    public function testLoadSessionBuildsPathWithId(): void
    {
        $this->client->loadSession('abc123');
        $this->assertSame('GET', $this->client->getCapturedMethod());
        $this->assertSame('/sessions/abc123', $this->client->getCapturedPath());
    }

    public function testBulkCalculateSendsCidrs(): void
    {
        $cidrs = ['192.168.1.0/24', '172.16.0.0/12'];
        $this->client->bulkCalculate($cidrs);
        $this->assertSame(['cidrs' => $cidrs], $this->client->getCapturedBody());
    }

    public function testRangeToIPv4CIDRsSendsIpRange(): void
    {
        $this->client->rangeToIPv4CIDRs('10.0.0.1', '10.0.0.254');
        $body = $this->client->getCapturedBody();
        $this->assertSame('10.0.0.1',  $body['start_ip'] ?? null);
        $this->assertSame('10.0.0.254', $body['end_ip'] ?? null);
    }

    // ── decode() error handling ────────────────────────────────────────────

    public function testDecodeThrowsOnHttp400(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/API error:/');
        $this->client->callDecode('{"ok":false,"error":"Bad Request"}', 400, 'http://x');
    }

    public function testDecodeThrowsWhenOkIsFalse(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->client->callDecode('{"ok":false,"error":"invalid input"}', 200, 'http://x');
    }

    public function testDecodeExtractsErrorMessage(): void
    {
        try {
            $this->client->callDecode('{"ok":false,"error":"Custom error"}', 422, 'http://x');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Custom error', $e->getMessage());
        }
    }

    public function testDecodeReturnsDataOnSuccess(): void
    {
        $result = $this->client->callDecode('{"ok":true,"data":{"network":"10.0.0.0"}}', 200, 'http://x');
        $this->assertTrue($result['ok']);
        $this->assertSame(['network' => '10.0.0.0'], $result['data']);
    }

    public function testDecodeThrowsOnNonJsonBody(): void
    {
        $this->expectException(\JsonException::class);
        $this->client->callDecode('not json', 200, 'http://x');
    }
}
