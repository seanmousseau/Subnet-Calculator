<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ZoneIdTest extends TestCase
{
    public function testLinkLocalWithZone(): void
    {
        $r = parse_zone_id('fe80::1%eth0');
        $this->assertSame('fe80::1', $r['address']);
        $this->assertSame('eth0', $r['zone_id']);
        $this->assertTrue($r['is_link_local']);
        $this->assertNull($r['warning']);
    }

    public function testLinkLocalWithoutZone(): void
    {
        $r = parse_zone_id('fe80::1');
        $this->assertSame('fe80::1', $r['address']);
        $this->assertNull($r['zone_id']);
        $this->assertTrue($r['is_link_local']);
        $this->assertNull($r['warning']);
    }

    public function testZoneOnNonLinkLocalProducesWarning(): void
    {
        $r = parse_zone_id('2001:db8::1%eth0');
        $this->assertSame('2001:db8::1', $r['address']);
        $this->assertSame('eth0', $r['zone_id']);
        $this->assertFalse($r['is_link_local']);
        $this->assertNotNull($r['warning']);
        $this->assertIsString($r['warning']);
    }

    public function testEmptyZoneAfterPercentRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        parse_zone_id('fe80::1%');
    }

    public function testInvalidCharInZoneRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        parse_zone_id('fe80::1%eth0!');
    }

    public function testZoneLongerThan32CharsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        parse_zone_id('fe80::1%' . str_repeat('a', 33));
    }

    public function testZoneExactly32CharsAccepted(): void
    {
        $r = parse_zone_id('fe80::1%' . str_repeat('a', 32));
        $this->assertSame(str_repeat('a', 32), $r['zone_id']);
    }

    public function testInvalidAddressRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        parse_zone_id('zzz');
    }

    public function testEmptyInputRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        parse_zone_id('');
    }

    public function testCompressedAndExpandedFormsCanonicaliseIdentically(): void
    {
        $a = parse_zone_id('fe80::1');
        $b = parse_zone_id('fe80:0000:0000:0000:0000:0000:0000:0001');
        $this->assertSame($a['address'], $b['address']);
    }

    public function testLinkLocalUpperBoundary(): void
    {
        // fe80::/10 covers fe80:: through febf:ffff:ffff:ffff:ffff:ffff:ffff:ffff.
        $r = parse_zone_id('febf:ffff:ffff:ffff:ffff:ffff:ffff:ffff');
        $this->assertTrue($r['is_link_local']);
    }

    public function testFirstAddressOutsideLinkLocalIsNotFlagged(): void
    {
        // fec0::/10 was the deprecated site-local block — outside fe80::/10.
        $r = parse_zone_id('fec0::1');
        $this->assertFalse($r['is_link_local']);
    }

    public function testAllowedZoneCharsUnderscoreAndDash(): void
    {
        $r = parse_zone_id('fe80::1%en_0-1');
        $this->assertSame('en_0-1', $r['zone_id']);
    }
}
