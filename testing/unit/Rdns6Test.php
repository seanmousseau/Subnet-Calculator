<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Rdns6Test extends TestCase
{
    public function testFullAddressArpa(): void
    {
        $r = ipv6_to_arpa('2001:db8::1');
        $this->assertSame(
            '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa',
            $r
        );
    }

    public function testZoneDelegationAtSlash48(): void
    {
        $r = ipv6_to_arpa('2001:db8:abcd::', 48);
        $this->assertSame('d.c.b.a.8.b.d.0.1.0.0.2.ip6.arpa', $r);
    }

    public function testZoneDelegationAtSlash64(): void
    {
        $r = ipv6_to_arpa('2001:db8::', 64);
        $this->assertSame('0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa', $r);
    }

    public function testRejectsNonNibbleAlignedPrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ipv6_to_arpa('2001:db8::', 49);
    }

    public function testFullSlash128IsFullArpa(): void
    {
        $r1 = ipv6_to_arpa('2001:db8::1', 128);
        $r2 = ipv6_to_arpa('2001:db8::1');
        $this->assertSame($r2, $r1);
    }

    public function testLinkLocal(): void
    {
        $r = ipv6_to_arpa('fe80::1', 64);
        $this->assertSame('0.0.0.0.0.0.0.0.0.0.0.0.0.8.e.f.ip6.arpa', $r);
    }

    public function testRejectsInvalidAddress(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ipv6_to_arpa('not-an-address');
    }

    public function testZeroPrefixReturnsBareSuffix(): void
    {
        $r = ipv6_to_arpa('2001:db8::', 0);
        $this->assertSame('ip6.arpa', $r);
    }

    public function testRejectsPrefixOutOfRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ipv6_to_arpa('2001:db8::', 129);
    }
}
