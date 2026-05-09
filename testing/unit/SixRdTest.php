<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SixRdTest extends TestCase
{
    // ─── compute_6rd_delegation() ─────────────────────────────────────────────

    public function test_6rd_basic(): void
    {
        // SP prefix 2001:db8::/32, mask 0, customer 192.0.2.1 → 2001:db8:c000:201::/64
        $r = compute_6rd_delegation('2001:db8::/32', 0, '192.0.2.1');
        $this->assertSame('2001:db8:c000:201::/64', $r['prefix']);
        $this->assertSame(64, $r['prefix_length']);
    }

    public function test_6rd_with_mask(): void
    {
        // Discard top 8 bits of customer v4 (e.g. SP only assigns within 192.0.0.0/8).
        // SP=32 bits + (32-8)=24 customer bits = /56. Customer remaining 24 bits: 0x000201
        // packed at IPv6 bits [32, 56). 0x000201 << (128-56) → hex chars 8..13 = "000201".
        // Combined: 2001:0db8:0002:0100:: → canonical "2001:db8:2:100::/56".
        $r = compute_6rd_delegation('2001:db8::/32', 8, '192.0.2.1');
        $this->assertSame('2001:db8:2:100::/56', $r['prefix']);
        $this->assertSame(56, $r['prefix_length']);
    }

    public function test_6rd_extract(): void
    {
        $v4 = extract_6rd_ipv4('2001:db8::/32', 0, '2001:db8:c000:201::1');
        $this->assertSame('192.0.2.1', $v4);
    }

    public function test_6rd_extract_with_mask(): void
    {
        // With mask 8, the masked-off region is treated as zero. Round-trip
        // from compute_6rd_delegation('2001:db8::/32', 8, '192.0.2.1') →
        // 2001:db8:2:100::/56 → customer bits 0x000201 → v4 0.0.2.1.
        $v4 = extract_6rd_ipv4('2001:db8::/32', 8, '2001:db8:2:100::');
        $this->assertSame('0.0.2.1', $v4);
    }

    public function test_6rd_round_trip(): void
    {
        $r = compute_6rd_delegation('2001:db8::/32', 0, '203.0.113.42');
        $v4 = extract_6rd_ipv4('2001:db8::/32', 0, $r['prefix']);
        $this->assertSame('203.0.113.42', $v4);
    }

    public function test_invalid_ipv4_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        compute_6rd_delegation('2001:db8::/32', 0, 'not-an-ip');
    }

    public function test_invalid_sp_prefix_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        compute_6rd_delegation('not-a-prefix', 0, '192.0.2.1');
    }

    public function test_invalid_mask_len_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        compute_6rd_delegation('2001:db8::/32', -1, '192.0.2.1');
    }

    public function test_invalid_mask_len_too_large(): void
    {
        $this->expectException(InvalidArgumentException::class);
        compute_6rd_delegation('2001:db8::/32', 33, '192.0.2.1');
    }

    public function test_invalid_sp_prefix_length_too_large(): void
    {
        // SP /128 + 32 customer bits would overflow 128.
        $this->expectException(InvalidArgumentException::class);
        compute_6rd_delegation('2001:db8::/128', 0, '192.0.2.1');
    }

    public function test_extract_rejects_address_outside_sp_prefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        extract_6rd_ipv4('2001:db8::/32', 0, '2001:db9:c000:201::1');
    }

    public function test_extract_rejects_invalid_sp_prefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        extract_6rd_ipv4('not-a-prefix', 0, '2001:db8:c000:201::1');
    }

    public function test_extract_rejects_invalid_ipv6(): void
    {
        $this->expectException(InvalidArgumentException::class);
        extract_6rd_ipv4('2001:db8::/32', 0, 'not-an-address');
    }

    public function test_extract_rejects_bad_mask_len(): void
    {
        $this->expectException(InvalidArgumentException::class);
        extract_6rd_ipv4('2001:db8::/32', 33, '2001:db8:c000:201::1');
    }

    public function test_compute_rejects_empty_inputs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        compute_6rd_delegation('', 0, '192.0.2.1');
    }

    public function test_extract_rejects_empty_inputs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        extract_6rd_ipv4('2001:db8::/32', 0, '');
    }
}
