<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class IsatapTest extends TestCase
{
    // ─── ipv4_to_isatap_iid() ──────────────────────────────────────────────────

    public function test_private_ipv4_to_iid(): void
    {
        // Explicit globally_unique=false → '0:5efe:V4'.
        $this->assertSame('0:5efe:c000:201', ipv4_to_isatap_iid('192.0.2.1', false));
    }

    public function test_global_ipv4_to_iid(): void
    {
        // Explicit globally_unique=true → '200:5efe:V4'.
        $this->assertSame('200:5efe:c000:201', ipv4_to_isatap_iid('192.0.2.1', true));
    }

    public function test_auto_globally_unique_decision(): void
    {
        // 8.8.8.8 is public unicast → globally_unique=true by default.
        $this->assertSame('200:5efe:808:808', ipv4_to_isatap_iid('8.8.8.8'));

        // 10.0.0.1 is private → globally_unique=false.
        $this->assertSame('0:5efe:a00:1', ipv4_to_isatap_iid('10.0.0.1'));

        // 192.0.2.1 is TEST-NET-1 (RFC 5737) — treated as non-global in
        // v3.5.1+ to match T7's NAT64 helper. Was global in v3.5.0.
        $this->assertSame('0:5efe:c000:201', ipv4_to_isatap_iid('192.0.2.1'));
    }

    public function test_auto_classify_rejects_multicast(): void
    {
        // 224.0.0.0/4 multicast → non-global form.
        $this->assertSame('0:5efe:e000:1', ipv4_to_isatap_iid('224.0.0.1'));
    }

    public function test_auto_classify_rejects_class_e(): void
    {
        // 240.0.0.0/4 reserved (class-E) → non-global form.
        $this->assertSame('0:5efe:f000:1', ipv4_to_isatap_iid('240.0.0.1'));
    }

    public function test_auto_classify_rejects_zero(): void
    {
        // 0.0.0.0 → non-global form.
        $this->assertSame('0:5efe:0:0', ipv4_to_isatap_iid('0.0.0.0'));
    }

    public function test_encode_zero_address(): void
    {
        $this->assertSame('0:5efe:0:0', ipv4_to_isatap_iid('0.0.0.0', false));
    }

    public function test_encode_max_address(): void
    {
        $this->assertSame('200:5efe:ffff:ffff', ipv4_to_isatap_iid('255.255.255.255', true));
    }

    public function test_encode_rejects_invalid_ipv4(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ipv4_to_isatap_iid('not-an-ip');
    }

    public function test_encode_rejects_ipv6(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ipv4_to_isatap_iid('::1');
    }

    public function test_encode_rejects_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ipv4_to_isatap_iid('');
    }

    // ─── decode_isatap_iid() ───────────────────────────────────────────────────

    public function test_decode_iid_round_trip_global(): void
    {
        $r = decode_isatap_iid('200:5efe:c000:201');
        $this->assertSame('192.0.2.1', $r['ipv4']);
        $this->assertTrue($r['globally_unique']);
    }

    public function test_decode_iid_round_trip_private(): void
    {
        $r = decode_isatap_iid('0:5efe:a00:1');
        $this->assertSame('10.0.0.1', $r['ipv4']);
        $this->assertFalse($r['globally_unique']);
    }

    public function test_decode_accepts_zero_padded_form(): void
    {
        // Also accept the un-compressed form.
        $r = decode_isatap_iid('0000:5efe:c000:0201');
        $this->assertSame('192.0.2.1', $r['ipv4']);
        $this->assertFalse($r['globally_unique']);
    }

    public function test_decode_rejects_non_isatap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        decode_isatap_iid('feed:beef:cafe:1');
    }

    public function test_decode_rejects_garbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        decode_isatap_iid('not-an-iid');
    }

    public function test_decode_rejects_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        decode_isatap_iid('');
    }

    public function test_decode_rejects_wrong_magic(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // Magic must be 0000:5efe or 0200:5efe — not 0100:5efe.
        decode_isatap_iid('100:5efe:c000:201');
    }

    public function test_round_trip_preserves_value(): void
    {
        $iid = ipv4_to_isatap_iid('203.0.113.42', true);
        $r = decode_isatap_iid($iid);
        $this->assertSame('203.0.113.42', $r['ipv4']);
        $this->assertTrue($r['globally_unique']);
    }
}
