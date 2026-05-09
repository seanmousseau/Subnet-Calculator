<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Mapped6Test extends TestCase
{
    public function testIsIpv4MappedTrue(): void
    {
        $this->assertTrue(is_ipv4_mapped('::ffff:192.0.2.1'));
        $this->assertTrue(is_ipv4_mapped('::ffff:c000:0201'));
    }

    public function testIsIpv4MappedFalse(): void
    {
        $this->assertFalse(is_ipv4_mapped('2001:db8::1'));
        $this->assertFalse(is_ipv4_mapped('64:ff9b::c000:0201'));
    }

    public function testIpv4ToMapped(): void
    {
        $this->assertSame('::ffff:192.0.2.1', ipv4_to_mapped('192.0.2.1'));
    }

    public function testMappedToIpv4(): void
    {
        $this->assertSame('192.0.2.1', mapped_to_ipv4('::ffff:192.0.2.1'));
    }

    public function testMappedToIpv4RejectsNonMapped(): void
    {
        $this->expectException(InvalidArgumentException::class);
        mapped_to_ipv4('2001:db8::1');
    }

    public function testIsNat64DefaultPrefix(): void
    {
        $this->assertTrue(is_nat64('64:ff9b::c000:0201'));
        $this->assertFalse(is_nat64('::ffff:192.0.2.1'));
    }

    public function testIpv4ToNat64Default(): void
    {
        // inet_ntop renders as compressed form
        $this->assertSame(
            '64:ff9b::c000:201',
            ipv4_to_nat64('192.0.2.1')
        );
    }

    public function testNat64ToIpv4Default(): void
    {
        $this->assertSame('192.0.2.1', nat64_to_ipv4('64:ff9b::c000:0201'));
    }

    public function testNat64CustomPrefixSlash96(): void
    {
        $this->assertSame(
            '2001:db8:1::c000:201',
            ipv4_to_nat64('192.0.2.1', '2001:db8:1::/96')
        );
        $this->assertSame(
            '192.0.2.1',
            nat64_to_ipv4('2001:db8:1::c000:201', '2001:db8:1::/96')
        );
    }

    public function testNat64RejectsNonSlash96(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ipv4_to_nat64('192.0.2.1', '2001:db8::/64');
    }

    public function testEdgeCasesAllZerosAndAllOnes(): void
    {
        $this->assertSame('::ffff:0.0.0.0', ipv4_to_mapped('0.0.0.0'));
        $this->assertSame('::ffff:255.255.255.255', ipv4_to_mapped('255.255.255.255'));
    }

    public function testIpv4ToMappedRejectsInvalidV4(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ipv4_to_mapped('not-an-ip');
    }

    public function testNat64ToIpv4RejectsOutOfPrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        nat64_to_ipv4('::ffff:192.0.2.1');  // mapped, not NAT64
    }

    // v3.5.0 Task 7 — NAT64 prefix-length-aware helpers (RFC 6052 §2.4).
    // The legacy /96-only helpers above are retained verbatim; these new
    // tests exercise the prefix-length-aware nat64_embed/nat64_extract.

    public function testNat64EmbedSlash96WellKnown(): void
    {
        // 8.8.8.8 → 0x08080808 → 64:ff9b::808:808. Use a globally-unique IPv4
        // because RFC 6052 §3.1 forbids embedding non-globally-unique IPv4
        // (incl. TEST-NET) into the well-known prefix.
        $this->assertSame(
            '64:ff9b::808:808',
            nat64_embed('8.8.8.8', '64:ff9b::', 96)
        );
    }

    public function testNat64ExtractSlash32(): void
    {
        $this->assertSame(
            '192.0.2.33',
            nat64_extract('2001:db8:c000:221::', '2001:db8::', 32)
        );
    }
}
