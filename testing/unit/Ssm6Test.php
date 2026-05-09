<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Ssm6Test extends TestCase
{
    public function test_build_ssm_global(): void
    {
        // RFC 3306 §6 example: 2001:db8::/32, scope 0xE, group 0x12345678.
        $r = build_ssm_group('2001:db8::/32', 0xE, 0x12345678);
        $this->assertSame('ff3e:20:2001:db8::1234:5678', strtolower($r['address']));
        $this->assertSame(32, $r['prefix_length']);
        $this->assertSame(0xE, $r['scope']);
        $this->assertSame(0x12345678, $r['group_id']);
        $this->assertSame('2001:db8::', strtolower($r['unicast_prefix']));
    }

    public function test_build_ssm_short_form_zero_prefix_length(): void
    {
        $r = build_ssm_group('::/0', 0xE, 0x12345678);
        $this->assertSame('ff3e::1234:5678', strtolower($r['address']));
        $this->assertSame(0, $r['prefix_length']);
    }

    public function test_decode_ssm_round_trip(): void
    {
        $r = decode_ssm_group('ff3e:20:2001:db8::1234:5678');
        $this->assertSame(0xE, $r['scope']);
        $this->assertSame(32, $r['prefix_length']);
        $this->assertSame('2001:db8::', strtolower($r['unicast_prefix']));
        $this->assertSame(0x12345678, $r['group_id']);
        $this->assertSame('ff3e:20:2001:db8::1234:5678', strtolower($r['address']));
    }

    public function test_build_rejects_prefix_length_over_64(): void
    {
        $this->expectException(InvalidArgumentException::class);
        build_ssm_group('2001:db8::/96', 0xE, 1);
    }

    public function test_build_rejects_missing_slash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // Input without "/N" hits the format-validation branch in build_ssm_group().
        build_ssm_group('2001:db8::', 0xE, 1);
    }

    public function test_build_rejects_invalid_scope_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        build_ssm_group('2001:db8::/32', 0, 1);
    }

    public function test_build_rejects_invalid_scope_too_high(): void
    {
        $this->expectException(InvalidArgumentException::class);
        build_ssm_group('2001:db8::/32', 16, 1);
    }

    public function test_build_rejects_negative_group_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        build_ssm_group('2001:db8::/32', 0xE, -1);
    }

    public function test_build_rejects_group_id_over_32_bits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        build_ssm_group('2001:db8::/32', 0xE, 0x1_0000_0000);
    }

    public function test_build_rejects_garbage_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        build_ssm_group('not-a-prefix', 0xE, 1);
    }

    public function test_build_masks_host_bits_in_unicast_prefix(): void
    {
        // /32 with host bits set; must be zeroed before encoding.
        $r = build_ssm_group('2001:db8:ffff::/32', 0xE, 1);
        $this->assertSame('2001:db8::', strtolower($r['unicast_prefix']));
        // Address must contain bytes 4-11 = 2001:0db8:0000:0000.
        $this->assertSame('ff3e:20:2001:db8::1', strtolower($r['address']));
    }

    public function test_decode_rejects_p_flag_unset(): void
    {
        $this->expectException(InvalidArgumentException::class);
        decode_ssm_group('FF02::1');
    }

    public function test_decode_rejects_non_multicast(): void
    {
        $this->expectException(InvalidArgumentException::class);
        decode_ssm_group('2001:db8::1');
    }

    public function test_decode_rejects_garbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        decode_ssm_group('not-an-address');
    }

    public function test_decode_rejects_embedded_rp_r_flag(): void
    {
        // FF7E:: has flags=0x7 (R+P+T); decoder must require flags == 0x3 exactly.
        $this->expectException(InvalidArgumentException::class);
        decode_ssm_group('FF7E:140:2001:db8:cafe::1234');
    }

    public function test_decode_rejects_prefix_length_over_64(): void
    {
        // Manually craft a malformed SSM with prefix_length byte = 0x60 (96).
        // FF3E : 0060 : .... → invalid.
        $this->expectException(InvalidArgumentException::class);
        decode_ssm_group('ff3e:60:2001:db8::1');
    }

    public function test_build_scope_link_local(): void
    {
        // Scope 0x2 = link-local SSM. Permitted; result must reflect scope.
        $r = build_ssm_group('2001:db8::/32', 0x2, 0xCAFEBABE);
        $this->assertSame(0x2, $r['scope']);
        $bin = inet_pton($r['address']);
        $this->assertNotFalse($bin);
        $this->assertSame(0xFF, ord((string)$bin[0]));
        $this->assertSame((0x3 << 4) | 0x2, ord((string)$bin[1]));
    }

    public function test_decode_round_trip_zero_prefix(): void
    {
        $r = decode_ssm_group('ff3e::1234:5678');
        $this->assertSame(0, $r['prefix_length']);
        $this->assertSame('::', strtolower($r['unicast_prefix']));
        $this->assertSame(0x12345678, $r['group_id']);
    }
}
