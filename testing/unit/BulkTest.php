<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for the v3.4.0 multi-op bulk dispatcher (functions-bulk.php).
 *
 * The standalone /bulk handler also has a legacy single-op CIDR-resolution
 * mode; that mode is exercised by the Playwright API suite. These unit tests
 * cover the per-op dispatch logic that powers the new `items[]` request mode.
 */
final class BulkTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('gmp')) {
            self::markTestSkipped('Bulk dispatcher exercises IPv6 ops which require GMP.');
        }
    }

    // ── range6 ──────────────────────────────────────────────────────────────

    public function testDispatchRange6Success(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'range6', 'params' => ['start' => '2001:db8::', 'end' => '2001:db8::ff']],
        ]);
        $this->assertCount(1, $r);
        $this->assertSame('range6', $r[0]['op']);
        $this->assertTrue($r[0]['ok']);
        $this->assertIsArray($r[0]['cidrs']);
        $this->assertNotEmpty($r[0]['cidrs']);
    }

    public function testDispatchRange6InvalidStart(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'range6', 'params' => ['start' => 'not-an-address', 'end' => '2001:db8::ff']],
        ]);
        $this->assertFalse($r[0]['ok']);
        $this->assertNotEmpty($r[0]['error']);
    }

    // ── supernet6 ───────────────────────────────────────────────────────────

    public function testDispatchSupernet6FindSuccess(): void
    {
        $r = bulk_dispatch_ops([
            [
                'op'     => 'supernet6',
                'params' => [
                    'action' => 'find',
                    'cidrs'  => ['2001:db8:0::/48', '2001:db8:1::/48'],
                ],
            ],
        ]);
        $this->assertTrue($r[0]['ok']);
        $this->assertArrayHasKey('supernet', $r[0]);
        $this->assertNotSame('', $r[0]['supernet']);
    }

    public function testDispatchSupernet6EmptyCidrs(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'supernet6', 'params' => ['action' => 'find', 'cidrs' => []]],
        ]);
        $this->assertFalse($r[0]['ok']);
    }

    // ── zone-id ─────────────────────────────────────────────────────────────

    public function testDispatchZoneIdSuccess(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'zone-id', 'params' => ['input' => 'fe80::1%eth0']],
        ]);
        $this->assertTrue($r[0]['ok']);
        $this->assertSame('fe80::1', $r[0]['address']);
        $this->assertSame('eth0', $r[0]['zone_id']);
        $this->assertTrue($r[0]['is_link_local']);
    }

    public function testDispatchZoneIdInvalid(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'zone-id', 'params' => ['input' => '']],
        ]);
        $this->assertFalse($r[0]['ok']);
    }

    // ── derive ──────────────────────────────────────────────────────────────

    public function testDispatchDeriveSuccess(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'derive', 'params' => ['mac' => '00:11:22:33:44:55']],
        ]);
        $this->assertTrue($r[0]['ok']);
        $this->assertArrayHasKey('eui64', $r[0]);
        $this->assertArrayHasKey('link_local', $r[0]);
    }

    public function testDispatchDeriveInvalid(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'derive', 'params' => ['mac' => 'not-a-mac']],
        ]);
        $this->assertFalse($r[0]['ok']);
    }

    // ── slaac-privacy ───────────────────────────────────────────────────────

    public function testDispatchSlaacPrivacySuccess(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'slaac-privacy', 'params' => ['prefix' => '2001:db8::/64', 'seed' => 'deadbeefcafef00d']],
        ]);
        $this->assertTrue($r[0]['ok']);
        $this->assertArrayHasKey('address', $r[0]);
        $this->assertArrayHasKey('interface_id', $r[0]);
        $this->assertTrue($r[0]['seed_was_provided']);
    }

    public function testDispatchSlaacPrivacyInvalidPrefix(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'slaac-privacy', 'params' => ['prefix' => 'nonsense']],
        ]);
        $this->assertFalse($r[0]['ok']);
    }

    // ── rdns6 ───────────────────────────────────────────────────────────────

    public function testDispatchRdns6Success(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'rdns6', 'params' => ['address' => '2001:db8::1', 'prefix' => 64]],
        ]);
        $this->assertTrue($r[0]['ok']);
        $this->assertSame(64, $r[0]['prefix']);
        $this->assertStringContainsString('ip6.arpa', $r[0]['arpa']);
    }

    public function testDispatchRdns6InvalidPrefix(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'rdns6', 'params' => ['address' => '2001:db8::1', 'prefix' => 'forty-two']],
        ]);
        $this->assertFalse($r[0]['ok']);
    }

    // ── mapped6 ─────────────────────────────────────────────────────────────

    public function testDispatchMapped6FromIpv4(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'mapped6', 'params' => ['input' => '192.0.2.1']],
        ]);
        $this->assertTrue($r[0]['ok']);
        $this->assertSame('192.0.2.1', $r[0]['ipv4']);
        $this->assertSame('::ffff:192.0.2.1', $r[0]['ipv4_mapped']);
        $this->assertStringStartsWith('64:ff9b::', $r[0]['nat64']);
    }

    public function testDispatchMapped6Invalid(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'mapped6', 'params' => ['input' => '2001:db8::1']],
        ]);
        $this->assertFalse($r[0]['ok']);
    }

    // ── Top-level shape errors ─────────────────────────────────────────────

    public function testDispatchUnknownOp(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'flux-capacitor', 'params' => []],
        ]);
        $this->assertFalse($r[0]['ok']);
        $this->assertStringContainsString('Unsupported op', $r[0]['error']);
    }

    public function testDispatchMissingOp(): void
    {
        $r = bulk_dispatch_ops([
            ['params' => ['address' => '2001:db8::1']],
        ]);
        $this->assertFalse($r[0]['ok']);
        $this->assertStringContainsString('"op" is required', $r[0]['error']);
    }

    public function testDispatchNonArrayItem(): void
    {
        $r = bulk_dispatch_ops(['not an object']);
        $this->assertFalse($r[0]['ok']);
    }

    // ── Mixed batch — happy path covering all 7 ops in one call ────────────

    public function testDispatchMixedBatchAllOpsSucceed(): void
    {
        $items = [
            ['op' => 'range6',        'params' => ['start' => '2001:db8::', 'end' => '2001:db8::ff']],
            ['op' => 'supernet6',     'params' => ['action' => 'find', 'cidrs' => ['2001:db8:0::/48', '2001:db8:1::/48']]],
            ['op' => 'zone-id',       'params' => ['input' => 'fe80::1%eth0']],
            ['op' => 'derive',        'params' => ['mac' => '00:11:22:33:44:55']],
            ['op' => 'slaac-privacy', 'params' => ['prefix' => '2001:db8::/64', 'seed' => 'cafef00ddeadbeef']],
            ['op' => 'rdns6',         'params' => ['address' => '2001:db8::1', 'prefix' => 64]],
            ['op' => 'mapped6',       'params' => ['input' => '192.0.2.1']],
        ];
        $r = bulk_dispatch_ops($items);
        $this->assertCount(7, $r);
        foreach ($r as $i => $envelope) {
            $this->assertTrue($envelope['ok'], "Item $i ({$envelope['op']}) should succeed; error: "
                . ($envelope['error'] ?? '<none>'));
            $this->assertSame($items[$i]['op'], $envelope['op']);
        }
    }

    public function testDispatchMixedBatchPartialFailure(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'range6', 'params' => ['start' => '2001:db8::', 'end' => '2001:db8::ff']],
            ['op' => 'rdns6',  'params' => ['address' => 'not-an-address']],
        ]);
        $this->assertCount(2, $r);
        $this->assertTrue($r[0]['ok']);
        $this->assertFalse($r[1]['ok']);
        $this->assertSame('rdns6', $r[1]['op']);
    }

    // ── v3.5.0 ops ──────────────────────────────────────────────────────────

    public function testDispatchEmbeddedV4Success(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'embedded-v4', 'params' => ['input' => '::ffff:192.0.2.1']],
        ]);
        $this->assertTrue($r[0]['ok']);
        $this->assertSame('mapped', $r[0]['scheme']);
        $this->assertSame('192.0.2.1', $r[0]['ipv4']);
    }

    public function testDispatch6to4EncodeSuccess(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => '6to4', 'params' => ['mode' => 'encode', 'ipv4' => '192.0.2.1']],
        ]);
        $this->assertTrue($r[0]['ok']);
        $this->assertStringStartsWith('2002:', (string)$r[0]['prefix']);
    }

    public function testDispatchTeredoDecodeSuccess(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'teredo', 'params' => ['mode' => 'decode', 'ipv6' => '2001:0:4136:e378:8000:63bf:3fff:fdd2']],
        ]);
        $this->assertTrue($r[0]['ok'], (string)($r[0]['error'] ?? ''));
        $this->assertArrayHasKey('client_ipv4', $r[0]);
    }

    public function testDispatchIsatapEncodeSuccess(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'isatap', 'params' => ['mode' => 'encode', 'ipv4' => '192.0.2.1']],
        ]);
        $this->assertTrue($r[0]['ok'], (string)($r[0]['error'] ?? ''));
        $this->assertArrayHasKey('iid', $r[0]);
    }

    public function testDispatch6rdEncodeSuccess(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => '6rd', 'params' => [
                'mode'             => 'encode',
                'sp_ipv6_prefix'   => '2001:db8::/32',
                'sp_ipv4_mask_len' => 0,
                'customer_ipv4'    => '192.0.2.1',
            ]],
        ]);
        $this->assertTrue($r[0]['ok'], (string)($r[0]['error'] ?? ''));
        $this->assertArrayHasKey('prefix', $r[0]);
    }

    public function testDispatchNat64EncodeSuccess(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'nat64', 'params' => [
                'mode'          => 'encode',
                'ipv4'          => '8.8.8.8',
                'prefix_length' => 96,
            ]],
        ]);
        $this->assertTrue($r[0]['ok'], (string)($r[0]['error'] ?? ''));
        $this->assertArrayHasKey('ipv6', $r[0]);
    }

    public function testDispatchPrefixPlan6Success(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'prefix-plan6', 'params' => [
                'parent_prefix' => '2001:db8::/48',
                'child_length'  => 56,
                'count'         => 4,
            ]],
        ]);
        $this->assertTrue($r[0]['ok'], (string)($r[0]['error'] ?? ''));
        $this->assertCount(4, $r[0]['children']);
    }

    public function testDispatchNibble6Success(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'nibble6', 'params' => ['prefix' => '2001:db8::/49']],
        ]);
        $this->assertTrue($r[0]['ok'], (string)($r[0]['error'] ?? ''));
        $this->assertArrayHasKey('above', $r[0]);
        $this->assertArrayHasKey('below', $r[0]);
    }

    public function testDispatchRfc3531Success(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'rfc3531', 'params' => [
                'parent_prefix'    => '2001:db8::/48',
                'reservation_bits' => 4,
                'strategy'         => 'centermost',
            ]],
        ]);
        $this->assertTrue($r[0]['ok'], (string)($r[0]['error'] ?? ''));
        $this->assertSame('centermost', $r[0]['strategy']);
    }

    public function testDispatchV35MixedBatchAllSucceed(): void
    {
        $items = [
            ['op' => 'embedded-v4',  'params' => ['input' => '::ffff:192.0.2.1']],
            ['op' => '6to4',         'params' => ['mode' => 'encode', 'ipv4' => '192.0.2.1']],
            ['op' => 'teredo',       'params' => ['mode' => 'decode', 'ipv6' => '2001:0:4136:e378:8000:63bf:3fff:fdd2']],
            ['op' => 'isatap',       'params' => ['mode' => 'encode', 'ipv4' => '192.0.2.1']],
            ['op' => '6rd',          'params' => ['mode' => 'encode', 'sp_ipv6_prefix' => '2001:db8::/32', 'sp_ipv4_mask_len' => 0, 'customer_ipv4' => '192.0.2.1']],
            ['op' => 'nat64',        'params' => ['mode' => 'encode', 'ipv4' => '8.8.8.8', 'prefix_length' => 96]],
            ['op' => 'prefix-plan6', 'params' => ['parent_prefix' => '2001:db8::/48', 'child_length' => 56, 'count' => 2]],
            ['op' => 'nibble6',      'params' => ['prefix' => '2001:db8::/49']],
            ['op' => 'rfc3531',      'params' => ['parent_prefix' => '2001:db8::/48', 'reservation_bits' => 4, 'strategy' => 'centermost']],
        ];
        $r = bulk_dispatch_ops($items);
        $this->assertCount(9, $r);
        foreach ($r as $i => $env) {
            $this->assertTrue(
                $env['ok'],
                "Item $i ({$env['op']}) should succeed; error: " . ($env['error'] ?? '<none>')
            );
            $this->assertSame($items[$i]['op'], $env['op']);
        }
    }

    public function testDispatchV35InvalidShape(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'nibble6', 'params' => []],
            ['op' => '6to4',    'params' => ['mode' => 'encode']],
        ]);
        $this->assertFalse($r[0]['ok']);
        $this->assertStringContainsString('prefix', $r[0]['error']);
        $this->assertFalse($r[1]['ok']);
    }

    // ── v3.6.0 ──────────────────────────────────────────────────────────────

    public function testDispatchMulticast6Success(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'multicast6', 'params' => ['ipv6' => 'ff02::1']],
        ]);
        $this->assertTrue($r[0]['ok'], (string)($r[0]['error'] ?? ''));
        $this->assertSame('multicast6', $r[0]['op']);
        $this->assertArrayHasKey('scope', $r[0]);
        $this->assertArrayHasKey('scheme', $r[0]);
    }

    public function testDispatchMulticast6MissingField(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'multicast6', 'params' => []],
        ]);
        $this->assertFalse($r[0]['ok']);
        $this->assertStringContainsString('ipv6', $r[0]['error']);
    }

    public function testDispatchSsm6EncodeSuccess(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'ssm6', 'params' => [
                'mode'           => 'encode',
                'unicast_prefix' => '2001:db8::/64',
                'scope'          => 5,
                'group_id'       => 1,
            ]],
        ]);
        $this->assertTrue($r[0]['ok'], (string)($r[0]['error'] ?? ''));
        $this->assertSame('encode', $r[0]['mode']);
        $this->assertArrayHasKey('address', $r[0]);
    }

    public function testDispatchSsm6DecodeRoundTrip(): void
    {
        $enc = bulk_dispatch_ops([
            ['op' => 'ssm6', 'params' => [
                'mode' => 'encode', 'unicast_prefix' => '2001:db8::/64',
                'scope' => 5, 'group_id' => 42,
            ]],
        ]);
        $this->assertTrue($enc[0]['ok']);
        $dec = bulk_dispatch_ops([
            ['op' => 'ssm6', 'params' => ['mode' => 'decode', 'ipv6' => $enc[0]['address']]],
        ]);
        $this->assertTrue($dec[0]['ok'], (string)($dec[0]['error'] ?? ''));
        $this->assertSame(42, $dec[0]['group_id']);
    }

    public function testDispatchSsm6BadScope(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'ssm6', 'params' => [
                'mode' => 'encode', 'unicast_prefix' => '2001:db8::/64',
                'scope' => 99, 'group_id' => 1,
            ]],
        ]);
        $this->assertFalse($r[0]['ok']);
    }

    public function testDispatchEmbeddedRp6EncodeSuccess(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'embedded-rp6', 'params' => [
                'mode'             => 'encode',
                'rp_address'       => '2001:db8::1',
                'rp_prefix_length' => 64,
                'riid'             => 1,
                'scope'            => 5,
                'group_id'         => 1,
            ]],
        ]);
        $this->assertTrue($r[0]['ok'], (string)($r[0]['error'] ?? ''));
        $this->assertSame('encode', $r[0]['mode']);
        $this->assertArrayHasKey('rp_address', $r[0]);
    }

    public function testDispatchEmbeddedRp6DecodeRoundTrip(): void
    {
        $enc = bulk_dispatch_ops([
            ['op' => 'embedded-rp6', 'params' => [
                'mode' => 'encode', 'rp_address' => '2001:db8::1',
                'rp_prefix_length' => 64, 'riid' => 1, 'scope' => 5, 'group_id' => 1,
            ]],
        ]);
        $this->assertTrue($enc[0]['ok']);
        $dec = bulk_dispatch_ops([
            ['op' => 'embedded-rp6', 'params' => ['mode' => 'decode', 'ipv6' => $enc[0]['address']]],
        ]);
        $this->assertTrue($dec[0]['ok'], (string)($dec[0]['error'] ?? ''));
        $this->assertSame(1, $dec[0]['riid']);
    }

    public function testDispatchEmbeddedRp6MissingField(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'embedded-rp6', 'params' => ['mode' => 'encode']],
        ]);
        $this->assertFalse($r[0]['ok']);
    }

    public function testDispatchPmtu6Success(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'pmtu6', 'params' => ['path_mtu' => 1500, 'payload_size' => 4000]],
        ]);
        $this->assertTrue($r[0]['ok'], (string)($r[0]['error'] ?? ''));
        $this->assertSame(1500, $r[0]['path_mtu']);
        $this->assertTrue($r[0]['needs_fragmentation']);
        $this->assertGreaterThan(1, $r[0]['fragment_count']);
    }

    public function testDispatchPmtu6WithExtensionHeaders(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'pmtu6', 'params' => [
                'path_mtu'          => 1500,
                'payload_size'      => 100,
                'extension_headers' => [8, 16],
            ]],
        ]);
        $this->assertTrue($r[0]['ok'], (string)($r[0]['error'] ?? ''));
        $this->assertSame(24, $r[0]['extension_overhead']);
    }

    public function testDispatchPmtu6MissingField(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'pmtu6', 'params' => ['path_mtu' => 1500]],
        ]);
        $this->assertFalse($r[0]['ok']);
        $this->assertStringContainsString('payload_size', $r[0]['error']);
    }

    public function testDispatchPmtu6BadExtensionHeader(): void
    {
        $r = bulk_dispatch_ops([
            ['op' => 'pmtu6', 'params' => [
                'path_mtu' => 1500, 'payload_size' => 100,
                'extension_headers' => [7],
            ]],
        ]);
        $this->assertFalse($r[0]['ok']);
    }

    public function testDispatchV36MixedBatchAllSucceed(): void
    {
        $items = [
            ['op' => 'multicast6',   'params' => ['ipv6' => 'ff02::1']],
            ['op' => 'ssm6',         'params' => ['mode' => 'encode',
                'unicast_prefix' => '2001:db8::/64', 'scope' => 5, 'group_id' => 1]],
            ['op' => 'embedded-rp6', 'params' => ['mode' => 'encode',
                'rp_address' => '2001:db8::1', 'rp_prefix_length' => 64,
                'riid' => 1, 'scope' => 5, 'group_id' => 1]],
            ['op' => 'pmtu6',        'params' => ['path_mtu' => 1500, 'payload_size' => 100]],
        ];
        $r = bulk_dispatch_ops($items);
        $this->assertCount(4, $r);
        foreach ($r as $i => $env) {
            $this->assertTrue(
                $env['ok'],
                "Item $i ({$env['op']}) should succeed; error: " . ($env['error'] ?? '<none>')
            );
            $this->assertSame($items[$i]['op'], $env['op']);
        }
    }

    public function testDispatchHeterogeneousV34V35V36Batch(): void
    {
        $items = [
            ['op' => 'rdns6',       'params' => ['address' => '2001:db8::1', 'prefix' => 64]],
            ['op' => 'nibble6',     'params' => ['prefix' => '2001:db8::/49']],
            ['op' => 'multicast6',  'params' => ['ipv6' => 'ff02::1']],
            ['op' => 'pmtu6',       'params' => ['path_mtu' => 1500, 'payload_size' => 0]],
        ];
        $r = bulk_dispatch_ops($items);
        $this->assertCount(4, $r);
        foreach ($r as $i => $env) {
            $this->assertTrue(
                $env['ok'],
                "Item $i ({$env['op']}) should succeed; error: " . ($env['error'] ?? '<none>')
            );
        }
    }
}
