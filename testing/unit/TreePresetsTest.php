<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Validates the tree-preset library (#323, v3.1.0).
 *
 * Covers: manifest listing, single-preset load, path-traversal rejection,
 * schema-shape validation, end-to-end "apply preset → tree_validate()" replay.
 */
class TreePresetsTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function expectedStarterIds(): array
    {
        return [
            'flat-equal-split-24',
            'hq-branches-16',
            'ipv6-site-48',
            'ipv6-three-tier-48',
            'lan-dmz-mgmt-24',
            'three-tier-24',
        ];
    }

    public function testListReturnsAllSixStarterPresets(): void
    {
        $manifest = tree_presets_list();
        $ids = array_map(static fn(array $p): string => (string)$p['id'], $manifest);
        sort($ids);
        $this->assertSame($this->expectedStarterIds(), $ids);

        // Each manifest entry has the lightweight summary fields the picker
        // expects — and *not* the heavy `operations` list.
        foreach ($manifest as $entry) {
            $this->assertArrayHasKey('name', $entry);
            $this->assertArrayHasKey('family', $entry);
            $this->assertArrayHasKey('rootPrefix', $entry);
            $this->assertArrayHasKey('operationCount', $entry);
            $this->assertArrayNotHasKey('operations', $entry);
            $this->assertContains($entry['family'], ['ipv4', 'ipv6']);
        }
    }

    public function testLoadValidPreset(): void
    {
        $preset = tree_preset_load('lan-dmz-mgmt-24');
        $this->assertIsArray($preset);
        $this->assertSame('lan-dmz-mgmt-24', $preset['id']);
        $this->assertSame('ipv4', $preset['family']);
        $this->assertSame(24, $preset['rootPrefix']);
        $this->assertIsArray($preset['operations']);
        $this->assertGreaterThanOrEqual(1, count($preset['operations']));
        // First op should be a split.
        $first = $preset['operations'][0];
        $this->assertSame('split', $first['op']);
        $this->assertSame(4, $first['into']);
    }

    public function testLoadInvalidIdReturnsNull(): void
    {
        $this->assertNull(tree_preset_load('does-not-exist'));
    }

    public function testIdValidationRejectsPathTraversal(): void
    {
        // Each of these would escape the presets directory if not guarded.
        $this->assertNull(tree_preset_load('../../../etc/passwd'));
        $this->assertNull(tree_preset_load('..'));
        $this->assertNull(tree_preset_load('/etc/passwd'));
        $this->assertNull(tree_preset_load(''));
        $this->assertNull(tree_preset_load('UPPERCASE'));
        $this->assertNull(tree_preset_load('with spaces'));
        $this->assertNull(tree_preset_load('semi;colon'));
    }

    /**
     * Replay each starter preset's operations starting from the bundled root
     * CIDR, then assert the resulting tree validates via tree_validate().
     */
    public function testStarterPresetExpandsToValidTree(): void
    {
        foreach ($this->expectedStarterIds() as $id) {
            $preset = tree_preset_load($id);
            $this->assertIsArray($preset, "load failed for $id");
            $this->assertArrayHasKey('rootCidr', $preset, "no rootCidr on $id");
            $rootCidr = (string)$preset['rootCidr'];

            $tree = ['cidr' => $rootCidr];
            foreach ($preset['operations'] as $op) {
                $tree = $this->applyOp($tree, (array)$op, (string)$preset['family']);
            }

            try {
                tree_validate(['type' => 'tree', 'root' => $tree]);
            } catch (\InvalidArgumentException $e) {
                $this->fail("Preset $id produced invalid tree: " . $e->getMessage());
            }
            $this->addToAssertionCount(1);
        }
    }

    public function testSchemaIsValidJsonSchema(): void
    {
        $path = dirname(__DIR__, 2) . '/Subnet-Calculator/api/schemas/tree-preset.schema.json';
        $this->assertFileExists($path);
        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('$schema', $decoded);
        $this->assertArrayHasKey('$id', $decoded);
        $this->assertSame('object', $decoded['type'] ?? null);
        $this->assertArrayHasKey('properties', $decoded);
        $this->assertArrayHasKey('operations', $decoded['properties']);
        // operations.items uses oneOf to discriminate split vs rename.
        $this->assertArrayHasKey('oneOf', $decoded['properties']['operations']['items']);
    }

    /**
     * Apply a single operation to the tree.  Mirrors the JS reducer's SPLIT /
     * RENAME branches closely enough to validate the data shape; this is
     * test-only — production replay happens in app.js dispatch().
     *
     * @param array<string,mixed> $node
     * @param array<string,mixed> $op
     * @return array<string,mixed>
     */
    private function applyOp(array $node, array $op, string $family): array
    {
        $kind = $op['op'] ?? '';
        $targetCidr = (string)($op['cidr'] ?? '');

        if ($kind === 'split') {
            $into = (int)$op['into'];
            return $this->mutateNode($node, $targetCidr, function (array $n) use ($into, $family): array {
                $children = $this->splitInto((string)$n['cidr'], $into, $family);
                $n['children'] = array_map(static fn(string $c): array => ['cidr' => $c], $children);
                return $n;
            });
        }
        if ($kind === 'rename') {
            $name = (string)$op['name'];
            $notes = isset($op['notes']) ? (string)$op['notes'] : null;
            return $this->mutateNode($node, $targetCidr, function (array $n) use ($name, $notes): array {
                $n['name'] = $name;
                if ($notes !== null && $notes !== '') {
                    $n['notes'] = $notes;
                }
                return $n;
            });
        }
        return $node;
    }

    /**
     * Walk the tree and apply $fn to the node whose cidr matches.
     *
     * @param array<string,mixed> $node
     * @param callable(array<string,mixed>):array<string,mixed> $fn
     * @return array<string,mixed>
     */
    private function mutateNode(array $node, string $cidr, callable $fn): array
    {
        if (($node['cidr'] ?? '') === $cidr) {
            return $fn($node);
        }
        if (isset($node['children']) && is_array($node['children'])) {
            $kids = [];
            foreach ($node['children'] as $child) {
                if (is_array($child)) {
                    $kids[] = $this->mutateNode($child, $cidr, $fn);
                }
            }
            $node['children'] = $kids;
        }
        return $node;
    }

    /**
     * @return list<string>
     */
    private function splitInto(string $cidr, int $count, string $family): array
    {
        $parts = explode('/', $cidr, 2);
        $ip = $parts[0];
        $px = (int)$parts[1];
        $bits = (int)log($count, 2);
        $newPx = $px + $bits;
        $totalBits = $family === 'ipv6' ? 128 : 32;
        $shift = $totalBits - $newPx;

        $out = [];
        if ($family === 'ipv4') {
            $start = ip2long($ip) & 0xFFFFFFFF;
            $stride = $shift >= 32 ? 0 : (1 << $shift);
            for ($i = 0; $i < $count; $i++) {
                $addr = ($start + $i * $stride) & 0xFFFFFFFF;
                $ipStr = long2ip((int)$addr);
                $out[] = $ipStr . '/' . $newPx;
            }
            return $out;
        }
        // IPv6 via GMP.
        $bin = inet_pton($ip);
        if ($bin === false) {
            return [];
        }
        $g = gmp_init(bin2hex($bin), 16);
        $stride = gmp_pow(2, $shift);
        for ($i = 0; $i < $count; $i++) {
            $cur = gmp_add($g, gmp_mul($stride, $i));
            $hex = str_pad(gmp_strval($cur, 16), 32, '0', STR_PAD_LEFT);
            $packed = hex2bin($hex);
            if ($packed === false) {
                continue;
            }
            $ipStr = inet_ntop($packed);
            if ($ipStr === false) {
                continue;
            }
            $out[] = $ipStr . '/' . $newPx;
        }
        return $out;
    }
}
