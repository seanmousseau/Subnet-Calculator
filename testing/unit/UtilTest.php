<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class UtilTest extends TestCase
{
    // ── get_ipv4_type ─────────────────────────────────────────────────────────

    public function testPrivate(): void
    {
        $this->assertSame('Private', get_ipv4_type('10.0.0.0'));
        $this->assertSame('Private', get_ipv4_type('172.16.0.0'));
        $this->assertSame('Private', get_ipv4_type('192.168.0.0'));
    }

    public function testLoopback(): void
    {
        $this->assertSame('Loopback', get_ipv4_type('127.0.0.1'));
    }

    public function testPublic(): void
    {
        $type = get_ipv4_type('8.8.8.8');
        $this->assertSame('Public', $type);
    }

    public function testLinkLocal(): void
    {
        $this->assertSame('Link-local', get_ipv4_type('169.254.1.1'));
    }

    public function testMulticast(): void
    {
        $this->assertSame('Multicast', get_ipv4_type('224.0.0.1'));
    }

    // ── get_ipv6_type ─────────────────────────────────────────────────────────

    public function testLoopbackIpv6(): void
    {
        $this->assertSame('Loopback', get_ipv6_type('::1'));
    }

    public function testLinkLocalIpv6(): void
    {
        $this->assertSame('Link-local', get_ipv6_type('fe80::1'));
    }

    public function testUniqueLocalIpv6(): void
    {
        $this->assertSame('Unique Local', get_ipv6_type('fc00::1'));
        $this->assertSame('Unique Local', get_ipv6_type('fd00::1'));
    }

    // ── type_badge_class ──────────────────────────────────────────────────────

    public function testBadgeClassPrivate(): void
    {
        $this->assertSame('private', type_badge_class('Private'));
    }

    public function testBadgeClassPublic(): void
    {
        $this->assertSame('public', type_badge_class('Public'));
    }

    public function testBadgeClassLoopback(): void
    {
        $this->assertSame('loopback', type_badge_class('Loopback'));
    }

    public function testBadgeClassUnknown(): void
    {
        $this->assertSame('other', type_badge_class('Unknown Type'));
    }
}
