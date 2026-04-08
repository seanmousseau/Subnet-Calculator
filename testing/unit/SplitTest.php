<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class SplitTest extends TestCase
{
    // ── split_subnet (IPv4) ───────────────────────────────────────────────────

    public function testSplitSubnet24Into25(): void
    {
        $r = split_subnet('192.168.1.0', 24, 25, 100);
        $this->assertSame(2, $r['total']);
        $this->assertSame(2, $r['showing']);
        $this->assertContains('192.168.1.0/25', $r['subnets']);
        $this->assertContains('192.168.1.128/25', $r['subnets']);
    }

    public function testSplitSubnetRespectsMaxSubnets(): void
    {
        $r = split_subnet('10.0.0.0', 8, 16, 5);
        $this->assertSame(256, $r['total']);
        $this->assertSame(5, $r['showing']);
        $this->assertCount(5, $r['subnets']);
    }

    public function testSplitSubnet31Into32(): void
    {
        $r = split_subnet('10.0.0.0', 31, 32, 100);
        $this->assertSame(2, $r['total']);
        $this->assertContains('10.0.0.0/32', $r['subnets']);
        $this->assertContains('10.0.0.1/32', $r['subnets']);
    }

    // ── split_subnet6 (IPv6) ──────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\RequiresPhpExtension('gmp')]
    public function testSplitSubnet6_48Into49(): void
    {
        $r = split_subnet6('2001:db8::', 48, 49, 100);
        $this->assertSame(2, (int)$r['total']);
        $this->assertSame(2, $r['showing']);
        $this->assertCount(2, $r['subnets']);
    }

    #[\PHPUnit\Framework\Attributes\RequiresPhpExtension('gmp')]
    public function testSplitSubnet6LargeCountString(): void
    {
        // /48 → /64 = 2^16 = 65536 subnets, limit to 5
        $r = split_subnet6('2001:db8::', 48, 64, 5);
        $this->assertSame(5, $r['showing']);
        $this->assertCount(5, $r['subnets']);
    }
}
