<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PrefixPlan6Test extends TestCase
{
    public function test_slice_48_into_56s(): void
    {
        $r = plan_prefix_delegation('2001:db8::/48', 56, 4, 0, true);
        $this->assertCount(4, $r['children']);
        $this->assertSame('2001:db8::/56', $r['children'][0]['prefix']);
        $this->assertSame('2001:db8:0:100::/56', $r['children'][1]['prefix']);
        $this->assertSame('2001:db8:0:200::/56', $r['children'][2]['prefix']);
        $this->assertSame('2001:db8:0:300::/56', $r['children'][3]['prefix']);
        $this->assertSame(56, $r['normalized_child_length']);
    }

    public function test_nibble_align_snaps_child_length(): void
    {
        // Asking for /49 with nibble-align → snaps up to /52
        $r = plan_prefix_delegation('2001:db8::/48', 49, 1, 0, true);
        $this->assertSame(52, $r['normalized_child_length']);
    }

    public function test_overflow_count_uses_2_n_string(): void
    {
        // /32 → /128 ⇒ 2^96 children
        $r = plan_prefix_delegation('2001:db8::/32', 128, 1, 0, false);
        $this->assertSame('2^96', $r['parent']['total_children_str']);
    }

    public function test_invalid_child_length_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        plan_prefix_delegation('2001:db8::/48', 32, 1, 0, false); // smaller than parent
    }

    public function test_child_length_equal_parent_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        plan_prefix_delegation('2001:db8::/48', 48, 1, 0, false);
    }

    public function test_child_length_too_large_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        plan_prefix_delegation('2001:db8::/48', 129, 1, 0, false);
    }

    public function test_count_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        plan_prefix_delegation('2001:db8::/48', 56, 0, 0, false);
    }

    public function test_negative_offset_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        plan_prefix_delegation('2001:db8::/48', 56, 1, -1, false);
    }

    public function test_offset_plus_count_exceeds_total_rejected(): void
    {
        // /48 → /49 ⇒ only 2 children; offset 1 + count 2 = 3 > 2.
        $this->expectException(InvalidArgumentException::class);
        plan_prefix_delegation('2001:db8::/48', 49, 2, 1, false);
    }

    public function test_invalid_parent_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        plan_prefix_delegation('not-an-address/48', 56, 1, 0, false);
    }

    public function test_parent_missing_prefix_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        plan_prefix_delegation('2001:db8::', 56, 1, 0, false);
    }

    public function test_start_offset_skips_first_n(): void
    {
        $r = plan_prefix_delegation('2001:db8::/48', 56, 2, 2, true);
        $this->assertCount(2, $r['children']);
        $this->assertSame(2, $r['children'][0]['index']);
        $this->assertSame('2001:db8:0:200::/56', $r['children'][0]['prefix']);
        $this->assertSame('2001:db8:0:300::/56', $r['children'][1]['prefix']);
    }

    public function test_first_and_last_addresses(): void
    {
        $r = plan_prefix_delegation('2001:db8::/48', 56, 1, 0, true);
        $child = $r['children'][0];
        $this->assertSame('2001:db8::', $child['first']);
        $this->assertSame('2001:db8:0:ff:ffff:ffff:ffff:ffff', $child['last']);
    }

    public function test_contains_64s_for_56(): void
    {
        // /56 contains 2^(64-56) = 256 /64s.
        $r = plan_prefix_delegation('2001:db8::/48', 56, 1, 0, true);
        $this->assertSame('256', $r['children'][0]['contains_64s']);
    }

    public function test_contains_64s_for_64(): void
    {
        // /64 contains exactly 1 /64 (itself).
        $r = plan_prefix_delegation('2001:db8::/48', 64, 1, 0, true);
        $this->assertSame('1', $r['children'][0]['contains_64s']);
    }

    public function test_contains_64s_for_smaller_than_64(): void
    {
        // /80 is a subset of a /64.
        $r = plan_prefix_delegation('2001:db8::/48', 80, 1, 0, true);
        $this->assertSame('subset of /64', $r['children'][0]['contains_64s']);
    }

    public function test_nibble_align_off_keeps_requested_length(): void
    {
        $r = plan_prefix_delegation('2001:db8::/48', 49, 2, 0, false);
        $this->assertSame(49, $r['normalized_child_length']);
        $this->assertCount(2, $r['children']);
    }

    public function test_total_children_str_small(): void
    {
        $r = plan_prefix_delegation('2001:db8::/48', 56, 4, 0, true);
        $this->assertSame('256', $r['parent']['total_children_str']);
    }

    public function test_total_children_str_overflow_threshold(): void
    {
        // /48 → /128 ⇒ 2^80 children (exceeds signed 64).
        $r = plan_prefix_delegation('2001:db8::/48', 128, 1, 0, false);
        $this->assertSame('2^80', $r['parent']['total_children_str']);
    }

    public function test_free_remaining_str(): void
    {
        $r = plan_prefix_delegation('2001:db8::/48', 56, 4, 0, true);
        $this->assertSame('252', $r['free']['remaining_str']);
    }

    public function test_free_remaining_overflow(): void
    {
        // /32 → /128 ⇒ 2^96 total, take 1 → 2^96 - 1 (still huge).
        $r = plan_prefix_delegation('2001:db8::/32', 128, 1, 0, false);
        // Free is total - 1; we surface it as decimal string when it doesn't
        // collapse to a clean 2^N. Just check that it's a non-empty digit string.
        $this->assertNotSame('', $r['free']['remaining_str']);
        $this->assertMatchesRegularExpression('/^[0-9]+$/', $r['free']['remaining_str']);
    }

    public function test_child_length_128_single_address(): void
    {
        // /48 → /128 with count=1 ⇒ a single host address.
        $r = plan_prefix_delegation('2001:db8::/48', 128, 1, 0, false);
        $this->assertSame('2001:db8::/128', $r['children'][0]['prefix']);
        $this->assertSame('2001:db8::', $r['children'][0]['first']);
        $this->assertSame('2001:db8::', $r['children'][0]['last']);
        $this->assertSame('subset of /64', $r['children'][0]['contains_64s']);
    }

    public function test_parent_normalised(): void
    {
        // Parent with non-zero host bits is canonicalised in output.
        $r = plan_prefix_delegation('2001:db8:0:1234::/48', 56, 1, 0, true);
        $this->assertSame('2001:db8::/48', $r['parent']['prefix']);
        $this->assertSame(48, $r['parent']['length']);
    }
}
