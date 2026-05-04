<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Validates the published vlsm-session JSON schema structurally.
 *
 * v2 (v3.0.0, #315) introduced a `type` discriminator with three branches
 * (ipv4 / ipv6 / tree) under a top-level `oneOf`. The schema also gained
 * the `"2^N"` host-string form for IPv6 VLSM (parity with vlsm6_allocate's
 * accepted input).
 *
 * We intentionally do not pull in a full JSON-Schema validator dependency —
 * these checks catch the common breakage modes (missing $schema, broken
 * pattern, missing required field on an example) without the ~2 MB
 * transitive dependency surface that ships with opis/json-schema.
 */
class SchemaTest extends TestCase
{
    private const SCHEMA_PATH = __DIR__ . '/../../Subnet-Calculator/api/schemas/vlsm-session.schema.json';

    /** @var array<string, mixed> */
    private array $schema;

    protected function setUp(): void
    {
        $this->assertFileExists(self::SCHEMA_PATH, 'vlsm-session.schema.json must exist');
        $raw = file_get_contents(self::SCHEMA_PATH);
        $this->assertNotFalse($raw, 'schema file unreadable');
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'schema is not valid JSON');
        $this->schema = $decoded;
    }

    public function testDeclaresDraft202012Dialect(): void
    {
        $this->assertSame(
            'https://json-schema.org/draft/2020-12/schema',
            $this->schema['$schema'] ?? null
        );
    }

    public function testV2IdReflectsVersion(): void
    {
        $this->assertArrayHasKey('$id', $this->schema);
        $this->assertIsString($this->schema['$id']);
        $this->assertStringContainsString('v2', $this->schema['$id'], 'v2 schema must publish a v2 $id so pinned consumers do not break');
    }

    public function testTopLevelIsOneOfThreeBranches(): void
    {
        $this->assertArrayHasKey('oneOf', $this->schema);
        $branches = $this->schema['oneOf'];
        $this->assertCount(3, $branches, 'expect ipv4 / ipv6 / tree branches');
        $titles = array_column($branches, 'title');
        $this->assertContains('IPv4 VLSM', $titles);
        $this->assertContains('IPv6 VLSM', $titles);
        $this->assertContains('Subnet tree editor (v3.0.0 #302)', $titles);
    }

    public function testIpv4BranchKeepsLegacyShape(): void
    {
        $branch = $this->branchByTitle('IPv4 VLSM');
        $this->assertContains('network', $branch['required']);
        $this->assertContains('cidr', $branch['required']);
        $this->assertContains('requirements', $branch['required']);
        $this->assertSame(false, $branch['additionalProperties'] ?? null);
        // type is OPTIONAL for ipv4 (back-compat) — pre-v3 payloads omit it
        $this->assertNotContains('type', $branch['required']);
    }

    public function testIpv6BranchRequiresType(): void
    {
        $branch = $this->branchByTitle('IPv6 VLSM');
        $this->assertContains('type', $branch['required'], 'ipv6 branch must REQUIRE type discriminator');
        $this->assertSame('ipv6', $branch['properties']['type']['const']);
    }

    public function testTreeBranchRequiresTypeAndRoot(): void
    {
        $branch = $this->branchByTitle('Subnet tree editor (v3.0.0 #302)');
        $this->assertContains('type', $branch['required']);
        $this->assertContains('root', $branch['required']);
        $this->assertSame('tree', $branch['properties']['type']['const']);
    }

    public function testIpv6HostsAcceptsIntOrPowerOfTwoString(): void
    {
        $branch = $this->branchByTitle('IPv6 VLSM');
        $hosts  = $branch['properties']['requirements']['items']['properties']['hosts'];
        $this->assertArrayHasKey('oneOf', $hosts);
        $forms = array_column($hosts['oneOf'], 'type');
        $this->assertContains('integer', $forms);
        $this->assertContains('string', $forms);

        // Verify the 2^N regex
        $pattern = null;
        foreach ($hosts['oneOf'] as $f) {
            if (($f['type'] ?? null) === 'string') {
                $pattern = $f['pattern'];
                break;
            }
        }
        $this->assertNotNull($pattern);
        $regex = '/' . str_replace('/', '\\/', $pattern) . '/';
        foreach (['2^0', '2^1', '2^16', '2^63', '2^128'] as $valid) {
            $this->assertSame(1, preg_match($regex, $valid), "valid 2^N '$valid' should match");
        }
        foreach (['2^129', '2^999', '3^4', '2^', '16'] as $invalid) {
            $this->assertSame(0, preg_match($regex, $invalid), "invalid '$invalid' should not match");
        }
    }

    public function testIpv4CidrPattern(): void
    {
        $branch  = $this->branchByTitle('IPv4 VLSM');
        $pattern = $branch['properties']['cidr']['pattern'];
        $regex   = '/' . str_replace('/', '\\/', $pattern) . '/';
        foreach (['0', '8', '16', '24', '32'] as $valid) {
            $this->assertSame(1, preg_match($regex, $valid));
        }
        foreach (['33', '-1', 'abc', '/24', ' 24'] as $invalid) {
            $this->assertSame(0, preg_match($regex, $invalid));
        }
    }

    public function testIpv6CidrPattern(): void
    {
        $branch  = $this->branchByTitle('IPv6 VLSM');
        $pattern = $branch['properties']['cidr']['pattern'];
        $regex   = '/' . str_replace('/', '\\/', $pattern) . '/';
        foreach (['0', '48', '64', '127', '128'] as $valid) {
            $this->assertSame(1, preg_match($regex, $valid), "ipv6 cidr '$valid' should match");
        }
        foreach (['129', '-1', '/64', 'abc'] as $invalid) {
            $this->assertSame(0, preg_match($regex, $invalid));
        }
    }

    public function testTreeRootIsRecursiveDef(): void
    {
        $branch = $this->branchByTitle('Subnet tree editor (v3.0.0 #302)');
        $this->assertSame('#/$defs/treeNode', $branch['properties']['root']['$ref']);
        $this->assertArrayHasKey('treeNode', $this->schema['$defs']);
        $node = $this->schema['$defs']['treeNode'];
        $this->assertContains('cidr', $node['required']);
        // children is recursive
        $this->assertSame('#/$defs/treeNode', $node['properties']['children']['items']['$ref']);
    }

    public function testExamplesExistForEveryBranch(): void
    {
        $examples = $this->schema['examples'] ?? [];
        $this->assertNotEmpty($examples);
        $types = array_map(static fn ($e) => $e['type'] ?? 'ipv4', $examples);
        $this->assertContains('ipv4', $types);
        $this->assertContains('ipv6', $types);
        $this->assertContains('tree', $types);
    }

    public function testHandlerServesSchema(): void
    {
        $handler = __DIR__ . '/../../Subnet-Calculator/api/v1/handlers/schemas.php';
        $this->assertFileExists($handler);
        $code = file_get_contents($handler);
        $this->assertNotFalse($code);
        $this->assertStringContainsString('vlsm-session', (string)$code);
        $this->assertStringContainsString('application/schema+json', (string)$code);
    }

    /** @return array<string, mixed> */
    private function branchByTitle(string $title): array
    {
        foreach ($this->schema['oneOf'] as $branch) {
            if (($branch['title'] ?? '') === $title) {
                return $branch;
            }
        }
        $this->fail("No branch with title '$title'");
    }
}
