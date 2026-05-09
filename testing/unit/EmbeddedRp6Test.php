<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EmbeddedRp6Test extends TestCase
{
    public function test_build_embedded_rp(): void
    {
        // RP = 2001:db8:cafe::1 (RIID=1, prefix length 48). Bytes 0..15:
        //   FF 7E 01 30 20 01 0D B8 CA FE 00 00 12 34 56 78
        // Per RFC 5952, the single zero group (cafe:0000:...) does not
        // compress with `::`; only runs of >= 2 zero groups do.
        $r = build_embedded_rp_group('2001:db8:cafe::1', 48, 1, 0xE, 0x12345678);
        $this->assertSame('ff7e:130:2001:db8:cafe:0:1234:5678', strtolower($r['address']));
        $this->assertSame(48, $r['rp_prefix_length']);
        $this->assertSame(1, $r['riid']);
        $this->assertSame(0xE, $r['scope']);
        $this->assertSame(0x12345678, $r['group_id']);
        $this->assertSame('2001:db8:cafe::', strtolower($r['rp_prefix']));
        // RP address canonicalized = RP-prefix + ::RIID
        $this->assertSame('2001:db8:cafe::1', strtolower($r['rp_address']));
    }

    public function test_decode_embedded_rp_round_trip(): void
    {
        $r = decode_embedded_rp_group('ff7e:130:2001:db8:cafe:0:1234:5678');
        $this->assertSame(0xE, $r['scope']);
        $this->assertSame(48, $r['rp_prefix_length']);
        $this->assertSame('2001:db8:cafe::', strtolower($r['rp_prefix']));
        $this->assertSame(1, $r['riid']);
        $this->assertSame('2001:db8:cafe::1', strtolower($r['rp_address']));
        $this->assertSame(0x12345678, $r['group_id']);
    }

    public function test_build_rejects_riid_over_15(): void
    {
        $this->expectException(InvalidArgumentException::class);
        build_embedded_rp_group('2001:db8::1', 48, 16, 0xE, 1);
    }

    public function test_build_rejects_riid_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        build_embedded_rp_group('2001:db8::1', 48, -1, 0xE, 1);
    }

    public function test_build_rejects_prefix_length_over_64(): void
    {
        $this->expectException(InvalidArgumentException::class);
        build_embedded_rp_group('2001:db8::1', 96, 1, 0xE, 1);
    }

    public function test_build_rejects_negative_prefix_length(): void
    {
        $this->expectException(InvalidArgumentException::class);
        build_embedded_rp_group('2001:db8::1', -1, 1, 0xE, 1);
    }

    public function test_build_rejects_invalid_scope_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        build_embedded_rp_group('2001:db8::1', 48, 1, 0, 1);
    }

    public function test_build_rejects_invalid_scope_too_high(): void
    {
        $this->expectException(InvalidArgumentException::class);
        build_embedded_rp_group('2001:db8::1', 48, 1, 16, 1);
    }

    public function test_build_rejects_negative_group_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        build_embedded_rp_group('2001:db8::1', 48, 1, 0xE, -1);
    }

    public function test_build_rejects_group_id_over_32_bits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        build_embedded_rp_group('2001:db8::1', 48, 1, 0xE, 0x1_0000_0000);
    }

    public function test_build_rejects_garbage_rp_address(): void
    {
        $this->expectException(InvalidArgumentException::class);
        build_embedded_rp_group('not-an-address', 48, 1, 0xE, 1);
    }

    public function test_build_zero_prefix_length(): void
    {
        $r = build_embedded_rp_group('::1', 0, 1, 0xE, 0x12345678);
        // Bytes: FF 7E 01 00 00 00 00 00 00 00 00 00 12 34 56 78
        // → ff7e:100::1234:5678 (the eight zero bytes between byte3 and byte11
        //   span four 16-bit groups so `::` compresses them).
        $this->assertSame('ff7e:100::1234:5678', strtolower($r['address']));
        $this->assertSame(0, $r['rp_prefix_length']);
        $this->assertSame(1, $r['riid']);
        $this->assertSame('::', strtolower($r['rp_prefix']));
        $this->assertSame('::1', strtolower($r['rp_address']));
    }

    public function test_build_rp_address_host_bits_other_than_riid(): void
    {
        // RFC 3956 §3 — only the RIID is embedded; the RP address can have
        // any host suffix, but build() canonicalises rp_address to <rp_prefix>::<riid>.
        $r = build_embedded_rp_group('2001:db8:cafe::abcd', 48, 1, 0xE, 0x12345678);
        $this->assertSame('2001:db8:cafe::1', strtolower($r['rp_address']));
        $this->assertSame('ff7e:130:2001:db8:cafe:0:1234:5678', strtolower($r['address']));
    }

    public function test_decode_rejects_r_flag_unset(): void
    {
        // P only, R clear → flags = 0x3, not 0x7.
        $this->expectException(InvalidArgumentException::class);
        decode_embedded_rp_group('FF3E::1234:5678');
    }

    public function test_decode_rejects_non_multicast(): void
    {
        $this->expectException(InvalidArgumentException::class);
        decode_embedded_rp_group('2001:db8::1');
    }

    public function test_decode_rejects_garbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        decode_embedded_rp_group('not-an-address');
    }

    public function test_decode_rejects_prefix_length_over_64(): void
    {
        // FF7E:60:: would carry rp_prefix_length=0x60 (96) — must be rejected.
        $this->expectException(InvalidArgumentException::class);
        decode_embedded_rp_group('ff7e:60::1234:5678');
    }

    public function test_decode_link_local_scope(): void
    {
        // Scope 0x2 = link-local. flags must still be 0x7.
        // byte0=FF byte1=72 byte2=01 byte3=10 (16) prefix=fe80::, gid=1
        $r = decode_embedded_rp_group('ff72:110:fe80::1');
        $this->assertSame(0x2, $r['scope']);
        $this->assertSame(16, $r['rp_prefix_length']);
        $this->assertSame(1, $r['riid']);
        $this->assertSame(1, $r['group_id']);
    }

    public function test_build_riid_15(): void
    {
        // RIID = 0xF, prefix length 64.
        $r = build_embedded_rp_group('2001:db8:cafe:beef::f', 64, 0xF, 0xE, 0xDEADBEEF);
        $this->assertSame(0xF, $r['riid']);
        $this->assertSame(64, $r['rp_prefix_length']);
        $this->assertSame('2001:db8:cafe:beef::f', strtolower($r['rp_address']));
        $bin = inet_pton($r['address']);
        $this->assertNotFalse($bin);
        $this->assertSame(0xFF, ord((string)$bin[0]));
        // byte 1 high nibble must be 0x7 (R+P+T)
        $this->assertSame(0x7, (ord((string)$bin[1]) & 0xF0) >> 4);
        // byte 1 low nibble = scope
        $this->assertSame(0xE, ord((string)$bin[1]) & 0x0F);
        // byte 2 low nibble = riid
        $this->assertSame(0xF, ord((string)$bin[2]) & 0x0F);
        // byte 3 = prefix length
        $this->assertSame(64, ord((string)$bin[3]));
    }

    public function test_decode_zero_prefix_length(): void
    {
        // Round-trip of build_embedded_rp_group('::1', 0, 1, 0xE, 0x12345678).
        $r = decode_embedded_rp_group('ff7e:100::1234:5678');
        $this->assertSame(0, $r['rp_prefix_length']);
        $this->assertSame(1, $r['riid']);
        $this->assertSame('::', strtolower($r['rp_prefix']));
        $this->assertSame('::1', strtolower($r['rp_address']));
        $this->assertSame(0x12345678, $r['group_id']);
    }
}
