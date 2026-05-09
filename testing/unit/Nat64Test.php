<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * NAT64 / DNS64 helper (RFC 6052 / 6146 / 6147), v3.5.0 Task 7.
 *
 * Mandatory test vectors are taken from RFC 6052 §2.4: IPv4 192.0.2.33,
 * NAT64 prefix 2001:db8::/32 et al, and the well-known 64:ff9b::/96.
 */
final class Nat64Test extends TestCase
{
    /**
     * @return array<string, array{string, string, int, string}>
     */
    public static function rfc6052Section24Vectors(): array
    {
        return [
            '/32 → 2001:db8:c000:221::'      => [
                '192.0.2.33', '2001:db8::',           32, '2001:db8:c000:221::',
            ],
            '/40 → 2001:db8:1c0:2:21::'      => [
                '192.0.2.33', '2001:db8:100::',       40, '2001:db8:1c0:2:21::',
            ],
            '/48 → 2001:db8:122:c000:2:2100' => [
                '192.0.2.33', '2001:db8:122::',       48, '2001:db8:122:c000:2:2100::',
            ],
            '/56 → 2001:db8:122:3c0:0:221::' => [
                '192.0.2.33', '2001:db8:122:300::',   56, '2001:db8:122:3c0:0:221::',
            ],
            '/64 → 2001:db8:122:344:c0:2:2100' => [
                '192.0.2.33', '2001:db8:122:344::',   64, '2001:db8:122:344:c0:2:2100:0',
            ],
            // /96 row uses an NSP (2001:db8::/96 — documentation prefix)
            // because RFC 6052 §3.1 forbids embedding TEST-NET-1 (192.0.2.33)
            // into the well-known prefix. The /96 bit-layout is identical
            // regardless of which prefix is used.
            '/96 NSP → 2001:db8::c000:221' => [
                '192.0.2.33', '2001:db8::',           96, '2001:db8::c000:221',
            ],
        ];
    }

    /**
     * @dataProvider rfc6052Section24Vectors
     */
    public function testNat64EmbedRfc6052(
        string $v4,
        string $prefix,
        int $len,
        string $expected
    ): void {
        $this->assertSame($expected, nat64_embed($v4, $prefix, $len));
    }

    /**
     * @dataProvider rfc6052Section24Vectors
     */
    public function testNat64ExtractRfc6052(
        string $v4,
        string $prefix,
        int $len,
        string $embedded
    ): void {
        $this->assertSame($v4, nat64_extract($embedded, $prefix, $len));
    }

    public function testRejectsInvalidPrefixLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        nat64_embed('192.0.2.33', '2001:db8::', 80);
    }

    public function testRejectsNonAlignedPrefix(): void
    {
        // /32 prefix with non-zero bits beyond bit 32 must be rejected.
        $this->expectException(InvalidArgumentException::class);
        nat64_embed('192.0.2.33', '2001:db8:1::', 32);
    }

    public function testRejectsPrivateIpv4WithWellKnownPrefix(): void
    {
        // RFC 6052 §3.1: WKP MUST NOT be used to translate non-globally-
        // unique IPv4 addresses (RFC 1918, etc.).
        $this->expectException(InvalidArgumentException::class);
        nat64_embed('10.0.0.1', '64:ff9b::', 96);
    }

    public function testRejectsInvalidIpv4(): void
    {
        $this->expectException(InvalidArgumentException::class);
        nat64_embed('not-an-ip', '64:ff9b::', 96);
    }

    public function testExtractRejectsOutOfPrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        nat64_extract('2001:db8::1', '64:ff9b::', 96);
    }

    public function testDns64SynthesizeDefaults(): void
    {
        // DNS64 (RFC 6147) synthesises AAAA via well-known /96 by default.
        // 8.8.8.8 → 0x08080808 → 64:ff9b::808:808.
        $this->assertSame('64:ff9b::808:808', dns64_synthesize('8.8.8.8'));
    }

    public function testDns64SynthesizeCustom(): void
    {
        $this->assertSame(
            '2001:db8:122:344:c0:2:2100:0',
            dns64_synthesize('192.0.2.33', '2001:db8:122:344::', 64)
        );
    }

    public function testDns64RejectsPrivateWithWkp(): void
    {
        $this->expectException(InvalidArgumentException::class);
        dns64_synthesize('192.168.1.1');
    }

    public function testGloballyUniqueExcludesCGN(): void
    {
        $this->assertFalse(nat64_ipv4_is_globally_unique('100.64.0.1'));
    }

    public function testGloballyUniqueExcludesTestNet(): void
    {
        $this->assertFalse(nat64_ipv4_is_globally_unique('192.0.2.33'));
        $this->assertFalse(nat64_ipv4_is_globally_unique('198.51.100.1'));
        $this->assertFalse(nat64_ipv4_is_globally_unique('203.0.113.1'));
    }

    public function testGloballyUniqueIncludesOrdinaryGlobal(): void
    {
        $this->assertTrue(nat64_ipv4_is_globally_unique('8.8.8.8'));
        $this->assertTrue(nat64_ipv4_is_globally_unique('1.1.1.1'));
    }
}
