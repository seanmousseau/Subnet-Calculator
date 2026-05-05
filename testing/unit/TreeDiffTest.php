<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Validates tree_diff() — the pure tree-comparison helper added in v3.1.0
 * (#322).  The diff is computed client-side in app.js for the modal; the PHP
 * function is the canonical reference and the surface tested here.
 *
 * Contract:
 *   tree_diff(array $a, array $b): array{
 *       added:   list<array{cidr:string,name?:string,notes?:string}>,
 *       removed: list<array{cidr:string,name?:string,notes?:string}>,
 *       changed: list<array{cidr:string,kind:string,before:mixed,after:mixed}>,
 *   }
 *
 * "kind" ∈ {'prefix','rename','notes'} — one entry per kind per network.
 */
class TreeDiffTest extends TestCase
{
    /**
     * @param array<string,mixed> $root
     * @return array<string,mixed>
     */
    private function tree(array $root): array
    {
        return ['type' => 'tree', 'root' => $root];
    }

    public function testIdenticalTreesProduceEmptyDiff(): void
    {
        $a = $this->tree([
            'cidr' => '10.0.0.0/24',
            'children' => [
                ['cidr' => '10.0.0.0/25'],
                ['cidr' => '10.0.0.128/25'],
            ],
        ]);
        $diff = tree_diff($a, $a);
        $this->assertSame([], $diff['added']);
        $this->assertSame([], $diff['removed']);
        $this->assertSame([], $diff['changed']);
    }

    public function testPureAddition(): void
    {
        $a = $this->tree(['cidr' => '10.0.0.0/24']);
        $b = $this->tree([
            'cidr' => '10.0.0.0/24',
            'children' => [
                ['cidr' => '10.0.0.0/25'],
                ['cidr' => '10.0.0.128/25', 'name' => 'DC4'],
            ],
        ]);
        $diff = tree_diff($a, $b);
        $cidrs = array_map(fn(array $n): string => (string)$n['cidr'], $diff['added']);
        sort($cidrs);
        $this->assertSame(['10.0.0.0/25', '10.0.0.128/25'], $cidrs);
        $this->assertSame([], $diff['removed']);
        $this->assertSame([], $diff['changed']);
    }

    public function testPureRemoval(): void
    {
        $a = $this->tree([
            'cidr' => '10.0.0.0/24',
            'children' => [
                ['cidr' => '10.0.0.0/25'],
                ['cidr' => '10.0.0.128/25'],
            ],
        ]);
        $b = $this->tree(['cidr' => '10.0.0.0/24']);
        $diff = tree_diff($a, $b);
        $this->assertSame([], $diff['added']);
        $cidrs = array_map(fn(array $n): string => (string)$n['cidr'], $diff['removed']);
        sort($cidrs);
        $this->assertSame(['10.0.0.0/25', '10.0.0.128/25'], $cidrs);
        $this->assertSame([], $diff['changed']);
    }

    public function testPrefixChange(): void
    {
        // Same network 10.0.0.0, prefix /24 → /23.
        $a = $this->tree(['cidr' => '10.0.0.0/24']);
        $b = $this->tree(['cidr' => '10.0.0.0/23']);
        $diff = tree_diff($a, $b);
        $this->assertSame([], $diff['added']);
        $this->assertSame([], $diff['removed']);
        $this->assertCount(1, $diff['changed']);
        $entry = $diff['changed'][0];
        $this->assertSame('prefix', $entry['kind']);
        $this->assertSame('10.0.0.0/24', $entry['before']);
        $this->assertSame('10.0.0.0/23', $entry['after']);
    }

    public function testRenameOnly(): void
    {
        $a = $this->tree(['cidr' => '10.0.0.0/24', 'name' => 'DMZ']);
        $b = $this->tree(['cidr' => '10.0.0.0/24', 'name' => 'Edge']);
        $diff = tree_diff($a, $b);
        $this->assertSame([], $diff['added']);
        $this->assertSame([], $diff['removed']);
        $this->assertCount(1, $diff['changed']);
        $this->assertSame('rename', $diff['changed'][0]['kind']);
        $this->assertSame('DMZ',  $diff['changed'][0]['before']);
        $this->assertSame('Edge', $diff['changed'][0]['after']);
        $this->assertSame('10.0.0.0/24', $diff['changed'][0]['cidr']);
    }

    public function testNotesOnly(): void
    {
        $a = $this->tree(['cidr' => '10.0.0.0/24', 'notes' => 'old note']);
        $b = $this->tree(['cidr' => '10.0.0.0/24', 'notes' => 'new note']);
        $diff = tree_diff($a, $b);
        $this->assertSame([], $diff['added']);
        $this->assertSame([], $diff['removed']);
        $this->assertCount(1, $diff['changed']);
        $this->assertSame('notes',    $diff['changed'][0]['kind']);
        $this->assertSame('old note', $diff['changed'][0]['before']);
        $this->assertSame('new note', $diff['changed'][0]['after']);
    }

    public function testIpv6Trees(): void
    {
        $a = $this->tree([
            'cidr' => '2001:db8::/32',
            'children' => [
                ['cidr' => '2001:db8::/33'],
                ['cidr' => '2001:db8:8000::/33'],
            ],
        ]);
        $b = $this->tree([
            'cidr' => '2001:db8::/32',
            'children' => [
                ['cidr' => '2001:db8::/33', 'name' => 'east'],
                // 2001:db8:8000::/33 removed; 2001:db8:c000::/34 added.
                ['cidr' => '2001:db8:c000::/34'],
            ],
        ]);
        $diff = tree_diff($a, $b);
        $added_cidrs = array_map(fn(array $n): string => (string)$n['cidr'], $diff['added']);
        $removed_cidrs = array_map(fn(array $n): string => (string)$n['cidr'], $diff['removed']);
        $this->assertSame(['2001:db8:c000::/34'], $added_cidrs);
        $this->assertSame(['2001:db8:8000::/33'], $removed_cidrs);
        // The east-named change should be a 'rename' on 2001:db8::/33.
        $kinds_by_cidr = [];
        foreach ($diff['changed'] as $c) {
            $kinds_by_cidr[(string)$c['cidr']][] = (string)$c['kind'];
        }
        $this->assertArrayHasKey('2001:db8::/33', $kinds_by_cidr);
        $this->assertContains('rename', $kinds_by_cidr['2001:db8::/33']);
    }

    public function testCanonicalFormDifference(): void
    {
        // 10.0.0.5/24 and 10.0.0.0/24 canonicalise to the same network.
        $a = $this->tree(['cidr' => '10.0.0.5/24']);
        $b = $this->tree(['cidr' => '10.0.0.0/24']);
        $diff = tree_diff($a, $b);
        $this->assertSame([], $diff['added']);
        $this->assertSame([], $diff['removed']);
        $this->assertSame([], $diff['changed']);
    }
}
