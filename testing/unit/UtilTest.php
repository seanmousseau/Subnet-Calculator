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

    // ── format_number ─────────────────────────────────────────────────────────

    public function testFormatNumberDefaultLocale(): void
    {
        $GLOBALS['locale'] = 'en';
        $this->assertSame('65,534', format_number(65534));
    }

    public function testFormatNumberZero(): void
    {
        $GLOBALS['locale'] = 'en';
        $this->assertSame('0', format_number(0));
    }

    public function testFormatNumberSmall(): void
    {
        $GLOBALS['locale'] = 'en';
        $this->assertSame('254', format_number(254));
    }

    public function testFormatNumberLargeNumber(): void
    {
        $GLOBALS['locale'] = 'en';
        $this->assertSame('16,777,216', format_number(16777216));
    }

    public function testFormatNumberFallbackEn(): void
    {
        // When locale is 'en', format_number must use number_format() fallback
        // regardless of intl availability, so the output is always comma-separated.
        $GLOBALS['locale'] = 'en';
        $result = format_number(1234567);
        $this->assertSame('1,234,567', $result);
    }

    public function testFormatNumberIntlLocale(): void
    {
        if (!\extension_loaded('intl')) {
            $this->markTestSkipped('intl extension not available');
        }
        $GLOBALS['locale'] = 'de';
        $result = format_number(65534);
        // German uses period as thousands separator
        $this->assertStringContainsString('65', $result);
        $this->assertNotSame('65,534', $result, 'de locale should not produce en comma format');
        $GLOBALS['locale'] = 'en';
    }
}
