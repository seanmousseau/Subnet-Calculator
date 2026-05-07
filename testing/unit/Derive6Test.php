<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Derive6Test extends TestCase
{
    // ── mac_to_eui64 ────────────────────────────────────────────────────────

    public function testMacToEui64Rfc4291Vector(): void
    {
        // RFC 4291 §2.5.1 — U/L bit flip 0 → 1 (0x00 ^ 0x02 = 0x02).
        $this->assertSame('0224:b9ff:fe7e:abcd', mac_to_eui64('00:24:b9:7e:ab:cd'));
    }

    public function testMacToEui64AllZero(): void
    {
        $this->assertSame('0200:00ff:fe00:0000', mac_to_eui64('00:00:00:00:00:00'));
    }

    public function testMacToEui64AllOnes(): void
    {
        // U/L bit flips 1 → 0 (0xff ^ 0x02 = 0xfd).
        $this->assertSame('fdff:ffff:feff:ffff', mac_to_eui64('ff:ff:ff:ff:ff:ff'));
    }

    public function testMacToEui64AcceptsHyphenForm(): void
    {
        $this->assertSame('0224:b9ff:fe7e:abcd', mac_to_eui64('00-24-b9-7e-ab-cd'));
    }

    public function testMacToEui64AcceptsCiscoDottedForm(): void
    {
        $this->assertSame('0224:b9ff:fe7e:abcd', mac_to_eui64('0024.b97e.abcd'));
    }

    public function testMacToEui64AcceptsBareHexForm(): void
    {
        $this->assertSame('0224:b9ff:fe7e:abcd', mac_to_eui64('0024b97eabcd'));
    }

    public function testMacToEui64AcceptsMixedCase(): void
    {
        $this->assertSame('0224:b9ff:fe7e:abcd', mac_to_eui64('00:24:B9:7E:AB:CD'));
    }

    public function testMacToEui64WrongLengthRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        mac_to_eui64('00:24:b9:7e:ab');
    }

    public function testMacToEui64NonHexRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        mac_to_eui64('zz:24:b9:7e:ab:cd');
    }

    public function testMacToEui64EmptyRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        mac_to_eui64('');
    }

    public function testMacToEui64MixedSeparatorsRejected(): void
    {
        // Inputs that would normalise to 12 hex chars but mix separators
        // outside the four documented formats must be rejected.
        $this->expectException(InvalidArgumentException::class);
        mac_to_eui64('00:24-b9.7e:ab:cd');
    }

    // ── mac_to_link_local ───────────────────────────────────────────────────

    public function testMacToLinkLocalRfc4291Vector(): void
    {
        $this->assertSame('fe80::224:b9ff:fe7e:abcd', mac_to_link_local('00:24:b9:7e:ab:cd'));
    }

    public function testMacToLinkLocalAllZero(): void
    {
        $this->assertSame('fe80::200:ff:fe00:0', mac_to_link_local('00:00:00:00:00:00'));
    }

    public function testMacToLinkLocalInvalidMacRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        mac_to_link_local('not-a-mac');
    }

    // ── unicast_to_solicited_node ───────────────────────────────────────────

    public function testSolicitedNodeFromGlobalUnicast(): void
    {
        $this->assertSame('ff02::1:ff00:1', unicast_to_solicited_node('2001:db8::1'));
    }

    public function testSolicitedNodeFromLinkLocal(): void
    {
        $this->assertSame('ff02::1:ff7e:abcd', unicast_to_solicited_node('fe80::224:b9ff:fe7e:abcd'));
    }

    public function testSolicitedNodeInvalidIpv6Rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        unicast_to_solicited_node('not-an-address');
    }

    public function testSolicitedNodeAcceptsExpandedForm(): void
    {
        $this->assertSame(
            'ff02::1:ff00:1',
            unicast_to_solicited_node('2001:0db8:0000:0000:0000:0000:0000:0001')
        );
    }

    // ── derive_from_mac (orchestrator) ──────────────────────────────────────

    public function testDeriveFromMacRfc4291Vector(): void
    {
        $r = derive_from_mac('00:24:b9:7e:ab:cd');
        $this->assertSame('00:24:b9:7e:ab:cd', $r['mac_canonical']);
        $this->assertSame('0224:b9ff:fe7e:abcd', $r['eui64']);
        $this->assertTrue($r['ul_bit_flipped']);
        $this->assertSame('fe80::224:b9ff:fe7e:abcd', $r['link_local']);
        $this->assertSame('ff02::1:ff7e:abcd', $r['solicited_node']);
        $this->assertNull($r['warning']);
    }

    public function testDeriveFromMacCanonicalisesInputForm(): void
    {
        $r = derive_from_mac('0024.B97E.ABCD');
        $this->assertSame('00:24:b9:7e:ab:cd', $r['mac_canonical']);
    }

    public function testDeriveFromMacUlBitAlreadySet(): void
    {
        // 0x02 already has U/L = 1; the flip goes 1 → 0 (i.e. ul_bit_flipped = false).
        $r = derive_from_mac('02:24:b9:7e:ab:cd');
        $this->assertFalse($r['ul_bit_flipped']);
        $this->assertSame('02:24:b9:7e:ab:cd', $r['mac_canonical']);
    }

    public function testDeriveFromMacMulticastBitSetWarning(): void
    {
        // LSB of first byte = 1 (0x01) → multicast MAC; warn but still compute.
        $r = derive_from_mac('01:00:5e:00:00:01');
        $this->assertNotNull($r['warning']);
        $this->assertIsString($r['warning']);
        $this->assertSame('01:00:5e:00:00:01', $r['mac_canonical']);
        $this->assertNotEmpty($r['eui64']);
        $this->assertNotEmpty($r['link_local']);
        $this->assertNotEmpty($r['solicited_node']);
    }

    public function testDeriveFromMacUnicastBitNoWarning(): void
    {
        $r = derive_from_mac('00:24:b9:7e:ab:cd');
        $this->assertNull($r['warning']);
    }

    public function testDeriveFromMacInvalidRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        derive_from_mac('not-a-mac');
    }

    public function testDeriveFromMacAllFiveFieldsPresent(): void
    {
        $r = derive_from_mac('00:24:b9:7e:ab:cd');
        $this->assertArrayHasKey('mac_canonical', $r);
        $this->assertArrayHasKey('eui64', $r);
        $this->assertArrayHasKey('ul_bit_flipped', $r);
        $this->assertArrayHasKey('link_local', $r);
        $this->assertArrayHasKey('solicited_node', $r);
        $this->assertArrayHasKey('warning', $r);
    }
}
