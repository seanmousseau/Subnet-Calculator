<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class SlaacPrivacyTest extends TestCase
{
    // ── Property tests (unseeded) ────────────────────────────────────────────

    public function testAddressIsExactly128Bits(): void
    {
        $r = slaac_privacy_address('2001:db8:1:2::/64');
        $bin = inet_pton($r['address']);
        $this->assertNotFalse($bin);
        $this->assertSame(16, strlen($bin));
    }

    public function testFirst64BitsMatchPrefix(): void
    {
        $r = slaac_privacy_address('2001:db8:1:2::/64');
        $addr_bin   = inet_pton($r['address']);
        $prefix_bin = inet_pton('2001:db8:1:2::');
        $this->assertNotFalse($addr_bin);
        $this->assertNotFalse($prefix_bin);
        $this->assertSame(substr($prefix_bin, 0, 8), substr($addr_bin, 0, 8));
    }

    public function testUlBitIsCleared(): void
    {
        // Force a seed whose first byte has U/L set; output must clear it.
        $r = slaac_privacy_address('2001:db8:1:2::/64', 'ffffffffffffffff');
        $addr_bin = inet_pton($r['address']);
        $this->assertNotFalse($addr_bin);
        // Byte 8 (zero-indexed) is the first byte of the interface ID.
        $first_iid_byte = ord($addr_bin[8]);
        $this->assertSame(0, $first_iid_byte & 0x02, 'U/L bit must be cleared on SLAAC privacy IID.');
    }

    public function testAvoidsEui64ShapedSuffix(): void
    {
        // Run several samples — none should look like EUI-64 (bytes 11–12 != ff fe).
        for ($i = 0; $i < 16; $i++) {
            $r = slaac_privacy_address('2001:db8::/64');
            $addr_bin = inet_pton($r['address']);
            $this->assertNotFalse($addr_bin);
            $b11 = ord($addr_bin[11]);
            $b12 = ord($addr_bin[12]);
            $this->assertFalse(
                $b11 === 0xff && $b12 === 0xfe,
                'Privacy IID should not match the EUI-64 ff:fe pattern.'
            );
        }
    }

    public function testTwoUnseededCallsDiffer(): void
    {
        $a = slaac_privacy_address('2001:db8:1:2::/64');
        $b = slaac_privacy_address('2001:db8:1:2::/64');
        // Flake probability 2^-64 — treat any failure as a real bug.
        $this->assertNotSame($a['address'], $b['address']);
    }

    // ── Seeded determinism ──────────────────────────────────────────────────

    public function testSameSeedSamePrefixProducesIdenticalAddress(): void
    {
        $a = slaac_privacy_address('2001:db8:1:2::/64', 'a8d3f4e10c529837');
        $b = slaac_privacy_address('2001:db8:1:2::/64', 'a8d3f4e10c529837');
        $this->assertSame($a['address'], $b['address']);
        $this->assertSame($a['interface_id'], $b['interface_id']);
    }

    public function testDifferentSeedProducesDifferentAddress(): void
    {
        $a = slaac_privacy_address('2001:db8:1:2::/64', 'a8d3f4e10c529837');
        $b = slaac_privacy_address('2001:db8:1:2::/64', 'b8d3f4e10c529837');
        $this->assertNotSame($a['address'], $b['address']);
    }

    public function testSeededResponseEchoesSeedAndProvenance(): void
    {
        $r = slaac_privacy_address('2001:db8:1:2::/64', 'a8d3f4e10c529837');
        $this->assertSame('a8d3f4e10c529837', $r['seed_used']);
        $this->assertTrue($r['seed_was_provided']);
    }

    public function testUnseededResponseHasGeneratedSeedMetadata(): void
    {
        $r = slaac_privacy_address('2001:db8:1:2::/64');
        $this->assertFalse($r['seed_was_provided']);
        $this->assertIsString($r['seed_used']);
        $this->assertSame(16, strlen($r['seed_used']));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $r['seed_used']);
    }

    public function testUppercaseSeedAcceptedAndNormalized(): void
    {
        $r = slaac_privacy_address('2001:db8:1:2::/64', 'A8D3F4E10C529837');
        $this->assertSame('a8d3f4e10c529837', $r['seed_used']);
        $this->assertTrue($r['seed_was_provided']);
        // Should match the lowercase-seeded run identically.
        $r2 = slaac_privacy_address('2001:db8:1:2::/64', 'a8d3f4e10c529837');
        $this->assertSame($r2['address'], $r['address']);
    }

    // ── Validation ──────────────────────────────────────────────────────────

    public function testEmptyPrefixRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        slaac_privacy_address('');
    }

    public function testNonSlash64PrefixRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SLAAC privacy addresses require a /64 prefix.');
        slaac_privacy_address('2001:db8::/48');
    }

    public function testInvalidPrefixStringRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        slaac_privacy_address('not-a-prefix');
    }

    public function testShortSeedRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Seed must be 16 hexadecimal characters.');
        slaac_privacy_address('2001:db8:1:2::/64', 'a8d3f4e10c52983');
    }

    public function testNonHexSeedRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Seed must be 16 hexadecimal characters.');
        slaac_privacy_address('2001:db8:1:2::/64', 'g0d3f4e10c529837');
    }
}
