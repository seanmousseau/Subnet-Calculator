<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Multicast6Test extends TestCase
{
    public function test_all_nodes_link_local(): void
    {
        $r = decode_multicast('FF02::1');
        $this->assertSame(2, $r['scope']);
        $this->assertSame('link-local', $r['scope_name']);
        $this->assertSame(0, $r['flags']);
        $this->assertFalse($r['transient']);
        $this->assertSame('well-known', $r['scheme']);
        $this->assertNotNull($r['well_known']);
        $this->assertSame('All Nodes Address', $r['well_known']['name']);
        $this->assertNull($r['detail_route']);
    }

    public function test_solicited_node(): void
    {
        $r = decode_multicast('FF02::1:FF12:3456');
        $this->assertSame(2, $r['scope']);
        $this->assertNotNull($r['well_known']);
        $this->assertSame('Solicited-Node Address (RFC 4291)', $r['well_known']['name']);
    }

    public function test_ssm_group(): void
    {
        $r = decode_multicast('FF3E::1234:5678');
        $this->assertSame(0xE, $r['scope']);
        $this->assertSame('global', $r['scope_name']);
        $this->assertSame(0x3, $r['flags']);
        $this->assertTrue($r['prefix_based']);
        $this->assertTrue($r['transient']);
        $this->assertFalse($r['embedded_rp']);
        $this->assertSame('ssm', $r['scheme']);
        $this->assertSame('/ipv6/ssm', $r['detail_route']);
    }

    public function test_embedded_rp_group(): void
    {
        $r = decode_multicast('FF7E:140:2001:db8:cafe::1234');
        $this->assertSame(0xE, $r['scope']);
        $this->assertSame(0x7, $r['flags']);
        $this->assertTrue($r['embedded_rp']);
        $this->assertSame('embedded-rp', $r['scheme']);
        $this->assertSame('/ipv6/embedded-rp', $r['detail_route']);
    }

    public function test_invalid_non_multicast_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        decode_multicast('2001:db8::1');
    }

    public function test_invalid_garbage_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        decode_multicast('not-an-address');
    }

    public function test_reserved_scope_returns_reserved_name(): void
    {
        $r = decode_multicast('FF00::1');
        $this->assertSame(0, $r['scope']);
        $this->assertSame('reserved', $r['scope_name']);
    }

    public function test_canonical_address_lowercased_and_compressed(): void
    {
        $r = decode_multicast('FF02:0000:0000:0000:0000:0000:0000:0001');
        $this->assertSame('ff02::1', $r['address']);
    }

    public function test_group_id_extracted_as_28_hex_chars(): void
    {
        $r = decode_multicast('FF02::1');
        $this->assertSame(28, strlen($r['group_id']));
        $this->assertSame(str_pad('1', 28, '0', STR_PAD_LEFT), $r['group_id']);
    }

    public function test_transient_only_scheme(): void
    {
        // Flags = T only (0x1); not SSM, not embedded-RP.
        $r = decode_multicast('FF1E::1234');
        $this->assertSame(0x1, $r['flags']);
        $this->assertTrue($r['transient']);
        $this->assertFalse($r['prefix_based']);
        $this->assertFalse($r['embedded_rp']);
        $this->assertSame('transient', $r['scheme']);
        $this->assertNull($r['detail_route']);
    }

    public function test_well_known_unknown_returns_null_well_known(): void
    {
        // Flags=0, scope=2, but address not in registry.
        $r = decode_multicast('FF02::abcd');
        $this->assertSame('well-known', $r['scheme']);
        $this->assertNull($r['well_known']);
    }

    public function test_ipv6_in_prefix_still_works_after_migration(): void
    {
        // Pin contract: helper still works after move from functions-embedded-v4.php to functions-ipv6.php.
        $this->assertTrue(ipv6_in_prefix('2001:db8::1', '2001:db8::', 32));
        $this->assertFalse(ipv6_in_prefix('2001:db9::1', '2001:db8::', 32));
    }
}
