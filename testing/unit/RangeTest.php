<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class RangeTest extends TestCase
{
    // ── range_to_cidrs ────────────────────────────────────────────────────────

    public function testRange_ExactSlash24(): void
    {
        $r = range_to_cidrs('10.0.0.0', '10.0.0.255');
        $this->assertSame(['10.0.0.0/24'], $r['cidrs']);
    }

    public function testRange_ExactSlash16(): void
    {
        $r = range_to_cidrs('10.0.0.0', '10.0.255.255');
        $this->assertSame(['10.0.0.0/16'], $r['cidrs']);
    }

    public function testRange_SingleIp(): void
    {
        $r = range_to_cidrs('10.0.0.5', '10.0.0.5');
        $this->assertSame(['10.0.0.5/32'], $r['cidrs']);
    }

    public function testRange_NonPowerOfTwo_FiveAddresses(): void
    {
        // 10.0.0.0–10.0.0.4 = /30 (4 addresses) + /32 (1 address)
        $r = range_to_cidrs('10.0.0.0', '10.0.0.4');
        $this->assertSame(['10.0.0.0/30', '10.0.0.4/32'], $r['cidrs']);
    }

    public function testRange_MisalignedStart(): void
    {
        // 10.0.0.1–10.0.0.6 cannot start with a block larger than /32 at 10.0.0.1
        $r = range_to_cidrs('10.0.0.1', '10.0.0.6');
        $this->assertArrayHasKey('cidrs', $r);
        $cidrs = $r['cidrs'];
        // The result must cover every address from .1 to .6 exactly once.
        $covered = [];
        foreach ($cidrs as $cidr) {
            [$ip, $prefix] = explode('/', $cidr);
            $net = ip2long($ip) & 0xFFFFFFFF;
            $size = 1 << (32 - (int)$prefix);
            for ($i = 0; $i < $size; $i++) {
                $covered[] = $net + $i;
            }
        }
        sort($covered);
        $expected = range(ip2long('10.0.0.1') & 0xFFFFFFFF, ip2long('10.0.0.6') & 0xFFFFFFFF);
        $this->assertSame($expected, $covered);
    }

    public function testRange_EntireAddressSpace(): void
    {
        $r = range_to_cidrs('0.0.0.0', '255.255.255.255');
        $this->assertSame(['0.0.0.0/0'], $r['cidrs']);
    }

    public function testRange_ClassB(): void
    {
        $r = range_to_cidrs('172.16.0.0', '172.31.255.255');
        $this->assertSame(['172.16.0.0/12'], $r['cidrs']);
    }

    public function testRange_EndLessThanStart_ReturnsError(): void
    {
        $r = range_to_cidrs('10.0.0.10', '10.0.0.1');
        $this->assertArrayHasKey('error', $r);
        $this->assertStringContainsStringIgnoringCase('less than or equal', $r['error']);
    }

    public function testRange_InvalidStartIp_ReturnsError(): void
    {
        $r = range_to_cidrs('not-an-ip', '10.0.0.255');
        $this->assertArrayHasKey('error', $r);
        $this->assertStringContainsStringIgnoringCase('start', $r['error']);
    }

    public function testRange_InvalidEndIp_ReturnsError(): void
    {
        $r = range_to_cidrs('10.0.0.0', 'not-an-ip');
        $this->assertArrayHasKey('error', $r);
        $this->assertStringContainsStringIgnoringCase('end', $r['error']);
    }

    public function testRange_TwoAdjacentSlash32s(): void
    {
        $r = range_to_cidrs('10.0.0.0', '10.0.0.1');
        $this->assertSame(['10.0.0.0/31'], $r['cidrs']);
    }

    public function testRange_StartEqualsEnd_LoopbackSingleIp(): void
    {
        $r = range_to_cidrs('127.0.0.1', '127.0.0.1');
        $this->assertSame(['127.0.0.1/32'], $r['cidrs']);
    }
}
