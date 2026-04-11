<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\RequiresPhpExtension('gmp')]
class IPv6Test extends TestCase
{
    // ── is_valid_ipv6 ──────────────────────────────────────────────────────────

    public function testValidIpv6(): void
    {
        $this->assertTrue(is_valid_ipv6('::1'));
        $this->assertTrue(is_valid_ipv6('2001:db8::1'));
        $this->assertTrue(is_valid_ipv6('fe80::1'));
    }

    public function testInvalidIpv6(): void
    {
        $this->assertFalse(is_valid_ipv6('192.168.1.1'));
        $this->assertFalse(is_valid_ipv6('not-an-ip'));
        $this->assertFalse(is_valid_ipv6(''));
    }

    // ── ipv6_to_gmp / gmp_to_ipv6 ────────────────────────────────────────────

    public function testRoundtrip(): void
    {
        $ip = '2001:db8::1';
        $expanded = inet_ntop(inet_pton($ip));
        $this->assertSame($expanded, gmp_to_ipv6(ipv6_to_gmp($ip)));
    }

    public function testLoopback(): void
    {
        $this->assertSame('::1', gmp_to_ipv6(ipv6_to_gmp('::1')));
    }

    // ── ipv6_ptr_zone ─────────────────────────────────────────────────────────

    public function testPtrZone32(): void
    {
        $this->assertSame('8.b.d.0.1.0.0.2.ip6.arpa', ipv6_ptr_zone('2001:db8::/32'));
    }

    public function testPtrZone48(): void
    {
        $r = ipv6_ptr_zone('2001:db8:1::/48');
        $this->assertStringEndsWith('.ip6.arpa', $r);
        // 48 bits / 4 = 12 nibbles, each separated by '.', plus '.ip6.arpa'
        // Format: n1.n2...n12.ip6.arpa → 12 nibbles + 2 labels = 13 dots
        $this->assertSame(13, substr_count($r, '.'));
    }

    public function testPtrZone0(): void
    {
        $this->assertSame('ip6.arpa', ipv6_ptr_zone('::/0'));
    }

    // ── calculate_subnet6 ─────────────────────────────────────────────────────

    public function testCalculateSubnet6_48(): void
    {
        $r = calculate_subnet6('2001:db8::', 48);
        $this->assertSame('2001:db8::/48', $r['network_cidr']);
        $this->assertSame('/48', $r['prefix']);
        $this->assertSame('2001:db8::', $r['first_ip']);
        $this->assertStringEndsWith('.ip6.arpa', $r['ptr_zone']);
    }

    public function testCalculateSubnet6_127(): void
    {
        // /127 — 2 addresses, small count
        $r = calculate_subnet6('2001:db8::0', 127);
        $this->assertSame('2', $r['total']);
    }

    public function testCalculateSubnet6_128(): void
    {
        $r = calculate_subnet6('::1', 128);
        $this->assertSame('1', $r['total']);
    }

    public function testCalculateSubnet6_64SmallCount(): void
    {
        // /64 — large count, should use exponential notation
        $r = calculate_subnet6('fe80::', 64);
        $this->assertSame('2^64', $r['total']);
    }

    public function testCalculateSubnet6_44SmallCount(): void
    {
        // /44 — host_bits = 84, > 20, so exponential
        $r = calculate_subnet6('2001:db8::', 44);
        $this->assertSame('2^84', $r['total']);
    }

    public function testCalculateSubnet6_108SmallCount(): void
    {
        // /108 — host_bits = 20, exactly at threshold: numeric
        $r = calculate_subnet6('2001:db8::', 108);
        $this->assertSame((string)(1 << 20), $r['total']);
    }

    // ── cidrs_overlap6 ────────────────────────────────────────────────────────

    public function testCidrsOverlap6None(): void
    {
        $this->assertSame('none', cidrs_overlap6('2001:db8::/32', '2001:db9::/32'));
    }

    public function testCidrsOverlap6Identical(): void
    {
        $this->assertSame('identical', cidrs_overlap6('2001:db8::/32', '2001:db8::/32'));
    }

    public function testCidrsOverlap6AContainsB(): void
    {
        $this->assertSame('a_contains_b', cidrs_overlap6('2001:db8::/32', '2001:db8:1::/48'));
    }

    public function testCidrsOverlap6BContainsA(): void
    {
        $this->assertSame('b_contains_a', cidrs_overlap6('2001:db8:1::/48', '2001:db8::/32'));
    }

    public function testCidrsOverlap6SlashZero(): void
    {
        // /0 covers everything — exercises the $host_bits = 128 branch of the mask
        $this->assertSame('a_contains_b', cidrs_overlap6('::/0', '2001:db8::/32'));
    }

    public function testCidrsOverlap6SlashOneTwentyEight(): void
    {
        // Two identical /128 host routes — exercises $host_bits = 0 branch (host_mask = 0)
        $this->assertSame('identical', cidrs_overlap6('::1/128', '::1/128'));
        $this->assertSame('none', cidrs_overlap6('::1/128', '::2/128'));
    }
}
