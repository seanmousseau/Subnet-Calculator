<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Validates published JSON-Schema documents structurally.
 *
 * Confirms the file parses as JSON, declares the Draft 2020-12 dialect,
 * has a stable $id, and that every advertised example matches the schema's
 * own `required` / `properties` declarations.
 *
 * We intentionally do not pull in a full JSON-Schema validator dependency —
 * these checks catch the common breakage modes (missing $schema, broken
 * pattern, missing required field on an example) without the ~2 MB transitive
 * dependency surface that ships with justinrainbow/json-schema or opis/json-schema.
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
            $this->schema['$schema'] ?? null,
            'Must declare Draft 2020-12'
        );
    }

    public function testHasStableId(): void
    {
        $this->assertArrayHasKey('$id', $this->schema);
        $this->assertIsString($this->schema['$id']);
        $this->assertStringStartsWith('https://', $this->schema['$id']);
    }

    public function testTopLevelStructure(): void
    {
        $this->assertSame('object', $this->schema['type'] ?? null);
        $this->assertContains('network', $this->schema['required'] ?? []);
        $this->assertContains('cidr', $this->schema['required'] ?? []);
        $this->assertContains('requirements', $this->schema['required'] ?? []);
        $this->assertSame(false, $this->schema['additionalProperties'] ?? null);
    }

    public function testRequirementsItemSchema(): void
    {
        $items = $this->schema['properties']['requirements']['items'] ?? null;
        $this->assertIsArray($items);
        $this->assertSame('object', $items['type'] ?? null);
        $this->assertContains('name', $items['required'] ?? []);
        $this->assertContains('hosts', $items['required'] ?? []);
        $this->assertSame('integer', $items['properties']['hosts']['type'] ?? null);
        $this->assertSame(1, $items['properties']['hosts']['minimum'] ?? null);
    }

    public function testCidrPatternMatchesValidPrefixes(): void
    {
        $pattern = $this->schema['properties']['cidr']['pattern'] ?? '';
        $this->assertNotSame('', $pattern, 'cidr must declare a pattern');
        $regex = '/' . str_replace('/', '\\/', $pattern) . '/';
        foreach (['0', '8', '16', '24', '32'] as $valid) {
            $this->assertSame(1, preg_match($regex, $valid), "valid cidr '$valid' should match");
        }
        foreach (['33', '-1', 'abc', '/24', ' 24', '99'] as $invalid) {
            $this->assertSame(0, preg_match($regex, $invalid), "invalid cidr '$invalid' should not match");
        }
    }

    public function testExamplesMatchOwnSchema(): void
    {
        $examples = $this->schema['examples'] ?? [];
        $this->assertNotEmpty($examples, 'schema must ship at least one example');
        foreach ($examples as $idx => $ex) {
            $this->assertIsArray($ex, "example $idx must be an object");
            foreach (['network', 'cidr', 'requirements'] as $key) {
                $this->assertArrayHasKey($key, $ex, "example $idx missing $key");
            }
            $this->assertIsString($ex['network']);
            $this->assertIsString($ex['cidr']);
            $this->assertIsArray($ex['requirements']);
            $this->assertNotEmpty($ex['requirements']);
            foreach ($ex['requirements'] as $r) {
                $this->assertIsString($r['name']);
                $this->assertIsInt($r['hosts']);
                $this->assertGreaterThanOrEqual(1, $r['hosts']);
            }
        }
    }

    public function testHandlerServesSchema(): void
    {
        $handler = __DIR__ . '/../../Subnet-Calculator/api/v1/handlers/schemas.php';
        $this->assertFileExists($handler);
        $code = file_get_contents($handler);
        $this->assertNotFalse($code);
        $this->assertStringContainsString('vlsm-session', (string)$code, 'handler must allowlist vlsm-session');
        $this->assertStringContainsString('application/schema+json', (string)$code, 'handler must set schema MIME type');
    }
}
