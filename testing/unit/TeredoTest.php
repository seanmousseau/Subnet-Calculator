<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TeredoTest extends TestCase
{
    // ─── decode_teredo() ───────────────────────────────────────────────────────

    public function test_decode_rfc4380_example(): void
    {
        // RFC 4380 §4 worked example:
        //   server = 65.54.227.120
        //   client = 192.0.2.45 (post-XOR)
        //   port   = 40000      (post-XOR)
        //   flags  = 0x8000 (cone)
        // Encoded address: 2001:0:4136:e378:8000:63bf:3fff:fdd2
        $r = decode_teredo('2001:0:4136:e378:8000:63bf:3fff:fdd2');
        $this->assertSame('65.54.227.120', $r['server_ipv4']);
        $this->assertSame(40000, $r['port']);
        $this->assertSame('192.0.2.45', $r['client_ipv4']);
        $this->assertTrue($r['cone']);
        $this->assertSame(0x8000, $r['flags']);
    }

    public function test_decode_non_cone_flag(): void
    {
        // Same address but flags=0x0000 → non-cone client.
        $r = decode_teredo('2001:0:4136:e378:0:63bf:3fff:fdd2');
        $this->assertFalse($r['cone']);
        $this->assertSame(0, $r['flags']);
        $this->assertSame('192.0.2.45', $r['client_ipv4']);
        $this->assertSame(40000, $r['port']);
    }

    public function test_decode_rejects_non_teredo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        decode_teredo('2001:db8::1');
    }

    public function test_decode_rejects_6to4(): void
    {
        $this->expectException(InvalidArgumentException::class);
        decode_teredo('2002:c000:0201::1');
    }

    public function test_decode_rejects_garbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        decode_teredo('not-an-address');
    }

    public function test_decode_rejects_ipv4(): void
    {
        $this->expectException(InvalidArgumentException::class);
        decode_teredo('192.0.2.1');
    }

    public function test_decode_rejects_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        decode_teredo('');
    }

    // ─── encode_teredo() ───────────────────────────────────────────────────────

    public function test_encode_round_trip(): void
    {
        $addr = encode_teredo('65.54.227.120', '192.0.2.45', 40000, 0x8000);
        $this->assertSame('2001:0:4136:e378:8000:63bf:3fff:fdd2', $addr);
    }

    public function test_encode_default_flags_cone(): void
    {
        // Default flags arg = 0x8000 (cone).
        $addr = encode_teredo('65.54.227.120', '192.0.2.45', 40000);
        $this->assertSame('2001:0:4136:e378:8000:63bf:3fff:fdd2', $addr);
    }

    public function test_encode_zero_flags(): void
    {
        $addr = encode_teredo('65.54.227.120', '192.0.2.45', 40000, 0);
        $this->assertSame('2001:0:4136:e378:0:63bf:3fff:fdd2', $addr);
    }

    public function test_encode_rejects_invalid_server_ipv4(): void
    {
        $this->expectException(InvalidArgumentException::class);
        encode_teredo('not-an-ip', '192.0.2.45', 40000);
    }

    public function test_encode_rejects_invalid_client_ipv4(): void
    {
        $this->expectException(InvalidArgumentException::class);
        encode_teredo('65.54.227.120', 'not-an-ip', 40000);
    }

    public function test_encode_rejects_port_out_of_range_high(): void
    {
        $this->expectException(InvalidArgumentException::class);
        encode_teredo('65.54.227.120', '192.0.2.45', 70000);
    }

    public function test_encode_rejects_port_out_of_range_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        encode_teredo('65.54.227.120', '192.0.2.45', -1);
    }

    public function test_encode_rejects_ipv6_as_v4(): void
    {
        $this->expectException(InvalidArgumentException::class);
        encode_teredo('::ffff:192.0.2.1', '192.0.2.45', 40000);
    }

    // ─── round-trip ────────────────────────────────────────────────────────────

    public function test_decode_encode_round_trip(): void
    {
        $original = '2001:0:4136:e378:8000:63bf:3fff:fdd2';
        $r = decode_teredo($original);
        $rebuilt = encode_teredo($r['server_ipv4'], $r['client_ipv4'], $r['port'], $r['flags']);
        $this->assertSame($original, $rebuilt);
    }
}
