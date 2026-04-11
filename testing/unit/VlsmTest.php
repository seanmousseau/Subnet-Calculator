<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class VlsmTest extends TestCase
{
    // ── vlsm_allocate ─────────────────────────────────────────────────────────

    public function testBasicAllocation(): void
    {
        $r = vlsm_allocate('192.168.1.0', 24, [
            ['name' => 'LAN A', 'hosts' => 100],
            ['name' => 'LAN B', 'hosts' => 50],
            ['name' => 'WAN',   'hosts' => 2],
        ]);

        $this->assertArrayHasKey('allocations', $r);
        $this->assertCount(3, $r['allocations']);

        // Largest-first: LAN A (100 hosts) → /25 (126 usable)
        $this->assertSame('LAN A', $r['allocations'][0]['name']);
        $this->assertSame('192.168.1.0/25', $r['allocations'][0]['subnet']);
        $this->assertSame(126, $r['allocations'][0]['usable']);

        // LAN B (50 hosts) → /26 (62 usable)
        $this->assertSame('LAN B', $r['allocations'][1]['name']);
        $this->assertSame('192.168.1.128/26', $r['allocations'][1]['subnet']);
        $this->assertSame(62, $r['allocations'][1]['usable']);
    }

    public function testNoRequirements(): void
    {
        $r = vlsm_allocate('10.0.0.0', 24, []);
        $this->assertArrayHasKey('error', $r);
    }

    public function testOverCapacity(): void
    {
        $r = vlsm_allocate('192.168.1.0', 28, [
            ['name' => 'BigLAN', 'hosts' => 200],
        ]);
        $this->assertArrayHasKey('error', $r);
    }

    public function testPointToPoint(): void
    {
        $r = vlsm_allocate('10.0.0.0', 30, [
            ['name' => 'Link', 'hosts' => 2],
        ]);
        $this->assertArrayHasKey('allocations', $r);
        // /30 has 2 usable, /31 would also work — just verify it allocated
        $this->assertSame('Link', $r['allocations'][0]['name']);
        $this->assertGreaterThanOrEqual(2, $r['allocations'][0]['usable']);
    }

    public function testWasteCalculation(): void
    {
        $r = vlsm_allocate('10.0.0.0', 24, [
            ['name' => 'Small', 'hosts' => 10],
        ]);
        $this->assertArrayHasKey('allocations', $r);
        $alloc = $r['allocations'][0];
        $this->assertSame($alloc['usable'] - $alloc['hosts_needed'], $alloc['waste']);
    }

    public function testSubnetsDoNotOverlap(): void
    {
        $r = vlsm_allocate('10.0.0.0', 20, [
            ['name' => 'A', 'hosts' => 500],
            ['name' => 'B', 'hosts' => 200],
            ['name' => 'C', 'hosts' => 100],
        ]);
        $this->assertArrayHasKey('allocations', $r);

        // Extract all network ranges and verify no overlap
        $ranges = array_map(fn($a) => $a['subnet'], $r['allocations']);
        foreach ($ranges as $i => $a) {
            foreach ($ranges as $j => $b) {
                if ($i >= $j) {
                    continue;
                }
                $this->assertSame(
                    'none',
                    cidrs_overlap($a, $b),
                    "Subnets {$a} and {$b} should not overlap"
                );
            }
        }
    }
}
