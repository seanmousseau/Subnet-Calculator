<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @requires extension gmp
 */
class Vlsm6Test extends TestCase
{
    public function testSingleRequirementFits(): void
    {
        $r = vlsm6_allocate('2001:db8::', 32, [['name' => 'site-a', 'hosts' => 256]]);
        $this->assertArrayHasKey('allocations', $r);
        $this->assertSame('site-a', $r['allocations'][0]['name']);
        $this->assertSame('2001:db8::/120', $r['allocations'][0]['subnet']);
        $this->assertSame(256, $r['allocations'][0]['usable']);
    }

    public function testLargestFirstAlignment(): void
    {
        $r = vlsm6_allocate('2001:db8::', 48, [
            ['name' => 'small', 'hosts' => 4],
            ['name' => 'large', 'hosts' => 65536],
        ]);
        $allocs = $r['allocations'];
        $this->assertSame('large', $allocs[0]['name']);
        $this->assertSame('2001:db8::/112', $allocs[0]['subnet']);
        $this->assertSame('small', $allocs[1]['name']);
        $this->assertSame('2001:db8::1:0/126', $allocs[1]['subnet']);
    }

    public function testInsufficientSpaceErrors(): void
    {
        $r = vlsm6_allocate('2001:db8::', 126, [['name' => 'x', 'hosts' => 32]]);
        $this->assertArrayHasKey('error', $r);
        $this->assertStringContainsString('x', $r['error']);
    }

    public function testInvalidHostCountRejected(): void
    {
        $r = vlsm6_allocate('2001:db8::', 64, [['name' => 'x', 'hosts' => 0]]);
        $this->assertArrayHasKey('error', $r);
    }

    public function testEmptyRequirementsRejected(): void
    {
        $r = vlsm6_allocate('2001:db8::', 64, []);
        $this->assertArrayHasKey('error', $r);
    }

    public function testHugeAllocationReturns2NString(): void
    {
        $r = vlsm6_allocate('2001:db8::', 32, [['name' => 'huge', 'hosts' => '2^96']]);
        $this->assertArrayHasKey('allocations', $r);
        $this->assertSame('2001:db8::/32', $r['allocations'][0]['subnet']);
        $this->assertSame('2^96', $r['allocations'][0]['usable']);
    }
}
