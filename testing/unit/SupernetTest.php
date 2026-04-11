<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class SupernetTest extends TestCase
{
    // ── supernet_find ──────────────────────────────────────────────────────────

    public function testSupernet_TwoAdjacentSlash24s(): void
    {
        $r = supernet_find(['10.0.0.0/24', '10.0.1.0/24']);
        $this->assertSame('10.0.0.0/23', $r['supernet']);
    }

    public function testSupernet_TwoNonAdjacentSlash24sInSameSlash22(): void
    {
        $r = supernet_find(['10.0.0.0/24', '10.0.2.0/24']);
        $this->assertSame('10.0.0.0/22', $r['supernet']);
    }

    public function testSupernet_AlreadyContained(): void
    {
        // /24 is inside the /8; supernet should be the /8
        $r = supernet_find(['10.0.0.0/8', '10.0.1.0/24']);
        $this->assertSame('10.0.0.0/8', $r['supernet']);
    }

    public function testSupernet_SingleCidr(): void
    {
        $r = supernet_find(['192.168.1.0/24']);
        $this->assertSame('192.168.1.0/24', $r['supernet']);
    }

    public function testSupernet_AllSlash32s(): void
    {
        $r = supernet_find(['10.0.0.0/32', '10.0.0.1/32']);
        $this->assertSame('10.0.0.0/31', $r['supernet']);
    }

    public function testSupernet_SlashZeroResult(): void
    {
        // 0.0.0.0/1 and 128.0.0.0/1 → entire IPv4 space → /0
        $r = supernet_find(['0.0.0.0/1', '128.0.0.0/1']);
        $this->assertSame('0.0.0.0/0', $r['supernet']);
    }

    public function testSupernet_ThreeCidrs(): void
    {
        $r = supernet_find(['10.0.0.0/24', '10.0.1.0/24', '10.0.2.0/24']);
        $this->assertSame('10.0.0.0/22', $r['supernet']);
    }

    public function testSupernet_EmptyInput(): void
    {
        $r = supernet_find([]);
        $this->assertArrayHasKey('error', $r);
    }

    public function testSupernet_InvalidCidr(): void
    {
        $r = supernet_find(['not-a-cidr']);
        $this->assertArrayHasKey('error', $r);
    }

    public function testSupernet_InvalidIp(): void
    {
        $r = supernet_find(['999.999.999.999/24']);
        $this->assertArrayHasKey('error', $r);
    }

    public function testSupernet_InvalidPrefix(): void
    {
        $r = supernet_find(['10.0.0.0/33']);
        $this->assertArrayHasKey('error', $r);
    }

    // ── summarise_cidrs ────────────────────────────────────────────────────────

    public function testSummarise_TwoAdjacentSlash24s(): void
    {
        $r = summarise_cidrs(['10.0.0.0/24', '10.0.1.0/24']);
        $this->assertSame(['10.0.0.0/23'], $r['summaries']);
    }

    public function testSummarise_FourSlash24sMergeToSlash22(): void
    {
        $r = summarise_cidrs([
            '10.0.0.0/24',
            '10.0.1.0/24',
            '10.0.2.0/24',
            '10.0.3.0/24',
        ]);
        $this->assertSame(['10.0.0.0/22'], $r['summaries']);
    }

    public function testSummarise_RemovesContainedCidr(): void
    {
        // /24 is contained by /8; result should only be the /8
        $r = summarise_cidrs(['10.0.0.0/8', '10.1.2.0/24']);
        $this->assertSame(['10.0.0.0/8'], $r['summaries']);
    }

    public function testSummarise_NonMergeableReturnsOriginals(): void
    {
        // These cannot be merged (different class A ranges)
        $r = summarise_cidrs(['10.0.0.0/24', '192.168.1.0/24']);
        $this->assertCount(2, $r['summaries']);
        $this->assertContains('10.0.0.0/24', $r['summaries']);
        $this->assertContains('192.168.1.0/24', $r['summaries']);
    }

    public function testSummarise_SingleCidr(): void
    {
        $r = summarise_cidrs(['172.16.0.0/12']);
        $this->assertSame(['172.16.0.0/12'], $r['summaries']);
    }

    public function testSummarise_DuplicatesRemoved(): void
    {
        $r = summarise_cidrs(['10.0.0.0/24', '10.0.0.0/24']);
        $this->assertSame(['10.0.0.0/24'], $r['summaries']);
    }

    public function testSummarise_EmptyInput(): void
    {
        $r = summarise_cidrs([]);
        $this->assertArrayHasKey('error', $r);
    }

    public function testSummarise_InvalidCidr(): void
    {
        $r = summarise_cidrs(['bad-input']);
        $this->assertArrayHasKey('error', $r);
    }

    public function testSummarise_PartialMerge(): void
    {
        // 3 /24s: two can merge, one cannot
        $r = summarise_cidrs(['10.0.0.0/24', '10.0.1.0/24', '10.0.3.0/24']);
        $this->assertCount(2, $r['summaries']);
        $this->assertContains('10.0.0.0/23', $r['summaries']);
        $this->assertContains('10.0.3.0/24', $r['summaries']);
    }
}
