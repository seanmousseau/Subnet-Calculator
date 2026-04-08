<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class IPv4Test extends TestCase
{
    // ── cidr_to_mask ───────────────────────────────────────────────────────────

    public function testCidrToMask24(): void
    {
        $this->assertSame('255.255.255.0', cidr_to_mask(24));
    }

    public function testCidrToMask0(): void
    {
        $this->assertSame('0.0.0.0', cidr_to_mask(0));
    }

    public function testCidrToMask32(): void
    {
        $this->assertSame('255.255.255.255', cidr_to_mask(32));
    }

    public function testCidrToMask16(): void
    {
        $this->assertSame('255.255.0.0', cidr_to_mask(16));
    }

    // ── mask_to_cidr ───────────────────────────────────────────────────────────

    public function testMaskToCidr255255255128(): void
    {
        $this->assertSame(25, mask_to_cidr('255.255.255.128'));
    }

    public function testMaskToCidr255255255255(): void
    {
        $this->assertSame(32, mask_to_cidr('255.255.255.255'));
    }

    // ── is_valid_ipv4 ──────────────────────────────────────────────────────────

    public function testValidIpv4(): void
    {
        $this->assertTrue(is_valid_ipv4('192.168.1.1'));
        $this->assertTrue(is_valid_ipv4('0.0.0.0'));
        $this->assertTrue(is_valid_ipv4('255.255.255.255'));
    }

    public function testInvalidIpv4(): void
    {
        $this->assertFalse(is_valid_ipv4('256.0.0.1'));
        $this->assertFalse(is_valid_ipv4('192.168.1'));
        $this->assertFalse(is_valid_ipv4('::1'));
    }

    // ── is_valid_mask_octet ────────────────────────────────────────────────────

    public function testValidMasks(): void
    {
        $this->assertTrue(is_valid_mask_octet('255.255.255.0'));
        $this->assertTrue(is_valid_mask_octet('255.0.0.0'));
        $this->assertTrue(is_valid_mask_octet('0.0.0.0'));
    }

    public function testInvalidMasks(): void
    {
        $this->assertFalse(is_valid_mask_octet('255.255.0.128')); // non-contiguous
        $this->assertFalse(is_valid_mask_octet('255.255.255.256'));
    }

    // ── cidr_to_wildcard ───────────────────────────────────────────────────────

    public function testWildcard24(): void
    {
        $this->assertSame('0.0.0.255', cidr_to_wildcard(24));
    }

    public function testWildcard0(): void
    {
        $this->assertSame('255.255.255.255', cidr_to_wildcard(0));
    }

    public function testWildcard32(): void
    {
        $this->assertSame('0.0.0.0', cidr_to_wildcard(32));
    }

    // ── ipv4_ptr_zone ──────────────────────────────────────────────────────────

    public function testPtrZone24(): void
    {
        $this->assertSame('1.168.192.in-addr.arpa', ipv4_ptr_zone('192.168.1.0/24'));
    }

    public function testPtrZone16(): void
    {
        $this->assertSame('168.192.in-addr.arpa', ipv4_ptr_zone('192.168.0.0/16'));
    }

    public function testPtrZone8(): void
    {
        $this->assertSame('10.in-addr.arpa', ipv4_ptr_zone('10.0.0.0/8'));
    }

    public function testPtrZoneRfc2317(): void
    {
        // /25 — classless delegation
        $this->assertSame('0/25.1.168.192.in-addr.arpa', ipv4_ptr_zone('192.168.1.0/25'));
    }

    // ── calculate_subnet ──────────────────────────────────────────────────────

    public function testCalculateSubnet24(): void
    {
        $r = calculate_subnet('192.168.1.55', 24);
        $this->assertSame('192.168.1.0/24', $r['network_cidr']);
        $this->assertSame('192.168.1.1', $r['first_usable']);
        $this->assertSame('192.168.1.254', $r['last_usable']);
        $this->assertSame('192.168.1.255', $r['broadcast']);
        $this->assertSame(254, $r['usable_hosts']);
        $this->assertSame('255.255.255.0', $r['netmask_octet']);
        $this->assertSame('0.0.0.255', $r['wildcard']);
        $this->assertSame('1.168.192.in-addr.arpa', $r['ptr_zone']);
    }

    public function testCalculateSubnet31(): void
    {
        // /31 — point-to-point, no broadcast
        $r = calculate_subnet('10.0.0.0', 31);
        $this->assertSame(2, $r['usable_hosts']);
        $this->assertSame('10.0.0.0', $r['first_usable']);
        $this->assertSame('10.0.0.1', $r['last_usable']);
    }

    public function testCalculateSubnet32(): void
    {
        $r = calculate_subnet('10.0.0.1', 32);
        $this->assertSame(1, $r['usable_hosts']);
        $this->assertSame('10.0.0.1', $r['first_usable']);
    }

    // ── cidrs_overlap ─────────────────────────────────────────────────────────

    public function testOverlapNone(): void
    {
        $this->assertSame('none', cidrs_overlap('10.0.0.0/24', '10.0.1.0/24'));
    }

    public function testOverlapIdentical(): void
    {
        $this->assertSame('identical', cidrs_overlap('10.0.0.0/24', '10.0.0.0/24'));
    }

    public function testOverlapAContainsB(): void
    {
        $this->assertSame('a_contains_b', cidrs_overlap('10.0.0.0/23', '10.0.0.0/24'));
    }

    public function testOverlapBContainsA(): void
    {
        $this->assertSame('b_contains_a', cidrs_overlap('10.0.0.0/24', '10.0.0.0/16'));
    }

    public function testOverlapNonAdjacentSame24(): void
    {
        $this->assertSame('none', cidrs_overlap('192.168.0.0/24', '192.168.1.0/24'));
    }
}
