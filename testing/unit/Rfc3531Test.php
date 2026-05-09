<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Rfc3531Test extends TestCase
{
    public function test_centermost_4_bits(): void
    {
        // RFC 3531 §3 worked example for 4 reservation bits — centre first,
        // then bisect outward at each level.
        $order = rfc3531_allocation_order(4, 'centermost');
        $this->assertSame(
            [8, 4, 12, 2, 6, 10, 14, 1, 3, 5, 7, 9, 11, 13, 15],
            $order
        );
    }

    public function test_leftmost_4_bits(): void
    {
        $order = rfc3531_allocation_order(4, 'leftmost');
        $this->assertSame(range(0, 15), $order);
    }

    public function test_rightmost_4_bits(): void
    {
        $order = rfc3531_allocation_order(4, 'rightmost');
        $this->assertSame(array_reverse(range(0, 15)), $order);
    }

    public function test_centermost_3_bits(): void
    {
        $order = rfc3531_allocation_order(3, 'centermost');
        $this->assertSame([4, 2, 6, 1, 3, 5, 7], $order);
    }

    public function test_centermost_1_bit(): void
    {
        $order = rfc3531_allocation_order(1, 'centermost');
        $this->assertSame([1], $order);
    }

    public function test_leftmost_1_bit(): void
    {
        $order = rfc3531_allocation_order(1, 'leftmost');
        $this->assertSame([0, 1], $order);
    }

    public function test_invalid_strategy_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        rfc3531_allocation_order(4, 'random');
    }

    public function test_zero_bits_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        rfc3531_allocation_order(0, 'leftmost');
    }

    public function test_negative_bits_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        rfc3531_allocation_order(-1, 'leftmost');
    }

    public function test_bits_above_cap_rejected(): void
    {
        // Cap default 8; 1..16 absolute hard ceiling — verify the absolute ceiling.
        $this->expectException(InvalidArgumentException::class);
        rfc3531_allocation_order(17, 'leftmost');
    }

    public function test_apply_leftmost_produces_correct_child_prefixes(): void
    {
        $r = rfc3531_apply('2001:db8::/48', 4, 'leftmost');
        $this->assertSame(48, $r['parent']['length']);
        $this->assertSame('2001:db8::/48', $r['parent']['prefix']);
        $this->assertSame('leftmost', $r['strategy']);
        $this->assertSame(4, $r['reservation_bits']);
        $this->assertCount(16, $r['children']);
        $this->assertSame('2001:db8::/52', $r['children'][0]['prefix']);
        $this->assertSame('2001:db8:0:1000::/52', $r['children'][1]['prefix']);
        $this->assertSame('2001:db8:0:2000::/52', $r['children'][2]['prefix']);
        $this->assertSame('2001:db8:0:f000::/52', $r['children'][15]['prefix']);
        $this->assertSame(0, $r['children'][0]['order']);
        $this->assertSame(0, $r['children'][0]['value']);
        $this->assertSame(15, $r['children'][15]['value']);
    }

    public function test_apply_centermost_emits_centre_first(): void
    {
        $r = rfc3531_apply('2001:db8::/48', 4, 'centermost');
        $this->assertCount(15, $r['children']);
        // First child = value 8 → 2001:db8:0:8000::/52
        $this->assertSame(8, $r['children'][0]['value']);
        $this->assertSame('2001:db8:0:8000::/52', $r['children'][0]['prefix']);
        // Allocation order is preserved in $r['allocation_order']
        $this->assertSame(
            [8, 4, 12, 2, 6, 10, 14, 1, 3, 5, 7, 9, 11, 13, 15],
            $r['allocation_order']
        );
    }

    public function test_apply_rightmost(): void
    {
        $r = rfc3531_apply('2001:db8::/48', 4, 'rightmost');
        $this->assertCount(16, $r['children']);
        $this->assertSame(15, $r['children'][0]['value']);
        $this->assertSame('2001:db8:0:f000::/52', $r['children'][0]['prefix']);
    }

    public function test_apply_canonicalises_parent(): void
    {
        // Parent with non-zero host bits is canonicalised.
        $r = rfc3531_apply('2001:db8:0:1234::/48', 4, 'leftmost');
        $this->assertSame('2001:db8::/48', $r['parent']['prefix']);
    }

    public function test_apply_rejects_when_child_length_exceeds_128(): void
    {
        // /126 + 4 bits → /130 — illegal.
        $this->expectException(InvalidArgumentException::class);
        rfc3531_apply('2001:db8::/126', 4, 'leftmost');
    }

    public function test_apply_rejects_invalid_parent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        rfc3531_apply('not-an-address/48', 4, 'leftmost');
    }

    public function test_apply_rejects_parent_missing_length(): void
    {
        $this->expectException(InvalidArgumentException::class);
        rfc3531_apply('2001:db8::', 4, 'leftmost');
    }

    public function test_apply_8_bits_under_default_cap(): void
    {
        // 8 reservation bits → 256 children. Within default cap.
        $r = rfc3531_apply('2001:db8::/40', 8, 'leftmost');
        $this->assertCount(256, $r['children']);
        $this->assertSame(48, $r['children'][0]['prefix'] === '2001:db8::/48' ? 48 : -1);
    }

    public function test_apply_rejects_bits_above_configured_cap(): void
    {
        // Default cap is 8; ask for 9 → reject.
        $this->expectException(InvalidArgumentException::class);
        rfc3531_apply('2001:db8::/40', 9, 'leftmost');
    }

    public function test_apply_works_for_ipv4_style_deep_prefix(): void
    {
        // Slice a /60 with 2 bits → /62 children.
        $r = rfc3531_apply('2001:db8:0:1000::/60', 2, 'leftmost');
        $this->assertCount(4, $r['children']);
        $this->assertSame('2001:db8:0:1000::/62', $r['children'][0]['prefix']);
        $this->assertSame('2001:db8:0:1004::/62', $r['children'][1]['prefix']);
        $this->assertSame('2001:db8:0:1008::/62', $r['children'][2]['prefix']);
        $this->assertSame('2001:db8:0:100c::/62', $r['children'][3]['prefix']);
    }
}
