<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Pmtu6Test extends TestCase
{
    public function test_no_fragmentation_when_payload_fits(): void
    {
        $r = pmtu_compute(1500, 1000);
        $this->assertSame(40, $r['fixed_header']);
        $this->assertSame(0, $r['extension_overhead']);
        $this->assertSame(40, $r['total_overhead']);
        $this->assertSame(1460, $r['effective_payload']);
        $this->assertFalse($r['needs_fragmentation']);
        $this->assertSame(1, $r['fragment_count']);
        $this->assertCount(1, $r['fragments']);
        $this->assertSame(0, $r['fragments'][0]['offset']);
        $this->assertSame(0, $r['fragments'][0]['m_bit']);
        $this->assertSame(1000, $r['fragments'][0]['payload_bytes']);
        $this->assertTrue($r['meets_minimum']);
    }

    public function test_fragmentation_when_payload_exceeds(): void
    {
        // PMTU 1280, no extensions, payload 3000.
        // Per-fragment payload max = floor((1280 - 40 - 8) / 8) * 8 = floor(1232/8)*8 = 1232.
        // 3000 / 1232 → 3 fragments: 1232 + 1232 + 536.
        $r = pmtu_compute(1280, 3000);
        $this->assertTrue($r['needs_fragmentation']);
        $this->assertSame(3, $r['fragment_count']);
        $this->assertCount(3, $r['fragments']);
        $this->assertSame(0, $r['fragments'][0]['offset']);
        $this->assertSame(1, $r['fragments'][0]['m_bit']);
        $this->assertSame(1232, $r['fragments'][0]['payload_bytes']);
        $this->assertSame(154, $r['fragments'][1]['offset']);   // 1232 / 8 = 154 (8-byte units)
        $this->assertSame(1, $r['fragments'][1]['m_bit']);
        $this->assertSame(1232, $r['fragments'][1]['payload_bytes']);
        $this->assertSame(308, $r['fragments'][2]['offset']);   // 2464 / 8 = 308
        $this->assertSame(0, $r['fragments'][2]['m_bit']);     // last fragment M=0
        $this->assertSame(536, $r['fragments'][2]['payload_bytes']);
    }

    public function test_below_minimum_flagged(): void
    {
        $r = pmtu_compute(1200, 100);
        $this->assertFalse($r['meets_minimum']);
        $this->assertContains(
            'PMTU 1200 is below the IPv6 minimum link MTU (1280) per RFC 8200 §5.',
            $r['notes']
        );
    }

    public function test_extension_headers_accumulated(): void
    {
        // HBH (8) + routing (8) + dest-opts (8) = 24 bytes overhead beyond fixed 40.
        $r = pmtu_compute(1500, 100, [8, 8, 8]);
        $this->assertSame(24, $r['extension_overhead']);
        $this->assertSame(64, $r['total_overhead']);
        $this->assertSame(1436, $r['effective_payload']);
        $this->assertFalse($r['needs_fragmentation']);
    }

    public function test_invalid_path_mtu_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        pmtu_compute(0, 100);
    }

    public function test_extension_header_not_multiple_of_8_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        pmtu_compute(1500, 100, [7]);
    }

    public function test_negative_payload_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        pmtu_compute(1500, -1);
    }

    public function test_zero_byte_extension_header_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        pmtu_compute(1500, 100, [0]);
    }

    public function test_notes_include_router_and_fixed_header(): void
    {
        $r = pmtu_compute(1280, 3000);
        $this->assertContains(
            'End-host fragmentation only; routers do not fragment IPv6 per RFC 8200 §5.',
            $r['notes']
        );
        $this->assertContains(
            'IPv6 fixed header is 40 bytes (RFC 8200 §3).',
            $r['notes']
        );
    }

    public function test_zero_payload_no_fragmentation(): void
    {
        $r = pmtu_compute(1500, 0);
        $this->assertFalse($r['needs_fragmentation']);
        $this->assertSame(1, $r['fragment_count']);
        $this->assertSame(0, $r['fragments'][0]['payload_bytes']);
    }

    public function test_exact_fit_no_fragmentation(): void
    {
        // payload exactly equals effective_payload → fits in one packet.
        $r = pmtu_compute(1500, 1460);
        $this->assertFalse($r['needs_fragmentation']);
        $this->assertSame(1, $r['fragment_count']);
        $this->assertSame(1460, $r['fragments'][0]['payload_bytes']);
    }

    public function test_one_byte_over_triggers_fragmentation(): void
    {
        // PMTU 1500: effective = 1460. Payload 1461 → fragmentation.
        // Per-fragment max = floor((1500-40-8)/8)*8 = floor(1452/8)*8 = 1448.
        // 1461 = 1448 + 13.
        $r = pmtu_compute(1500, 1461);
        $this->assertTrue($r['needs_fragmentation']);
        $this->assertSame(2, $r['fragment_count']);
        $this->assertSame(1448, $r['fragments'][0]['payload_bytes']);
        $this->assertSame(1, $r['fragments'][0]['m_bit']);
        $this->assertSame(181, $r['fragments'][1]['offset']);  // 1448 / 8 = 181
        $this->assertSame(0, $r['fragments'][1]['m_bit']);
        $this->assertSame(13, $r['fragments'][1]['payload_bytes']);
    }
}
