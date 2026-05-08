<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SixToFourTest extends TestCase
{
    // ─── ipv4_to_6to4() ────────────────────────────────────────────────────────

    public function test_ipv4_to_6to4_basic(): void
    {
        // RFC 3056 §2 worked example: 192.0.2.1 → 2002:c000:0201::/48.
        $this->assertSame('2002:c000:0201::/48', ipv4_to_6to4('192.0.2.1'));
    }

    public function test_ipv4_to_6to4_documentation_prefix_uppercase_normalised(): void
    {
        // 198.51.100.42 → 2002:c633:642a::/48.
        $this->assertSame('2002:c633:642a::/48', ipv4_to_6to4('198.51.100.42'));
    }

    public function test_ipv4_to_6to4_preserves_leading_zero_hexadectet(): void
    {
        // 8.0.0.0 → 2002:0800:0000::/48. Leading zeros within each
        // V4-derived hexadectet are preserved (matches RFC 3056 §2 example
        // formatting and the plan's canonical-form contract).
        $this->assertSame('2002:0800:0000::/48', ipv4_to_6to4('8.0.0.0'));
    }

    public function test_ipv4_to_6to4_high_octets(): void
    {
        // 203.0.113.5 → 2002:cb00:7105::/48.
        $this->assertSame('2002:cb00:7105::/48', ipv4_to_6to4('203.0.113.5'));
    }

    public function test_private_ipv4_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ipv4_to_6to4('10.0.0.1');
    }

    public function test_rfc1918_192_168_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ipv4_to_6to4('192.168.1.1');
    }

    public function test_loopback_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ipv4_to_6to4('127.0.0.1');
    }

    public function test_multicast_ipv4_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ipv4_to_6to4('239.0.0.1');
    }

    public function test_multicast_low_boundary_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ipv4_to_6to4('224.0.0.1');
    }

    public function test_unspecified_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ipv4_to_6to4('0.0.0.0');
    }

    public function test_garbage_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ipv4_to_6to4('not-an-address');
    }

    public function test_ipv6_input_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ipv4_to_6to4('::ffff:192.0.2.1');
    }

    // ─── decode_6to4() ─────────────────────────────────────────────────────────

    public function test_decode_6to4_basic(): void
    {
        $r = decode_6to4('2002:c000:0201::1');
        $this->assertSame('192.0.2.1', $r['ipv4']);
        $this->assertSame(0, $r['subnet_id']);
        $this->assertSame('0000:0000:0000:0001', $r['interface_id']);
    }

    public function test_decode_6to4_with_subnet_id(): void
    {
        // 6to4 with SLA ID 0x00ab and IID :1.
        $r = decode_6to4('2002:c000:0201:00ab::1');
        $this->assertSame('192.0.2.1', $r['ipv4']);
        $this->assertSame(0xab, $r['subnet_id']);
        $this->assertSame('0000:0000:0000:0001', $r['interface_id']);
    }

    public function test_decode_6to4_full_iid(): void
    {
        $r = decode_6to4('2002:cb00:7105:cafe:dead:beef:1234:5678');
        $this->assertSame('203.0.113.5', $r['ipv4']);
        $this->assertSame(0xcafe, $r['subnet_id']);
        $this->assertSame('dead:beef:1234:5678', $r['interface_id']);
    }

    public function test_decode_rejects_non_2002(): void
    {
        $this->expectException(InvalidArgumentException::class);
        decode_6to4('2001:db8::1');
    }

    public function test_decode_rejects_mapped(): void
    {
        $this->expectException(InvalidArgumentException::class);
        decode_6to4('::ffff:192.0.2.1');
    }

    public function test_decode_rejects_garbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        decode_6to4('not-an-address');
    }

    public function test_decode_rejects_ipv4(): void
    {
        $this->expectException(InvalidArgumentException::class);
        decode_6to4('192.0.2.1');
    }

    // ─── round-trip ────────────────────────────────────────────────────────────

    public function test_roundtrip_preserves_ipv4(): void
    {
        $cases = ['192.0.2.1', '198.51.100.42', '203.0.113.5', '8.8.8.8'];
        foreach ($cases as $v4) {
            $prefix = ipv4_to_6to4($v4);
            // Strip /48, append ::1, decode.
            $base = substr($prefix, 0, -3);
            $r = decode_6to4($base . '1');
            $this->assertSame($v4, $r['ipv4'], "roundtrip $v4");
        }
    }
}
