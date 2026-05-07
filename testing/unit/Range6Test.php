<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for range6_to_cidrs() — IPv6 inclusive range → minimal CIDR list.
 * Mirrors the v4 RangeTest patterns and adds GMP-overflow + cap coverage.
 *
 * @requires extension gmp
 */
class Range6Test extends TestCase
{
    // ── range6_to_cidrs ───────────────────────────────────────────────────────

    public function testRange6_ExactSlash64(): void
    {
        $r = range6_to_cidrs('2001:db8::', '2001:db8::ffff:ffff:ffff:ffff');
        $this->assertSame(['2001:db8::/64'], $r['cidrs']);
        $this->assertSame(1, $r['count']);
        $this->assertFalse($r['truncated']);
    }

    public function testRange6_SingleAddress(): void
    {
        $r = range6_to_cidrs('2001:db8::1', '2001:db8::1');
        $this->assertSame(['2001:db8::1/128'], $r['cidrs']);
        $this->assertSame(1, $r['total_addresses']);
    }

    public function testRange6_TwoAdjacentSlash128_BecomesSlash127(): void
    {
        $r = range6_to_cidrs('2001:db8::', '2001:db8::1');
        $this->assertSame(['2001:db8::/127'], $r['cidrs']);
        $this->assertSame(2, $r['total_addresses']);
    }

    public function testRange6_FragmentedNonAlignedRange(): void
    {
        // 2001:db8::1 .. 2001:db8::6 (6 addresses) cannot start with a block larger
        // than /128 at ::1, so result is multiple CIDRs.
        $r = range6_to_cidrs('2001:db8::1', '2001:db8::6');
        $this->assertGreaterThan(1, $r['count']);
        $this->assertSame(6, $r['total_addresses']);
        $this->assertFalse($r['truncated']);
    }

    public function testRange6_TwoAlignedSlash64Blocks(): void
    {
        $r = range6_to_cidrs('2001:db8::', '2001:db8:0:1:ffff:ffff:ffff:ffff');
        $this->assertSame(['2001:db8::/63'], $r['cidrs']);
    }

    public function testRange6_CompressedInputForms(): void
    {
        // ::1 expands to 0000:...:0001
        $r = range6_to_cidrs('::', '::1');
        $this->assertSame(['::/127'], $r['cidrs']);
    }

    public function testRange6_LargeBlockReturnsPowerOfTwoString(): void
    {
        // /48 covers 2^80 addresses — overflows PHP int.
        $r = range6_to_cidrs('2001:db8::', '2001:db8:0:ffff:ffff:ffff:ffff:ffff');
        $this->assertSame(['2001:db8::/48'], $r['cidrs']);
        $this->assertIsString($r['total_addresses']);
        $this->assertSame('2^80', $r['total_addresses']);
    }

    public function testRange6_MisalignedStartProducesMultipleBlocks(): void
    {
        // 2001:db8::1 .. 2001:db8::ff
        $r = range6_to_cidrs('2001:db8::1', '2001:db8::ff');
        $this->assertGreaterThan(1, $r['count']);
        $this->assertSame(255, $r['total_addresses']);
        // Validate that union of returned CIDRs equals exactly the input range.
        $start = ipv6_to_gmp('2001:db8::1');
        $end   = ipv6_to_gmp('2001:db8::ff');
        $expected_count = gmp_intval(gmp_add(gmp_sub($end, $start), gmp_init(1)));
        $covered_count = 0;
        foreach ($r['cidrs'] as $cidr) {
            [, $px] = explode('/', $cidr);
            $size = gmp_pow(gmp_init(2), 128 - (int)$px);
            $covered_count += gmp_intval($size);
        }
        $this->assertSame($expected_count, $covered_count);
    }

    public function testRange6_StartGreaterThanEnd_ReturnsError(): void
    {
        $r = range6_to_cidrs('2001:db8::10', '2001:db8::1');
        $this->assertArrayHasKey('error', $r);
        $this->assertStringContainsStringIgnoringCase('less than or equal', $r['error']);
    }

    public function testRange6_InvalidStart_ReturnsError(): void
    {
        $r = range6_to_cidrs('not-an-ipv6', '2001:db8::ff');
        $this->assertArrayHasKey('error', $r);
        $this->assertStringContainsStringIgnoringCase('start', $r['error']);
    }

    public function testRange6_InvalidEnd_ReturnsError(): void
    {
        $r = range6_to_cidrs('2001:db8::', 'not-an-ipv6');
        $this->assertArrayHasKey('error', $r);
        $this->assertStringContainsStringIgnoringCase('end', $r['error']);
    }

    public function testRange6_RejectsIPv4Input_AsStart(): void
    {
        $r = range6_to_cidrs('10.0.0.0', '2001:db8::ff');
        $this->assertArrayHasKey('error', $r);
        $this->assertStringContainsStringIgnoringCase('ipv6', $r['error']);
    }

    public function testRange6_RejectsIPv4Input_AsEnd(): void
    {
        $r = range6_to_cidrs('2001:db8::', '10.0.0.255');
        $this->assertArrayHasKey('error', $r);
    }

    public function testRange6_EmptyStart_ReturnsError(): void
    {
        $r = range6_to_cidrs('', '2001:db8::ff');
        $this->assertArrayHasKey('error', $r);
    }

    public function testRange6_EmptyEnd_ReturnsError(): void
    {
        $r = range6_to_cidrs('2001:db8::', '');
        $this->assertArrayHasKey('error', $r);
    }

    public function testRange6_CapHit_TruncatesAndFlags(): void
    {
        // Fragmented range that yields > 256 CIDRs:
        // start at ...::1 and end at ...::ff (aligned high end), this
        // produces 8 CIDRs so it doesn't hit the cap. Use a wider misaligned
        // range to definitely exceed 256.
        // ::1 .. ::1ff  produces 9 CIDRs. We need a deliberately huge fragmented
        // range. Best path: misaligned start across enough bits to force > 256
        // blocks. Start at offset 1, end at offset 2^9 - 1 + 256 chunks ≈ 600.
        // Actually simpler: from ::1 to (::1 + ~600 addresses) — this still aligns
        // mostly, so use a stair-step. Simpler: rely on a deliberate /N where the
        // greedy decomposition of misaligned-by-1 covers the full prefix span,
        // producing exactly N blocks for a /N-wide range starting at offset 1.
        // For prefix-span 9 bits (512 addrs) starting at ::1, the greedy emits
        // ~9 blocks. To get >256 we need ~256 bits of misalignment... not easy.
        //
        // Easiest: directly verify cap behaviour by passing a large absolute span
        // that is misaligned. Span of 2^256 isn't possible (only 128 bits), so
        // instead force the output to bump the cap by issuing a custom cap=4
        // override from config and a 9-block range.
        $saved = $GLOBALS['range_max_cidrs'] ?? null;
        $GLOBALS['range_max_cidrs'] = 4;
        try {
            $r = range6_to_cidrs('2001:db8::1', '2001:db8::ff');
            $this->assertTrue($r['truncated']);
            $this->assertSame(4, $r['count']);
            $this->assertSame(4, $r['cap']);
            $this->assertCount(4, $r['cidrs']);
        } finally {
            if ($saved === null) {
                unset($GLOBALS['range_max_cidrs']);
            } else {
                $GLOBALS['range_max_cidrs'] = $saved;
            }
        }
    }

    public function testRange6_DefaultCap256_NotHitForSmallRange(): void
    {
        $saved = $GLOBALS['range_max_cidrs'] ?? null;
        unset($GLOBALS['range_max_cidrs']);
        try {
            $r = range6_to_cidrs('2001:db8::', '2001:db8::ff');
            $this->assertFalse($r['truncated']);
            $this->assertSame(256, $r['cap']);
        } finally {
            if ($saved !== null) {
                $GLOBALS['range_max_cidrs'] = $saved;
            }
        }
    }

    public function testRange6_ResultShape_AlwaysHasRequiredKeys(): void
    {
        $r = range6_to_cidrs('2001:db8::', '2001:db8::1');
        $this->assertArrayHasKey('cidrs', $r);
        $this->assertArrayHasKey('count', $r);
        $this->assertArrayHasKey('total_addresses', $r);
        $this->assertArrayHasKey('truncated', $r);
        $this->assertArrayHasKey('cap', $r);
    }

    public function testRange6_FullSlash0(): void
    {
        $r = range6_to_cidrs('::', 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff');
        $this->assertSame(['::/0'], $r['cidrs']);
        $this->assertSame('2^128', $r['total_addresses']);
    }

    public function testRange6_AcceptsExpandedForm(): void
    {
        $r = range6_to_cidrs(
            '2001:0db8:0000:0000:0000:0000:0000:0000',
            '2001:0db8:0000:0000:ffff:ffff:ffff:ffff'
        );
        $this->assertSame(['2001:db8::/64'], $r['cidrs']);
    }
}
