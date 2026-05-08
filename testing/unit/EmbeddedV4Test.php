<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EmbeddedV4Test extends TestCase
{
    public function test_ipv4_mapped_detected(): void
    {
        $result = detect_embedded_v4('::ffff:192.0.2.1');
        $this->assertSame('mapped', $result['scheme']);
        $this->assertSame('192.0.2.1', $result['ipv4']);
        $this->assertFalse($result['deprecated']);
    }

    public function test_ipv4_compatible_detected_as_deprecated(): void
    {
        $result = detect_embedded_v4('::192.0.2.1');
        $this->assertSame('compatible', $result['scheme']);
        $this->assertSame('192.0.2.1', $result['ipv4']);
        $this->assertTrue($result['deprecated']);
    }

    public function test_6to4_detected(): void
    {
        $result = detect_embedded_v4('2002:c000:0201::');
        $this->assertSame('6to4', $result['scheme']);
        $this->assertSame('192.0.2.1', $result['ipv4']);
    }

    public function test_teredo_detected(): void
    {
        // RFC 4380 §4 example
        $result = detect_embedded_v4('2001:0:4136:e378:8000:63bf:3fff:fdd2');
        $this->assertSame('teredo', $result['scheme']);
    }

    public function test_nat64_wkp_detected(): void
    {
        $result = detect_embedded_v4('64:ff9b::192.0.2.1');
        $this->assertSame('nat64-wkp', $result['scheme']);
        $this->assertSame('192.0.2.1', $result['ipv4']);
    }

    public function test_isatap_globally_unique_detected(): void
    {
        $result = detect_embedded_v4('2001:db8::200:5efe:c000:201');
        $this->assertSame('isatap', $result['scheme']);
        $this->assertSame('192.0.2.1', $result['ipv4']);
    }

    public function test_no_embedding_returns_null(): void
    {
        $result = detect_embedded_v4('2001:db8::1');
        $this->assertNull($result['scheme']);
        $this->assertNull($result['ipv4']);
    }

    public function test_invalid_ipv6_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        detect_embedded_v4('not-an-address');
    }

    public function test_loopback_and_unspecified_not_misdetected_as_compatible(): void
    {
        // ::1 (loopback) and :: (unspecified) sit inside ::/96 but must NOT
        // be reported as IPv4-compatible per RFC 4291 §2.5.5.1.
        $loopback = detect_embedded_v4('::1');
        $this->assertNull($loopback['scheme']);

        $unspec = detect_embedded_v4('::');
        $this->assertNull($unspec['scheme']);
    }

    public function test_ipv6_in_prefix_helper(): void
    {
        $this->assertTrue(ipv6_in_prefix('2001:db8::1', '2001:db8::', 32));
        $this->assertFalse(ipv6_in_prefix('2001:db9::1', '2001:db8::', 32));
        $this->assertTrue(ipv6_in_prefix('64:ff9b::c000:201', '64:ff9b::', 96));
    }

    public function test_detail_route_seeded_null_for_now(): void
    {
        // Per-scheme drawers land in T3–T7. Until then, every scheme returns
        // detail_route=null. This test pins the contract so the eventual
        // wiring touch is deliberate.
        $r = detect_embedded_v4('::ffff:192.0.2.1');
        $this->assertNull($r['detail_route']);
    }

    public function test_extra_present_for_all_branches(): void
    {
        $r = detect_embedded_v4('2001:db8::1');
        $this->assertIsArray($r['extra']);
    }
}
