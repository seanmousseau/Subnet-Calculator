<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class WildcardTest extends TestCase
{
    // ── cidr_to_wildcard ──────────────────────────────────────────────────────

    public function testCidrToWildcard_Slash24(): void
    {
        $this->assertSame('0.0.0.255', cidr_to_wildcard('/24'));
    }

    public function testCidrToWildcard_BareNumber(): void
    {
        $this->assertSame('0.0.0.255', cidr_to_wildcard('24'));
    }

    public function testCidrToWildcard_Slash16(): void
    {
        $this->assertSame('0.0.255.255', cidr_to_wildcard('/16'));
    }

    public function testCidrToWildcard_Slash32(): void
    {
        $this->assertSame('0.0.0.0', cidr_to_wildcard('/32'));
    }

    public function testCidrToWildcard_Slash0(): void
    {
        $this->assertSame('255.255.255.255', cidr_to_wildcard('/0'));
    }

    public function testCidrToWildcard_Slash30(): void
    {
        $this->assertSame('0.0.0.3', cidr_to_wildcard('/30'));
    }

    public function testCidrToWildcard_RejectsOutOfRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        cidr_to_wildcard('/33');
    }

    public function testCidrToWildcard_RejectsNonNumeric(): void
    {
        $this->expectException(InvalidArgumentException::class);
        cidr_to_wildcard('/abc');
    }

    // ── wildcard_to_cidr ──────────────────────────────────────────────────────

    public function testWildcardToCidr_Zero255(): void
    {
        $this->assertSame('/24', wildcard_to_cidr('0.0.0.255'));
    }

    public function testWildcardToCidr_AllZero(): void
    {
        $this->assertSame('/32', wildcard_to_cidr('0.0.0.0'));
    }

    public function testWildcardToCidr_AllOnes(): void
    {
        $this->assertSame('/0', wildcard_to_cidr('255.255.255.255'));
    }

    public function testWildcardToCidr_Slash30(): void
    {
        $this->assertSame('/30', wildcard_to_cidr('0.0.0.3'));
    }

    public function testWildcardToCidr_RejectsNonContiguous(): void
    {
        $this->expectException(InvalidArgumentException::class);
        wildcard_to_cidr('0.0.255.0');
    }

    public function testWildcardToCidr_RejectsBadOctet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        wildcard_to_cidr('256.0.0.0');
    }

    public function testWildcardToCidr_RejectsNonDottedQuad(): void
    {
        $this->expectException(InvalidArgumentException::class);
        wildcard_to_cidr('not an ip');
    }
}
