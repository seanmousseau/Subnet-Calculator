<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Subnet-Calculator/includes/functions-lookup.php';

class LookupTest extends TestCase
{
    public function testIpv4SingleMatch(): void
    {
        $r = lookup_ips(['10.0.0.0/8'], ['10.1.2.3']);
        $this->assertSame('10.0.0.0/8', $r[0]['deepest']);
        $this->assertSame(['10.0.0.0/8'], $r[0]['matches']);
        $this->assertSame('10.1.2.3', $r[0]['ip']);
    }

    public function testIpv4DeepestPrefixWins(): void
    {
        $r = lookup_ips(['10.0.0.0/8', '10.1.0.0/16', '10.1.2.0/24'], ['10.1.2.3']);
        $this->assertSame('10.1.2.0/24', $r[0]['deepest']);
        $this->assertCount(3, $r[0]['matches']);
    }

    public function testNoMatchReturnsNull(): void
    {
        $r = lookup_ips(['10.0.0.0/8'], ['8.8.8.8']);
        $this->assertNull($r[0]['deepest']);
        $this->assertSame([], $r[0]['matches']);
    }

    public function testIpv6Match(): void
    {
        $r = lookup_ips(['2001:db8::/32'], ['2001:db8::1']);
        $this->assertSame('2001:db8::/32', $r[0]['deepest']);
    }

    public function testIpv6DeepestPrefixWins(): void
    {
        $r = lookup_ips(['2001:db8::/32', '2001:db8:0:1::/64'], ['2001:db8:0:1::5']);
        $this->assertSame('2001:db8:0:1::/64', $r[0]['deepest']);
        $this->assertCount(2, $r[0]['matches']);
    }

    public function testMixedFamiliesIsolated(): void
    {
        $r = lookup_ips(['10.0.0.0/8', '2001:db8::/32'], ['10.1.2.3', '2001:db8::1']);
        $this->assertSame('10.0.0.0/8', $r[0]['deepest']);
        $this->assertSame('2001:db8::/32', $r[1]['deepest']);
    }

    public function testInvalidIpRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        lookup_ips(['10.0.0.0/8'], ['not-an-ip']);
    }

    public function testInvalidCidrRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        lookup_ips(['not-a-cidr'], ['10.0.0.1']);
    }

    public function testInvalidCidrPrefixRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        lookup_ips(['10.0.0.0/33'], ['10.0.0.1']);
    }

    public function testInvalidIpv6CidrPrefixRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        lookup_ips(['2001:db8::/129'], ['2001:db8::1']);
    }

    public function testIpOrderPreserved(): void
    {
        $r = lookup_ips(['10.0.0.0/8'], ['10.1.2.3', '8.8.8.8', '10.4.5.6']);
        $this->assertSame('10.1.2.3', $r[0]['ip']);
        $this->assertSame('8.8.8.8', $r[1]['ip']);
        $this->assertSame('10.4.5.6', $r[2]['ip']);
        $this->assertSame('10.0.0.0/8', $r[0]['deepest']);
        $this->assertNull($r[1]['deepest']);
        $this->assertSame('10.0.0.0/8', $r[2]['deepest']);
    }
}
