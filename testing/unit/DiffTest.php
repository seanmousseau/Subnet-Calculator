<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Subnet-Calculator/includes/functions-ipv6.php';
require_once __DIR__ . '/../../Subnet-Calculator/includes/functions-diff.php';

class DiffTest extends TestCase
{
    public function testIdenticalListsAllUnchanged(): void
    {
        $r = subnet_diff(
            ['10.0.0.0/24', '10.0.1.0/24'],
            ['10.0.0.0/24', '10.0.1.0/24']
        );
        $this->assertSame([], $r['added']);
        $this->assertSame([], $r['removed']);
        $this->assertSame([], $r['changed']);
        $this->assertSame(['10.0.0.0/24', '10.0.1.0/24'], $r['unchanged']);
    }

    public function testPureAdditions(): void
    {
        $r = subnet_diff(
            ['10.0.0.0/24'],
            ['10.0.0.0/24', '10.0.1.0/24', '10.0.2.0/24']
        );
        $this->assertSame(['10.0.1.0/24', '10.0.2.0/24'], $r['added']);
        $this->assertSame([], $r['removed']);
        $this->assertSame([], $r['changed']);
        $this->assertSame(['10.0.0.0/24'], $r['unchanged']);
    }

    public function testPureRemovals(): void
    {
        $r = subnet_diff(
            ['10.0.0.0/24', '10.0.1.0/24', '10.0.2.0/24'],
            ['10.0.0.0/24']
        );
        $this->assertSame([], $r['added']);
        $this->assertSame(['10.0.1.0/24', '10.0.2.0/24'], $r['removed']);
        $this->assertSame([], $r['changed']);
        $this->assertSame(['10.0.0.0/24'], $r['unchanged']);
    }

    public function testPrefixChangeIsInChanged(): void
    {
        $r = subnet_diff(
            ['10.0.0.0/24'],
            ['10.0.0.0/23']
        );
        $this->assertSame([], $r['added']);
        $this->assertSame([], $r['removed']);
        $this->assertSame([], $r['unchanged']);
        $this->assertCount(1, $r['changed']);
        $this->assertSame('10.0.0.0/24', $r['changed'][0]['from']);
        $this->assertSame('10.0.0.0/23', $r['changed'][0]['to']);
        $this->assertSame('prefix changed /24 → /23', $r['changed'][0]['reason']);
    }

    public function testInvalidCidrThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        subnet_diff(['not-a-cidr'], ['10.0.0.0/24']);
    }

    public function testInvalidCidrInAfterThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        subnet_diff(['10.0.0.0/24'], ['bogus/24']);
    }

    public function testMixedV4V6Ordering(): void
    {
        // v4 then v6 in 'added' and 'removed'
        $r = subnet_diff(
            ['2001:db8::/32', '10.0.0.0/24'],
            ['10.0.1.0/24', '2001:db8:1::/48']
        );
        $this->assertSame(['10.0.1.0/24', '2001:db8:1::/48'], $r['added']);
        $this->assertSame(['10.0.0.0/24', '2001:db8::/32'], $r['removed']);
    }

    public function testNonCanonicalInputIsNormalised(): void
    {
        // 10.0.0.5/24 normalises to 10.0.0.0/24; uppercase v6 lowered.
        $r = subnet_diff(
            ['10.0.0.5/24', '2001:DB8::/32'],
            ['10.0.0.0/24', '2001:db8::/32']
        );
        $this->assertSame([], $r['added']);
        $this->assertSame([], $r['removed']);
        $this->assertSame([], $r['changed']);
        $this->assertCount(2, $r['unchanged']);
    }

    public function testMultiplePrefixesShareSameNetworkAreNotCollapsed(): void
    {
        // Regression for CodeRabbit PR #304 review: indexing by network address
        // alone collapsed entries like ["10.0.0.0/24", "10.0.0.0/25"]. The diff
        // must preserve every distinct (network, prefix) pair across both sides.
        $r = subnet_diff(
            ['10.0.0.0/24', '10.0.0.0/25'],
            ['10.0.0.0/26']
        );
        // Common prefix on both sides: none. /24 and /25 on the before side
        // share the network 10.0.0.0; /26 on the after side does too. One pair
        // is reported as "changed" and the leftover before-side prefix is
        // reported as "removed". No prefix is silently lost.
        $changed_to_prefixes = array_map(
            static fn(array $row): string => $row['to'],
            $r['changed']
        );
        $changed_from_prefixes = array_map(
            static fn(array $row): string => $row['from'],
            $r['changed']
        );
        $allPrefixesAccountedFor = array_merge(
            $r['added'],
            $r['removed'],
            $r['unchanged'],
            $changed_from_prefixes,
            $changed_to_prefixes
        );
        sort($allPrefixesAccountedFor);
        $this->assertSame(
            ['10.0.0.0/24', '10.0.0.0/25', '10.0.0.0/26'],
            $allPrefixesAccountedFor,
            'Every input prefix must appear in exactly one bucket; nothing collapsed.'
        );
        // And the after-side /26 must show up, not be silently dropped.
        $this->assertContains('10.0.0.0/26', array_merge($r['added'], $changed_to_prefixes));
    }

    public function testIpv6PrefixChange(): void
    {
        $r = subnet_diff(
            ['2001:db8::/48'],
            ['2001:db8::/56']
        );
        $this->assertCount(1, $r['changed']);
        $this->assertSame('prefix changed /48 → /56', $r['changed'][0]['reason']);
    }
}
