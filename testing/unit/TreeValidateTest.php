<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Validates tree_validate() — the server-side rule enforcement for
 * `type: 'tree'` session payloads (v3.0.0, #302).
 *
 * The schema (vlsm-session.schema.json v2) is permissive on the tree branch
 * because it has to coexist with v2 from PR1; the runtime contract is enforced
 * by tree_validate() and exercised here.
 */
class TreeValidateTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function payload(array $root): array
    {
        return ['type' => 'tree', 'root' => $root];
    }

    public function testRejectsWrongType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        tree_validate(['type' => 'ipv4', 'root' => ['cidr' => '10.0.0.0/24']]);
    }

    public function testRejectsMissingRoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        tree_validate(['type' => 'tree']);
    }

    public function testAcceptsLeafIpv4(): void
    {
        tree_validate($this->payload(['cidr' => '10.0.0.0/24']));
        $this->assertTrue(true); // no throw = pass
    }

    public function testAcceptsLeafIpv6(): void
    {
        tree_validate($this->payload(['cidr' => '2001:db8::/32']));
        $this->assertTrue(true);
    }

    public function testAcceptsTwoChildren(): void
    {
        tree_validate($this->payload([
            'cidr' => '10.0.0.0/24',
            'children' => [
                ['cidr' => '10.0.0.0/25'],
                ['cidr' => '10.0.0.128/25'],
            ],
        ]));
        $this->assertTrue(true);
    }

    public function testAcceptsGapBetweenSiblings(): void
    {
        // Children may have gaps (intentional reserves) — the design lock allows this.
        tree_validate($this->payload([
            'cidr' => '10.0.0.0/24',
            'children' => [
                ['cidr' => '10.0.0.0/26'],
                ['cidr' => '10.0.0.192/26'],
            ],
        ]));
        $this->assertTrue(true);
    }

    public function testRejectsSingleChild(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least 2/');
        tree_validate($this->payload([
            'cidr' => '10.0.0.0/24',
            'children' => [['cidr' => '10.0.0.0/25']],
        ]));
    }

    public function testRejectsOverlappingSiblings(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/overlaps/');
        tree_validate($this->payload([
            'cidr' => '10.0.0.0/24',
            'children' => [
                ['cidr' => '10.0.0.0/25'],
                ['cidr' => '10.0.0.0/26'], // sub-range of sibling
            ],
        ]));
    }

    public function testRejectsChildOutsideParent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not inside parent/');
        tree_validate($this->payload([
            'cidr' => '10.0.0.0/24',
            'children' => [
                ['cidr' => '10.0.0.0/25'],
                ['cidr' => '10.0.1.0/25'], // outside /24
            ],
        ]));
    }

    public function testRejectsNonCanonicalCidr(): void
    {
        // Host bits set on the network address.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/canonical/');
        tree_validate($this->payload(['cidr' => '10.0.0.5/24']));
    }

    public function testRejectsChildPrefixNotLongerThanParent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/longer than parent/');
        tree_validate($this->payload([
            'cidr' => '10.0.0.0/24',
            'children' => [
                ['cidr' => '10.0.0.0/24'], // same prefix as parent
                ['cidr' => '10.0.1.0/24'],
            ],
        ]));
    }

    public function testRejectsInvalidCidr(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        tree_validate($this->payload(['cidr' => '999.0.0.0/24']));
    }

    public function testRejectsMissingPrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        tree_validate($this->payload(['cidr' => '10.0.0.0']));
    }

    public function testRejectsNameTooLong(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/name exceeds/');
        tree_validate($this->payload([
            'cidr' => '10.0.0.0/24',
            'name' => str_repeat('a', 129),
        ]));
    }

    public function testRejectsNotesTooLong(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/notes exceed/');
        tree_validate($this->payload([
            'cidr' => '10.0.0.0/24',
            'notes' => str_repeat('a', 1025),
        ]));
    }

    public function testAcceptsNameAndNotesAtBoundary(): void
    {
        tree_validate($this->payload([
            'cidr' => '10.0.0.0/24',
            'name' => str_repeat('a', 128),
            'notes' => str_repeat('a', 1024),
        ]));
        $this->assertTrue(true);
    }

    public function testRejectsTreeDepthExceeded(): void
    {
        // Build /1 → /2 → … → /17 — depth 17 must be rejected.
        $node = ['cidr' => '0.0.0.0/17'];
        for ($p = 16; $p >= 0; $p--) {
            $node = [
                'cidr' => '0.0.0.0/' . $p,
                'children' => [
                    $node,
                    ['cidr' => tree_canonical_cidr_helper($p) . '/' . ($p + 1)],
                ],
            ];
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/depth exceeds/');
        tree_validate(['type' => 'tree', 'root' => $node]);
    }

    public function testAcceptsIpv6NestedTwoLevels(): void
    {
        tree_validate($this->payload([
            'cidr' => '2001:db8::/32',
            'children' => [
                [
                    'cidr' => '2001:db8::/33',
                    'children' => [
                        ['cidr' => '2001:db8::/34'],
                        ['cidr' => '2001:db8:4000::/34'],
                    ],
                ],
                ['cidr' => '2001:db8:8000::/33'],
            ],
        ]));
        $this->assertTrue(true);
    }

    public function testRejectsIpv6NonCanonical(): void
    {
        // Uppercase + uncompressed.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/canonical/');
        tree_validate($this->payload(['cidr' => '2001:DB8:0:0:0:0:0:0/32']));
    }
}

/**
 * Helper for the depth test — produces the second sibling's network
 * address at prefix p+1 (the upper half of /p).
 */
function tree_canonical_cidr_helper(int $parent_prefix): string
{
    if ($parent_prefix < 0) {
        $parent_prefix = 0;
    }
    if ($parent_prefix >= 32) {
        return '0.0.0.0';
    }
    $bit = 1 << (31 - $parent_prefix);
    return long2ip($bit) ?: '0.0.0.0';
}
