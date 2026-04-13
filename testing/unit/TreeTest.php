<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class TreeTest extends TestCase
{
    // ── build_subnet_tree ─────────────────────────────────────────────────────

    public function testTree_BasicTwoChildren(): void
    {
        $r = build_subnet_tree('10.0.0.0/24', ['10.0.0.0/25', '10.0.0.128/25']);
        $this->assertArrayHasKey('tree', $r);
        $tree = $r['tree'];
        $this->assertSame('10.0.0.0/24', $tree['cidr']);
        $this->assertCount(2, $tree['children']);
        $this->assertSame('10.0.0.0/25', $tree['children'][0]['cidr']);
        $this->assertSame('10.0.0.128/25', $tree['children'][1]['cidr']);
        $this->assertSame([], $tree['gaps']);
    }

    public function testTree_SingleChild_WithGap(): void
    {
        $r = build_subnet_tree('10.0.0.0/24', ['10.0.0.0/25']);
        $this->assertArrayHasKey('tree', $r);
        $tree = $r['tree'];
        $this->assertCount(1, $tree['children']);
        // The upper half (10.0.0.128/25) should be a gap.
        $this->assertContains('10.0.0.128/25', $tree['gaps']);
    }

    public function testTree_NestedChildren(): void
    {
        $r = build_subnet_tree('10.0.0.0/22', [
            '10.0.0.0/23',
            '10.0.0.0/24',
            '10.0.1.0/24',
        ]);
        $this->assertArrayHasKey('tree', $r);
        $tree = $r['tree'];
        // /23 should be a direct child of /22
        $this->assertCount(1, $tree['children']);
        $this->assertSame('10.0.0.0/23', $tree['children'][0]['cidr']);
        // /24s should be nested inside /23
        $this->assertCount(2, $tree['children'][0]['children']);
    }

    public function testTree_NoChildren_AllGap(): void
    {
        $r = build_subnet_tree('10.0.0.0/24', []);
        $this->assertArrayHasKey('tree', $r);
        $tree = $r['tree'];
        $this->assertSame([], $tree['children']);
        // No children means no gaps to compute (empty $sorted_children → no gap walk).
        $this->assertSame([], $tree['gaps']);
    }

    public function testTree_InvalidParent_ReturnsError(): void
    {
        $r = build_subnet_tree('not-a-cidr', ['10.0.0.0/24']);
        $this->assertArrayHasKey('error', $r);
    }

    public function testTree_ChildOutsideParent_ReturnsError(): void
    {
        $r = build_subnet_tree('10.0.0.0/24', ['192.168.1.0/24']);
        $this->assertArrayHasKey('error', $r);
        $this->assertStringContainsStringIgnoringCase('outside', $r['error']);
    }

    public function testTree_InvalidChildCidr_ReturnsError(): void
    {
        $r = build_subnet_tree('10.0.0.0/24', ['not-a-cidr']);
        $this->assertArrayHasKey('error', $r);
    }

    public function testTree_DuplicateChildren_Deduplicated(): void
    {
        $r = build_subnet_tree('10.0.0.0/24', ['10.0.0.0/25', '10.0.0.0/25']);
        $this->assertArrayHasKey('tree', $r);
        // Duplicates removed — only one /25 child should appear.
        $this->assertCount(1, $r['tree']['children']);
    }

    public function testTree_GapBetweenTwoChildren(): void
    {
        // /26 covers .0–.63, /26 covers .128–.191 → gap is .64–.127 = /26 and .192–.255 = /26
        $r = build_subnet_tree('10.0.0.0/24', ['10.0.0.0/26', '10.0.0.128/26']);
        $this->assertArrayHasKey('tree', $r);
        $gaps = $r['tree']['gaps'];
        $this->assertContains('10.0.0.64/26', $gaps);
        $this->assertContains('10.0.0.192/26', $gaps);
    }
}
