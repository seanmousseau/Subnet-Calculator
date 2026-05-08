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
}
