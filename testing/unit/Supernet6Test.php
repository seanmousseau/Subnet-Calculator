<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Supernet6Test extends TestCase
{
    // ── supernet6_find ─────────────────────────────────────────────────────────

    public function testSupernet6_TwoAdjacentSlash64s(): void
    {
        $r = supernet6_find(['2001:db8::/64', '2001:db8:0:1::/64']);
        $this->assertSame('2001:db8::/63', $r['supernet']);
    }

    public function testSupernet6_TwoNonAdjacentSlash64sInSameParent(): void
    {
        $r = supernet6_find(['2001:db8::/64', '2001:db8:0:2::/64']);
        $this->assertSame('2001:db8::/62', $r['supernet']);
    }

    public function testSupernet6_AlreadyContained(): void
    {
        // /48 contains /64 → supernet should be the /48
        $r = supernet6_find(['2001:db8::/48', '2001:db8:0:1::/64']);
        $this->assertSame('2001:db8::/48', $r['supernet']);
    }

    public function testSupernet6_SingleCidr(): void
    {
        $r = supernet6_find(['2001:db8::/32']);
        $this->assertSame('2001:db8::/32', $r['supernet']);
    }

    public function testSupernet6_AllSlash128s(): void
    {
        $r = supernet6_find(['2001:db8::/128', '2001:db8::1/128']);
        $this->assertSame('2001:db8::/127', $r['supernet']);
    }

    public function testSupernet6_SlashZeroResult(): void
    {
        // ::/1 and 8000::/1 → entire IPv6 space → /0
        $r = supernet6_find(['::/1', '8000::/1']);
        $this->assertSame('::/0', $r['supernet']);
    }

    public function testSupernet6_ThreeCidrs(): void
    {
        $r = supernet6_find([
            '2001:db8::/64',
            '2001:db8:0:1::/64',
            '2001:db8:0:2::/64',
        ]);
        $this->assertSame('2001:db8::/62', $r['supernet']);
    }

    public function testSupernet6_EmptyInput(): void
    {
        $r = supernet6_find([]);
        $this->assertArrayHasKey('error', $r);
    }

    public function testSupernet6_InvalidCidr(): void
    {
        $r = supernet6_find(['not-a-cidr']);
        $this->assertArrayHasKey('error', $r);
    }

    public function testSupernet6_InvalidIp(): void
    {
        $r = supernet6_find(['gggg::/64']);
        $this->assertArrayHasKey('error', $r);
    }

    public function testSupernet6_InvalidPrefix(): void
    {
        $r = supernet6_find(['2001:db8::/129']);
        $this->assertArrayHasKey('error', $r);
    }

    public function testSupernet6_RejectsIPv4Cidrs(): void
    {
        $r = supernet6_find(['10.0.0.0/24']);
        $this->assertArrayHasKey('error', $r);
    }

    // ── summarise6_cidrs ───────────────────────────────────────────────────────

    public function testSummarise6_TwoAdjacentSlash64s(): void
    {
        $r = summarise6_cidrs(['2001:db8::/64', '2001:db8:0:1::/64']);
        $this->assertSame(['2001:db8::/63'], $r['summaries']);
    }

    public function testSummarise6_FourSlash64sMergeToSlash62(): void
    {
        $r = summarise6_cidrs([
            '2001:db8::/64',
            '2001:db8:0:1::/64',
            '2001:db8:0:2::/64',
            '2001:db8:0:3::/64',
        ]);
        $this->assertSame(['2001:db8::/62'], $r['summaries']);
    }

    public function testSummarise6_RemovesContainedCidr(): void
    {
        $r = summarise6_cidrs(['2001:db8::/48', '2001:db8:0:1::/64']);
        $this->assertSame(['2001:db8::/48'], $r['summaries']);
    }

    public function testSummarise6_NonContiguousStaysSeparate(): void
    {
        $r = summarise6_cidrs(['2001:db8::/64', '2001:db8:0:2::/64']);
        $this->assertSame(['2001:db8::/64', '2001:db8:0:2::/64'], $r['summaries']);
    }

    public function testSummarise6_NormalisesAndDedupesCanonicalForm(): void
    {
        // Two textually-different inputs that resolve to the same network.
        $r = summarise6_cidrs(['2001:DB8::/64', '2001:db8:0:0::/64']);
        $this->assertSame(['2001:db8::/64'], $r['summaries']);
    }
}
