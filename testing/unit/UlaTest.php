<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class UlaTest extends TestCase
{
    // ── generate_ula_prefix ────────────────────────────────────────────────────

    public function testRandomGeneration_ReturnsExpectedKeys(): void
    {
        $r = generate_ula_prefix();
        $this->assertArrayHasKey('prefix', $r);
        $this->assertArrayHasKey('global_id', $r);
        $this->assertArrayHasKey('example_64s', $r);
        $this->assertArrayHasKey('available_64s', $r);
        $this->assertArrayNotHasKey('error', $r);
    }

    public function testRandomGeneration_PrefixIsSlash48(): void
    {
        $r = generate_ula_prefix();
        $this->assertStringEndsWith('::/48', $r['prefix']);
    }

    public function testRandomGeneration_PrefixStartsWithFd(): void
    {
        $r = generate_ula_prefix();
        $this->assertStringStartsWith('fd', $r['prefix']);
    }

    public function testRandomGeneration_GlobalIdIs10HexChars(): void
    {
        $r = generate_ula_prefix();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{10}$/', $r['global_id']);
    }

    public function testRandomGeneration_Has5ExampleSlash64s(): void
    {
        $r = generate_ula_prefix();
        $this->assertCount(5, $r['example_64s']);
        foreach ($r['example_64s'] as $s) {
            $this->assertStringEndsWith('::/64', $s);
        }
    }

    public function testRandomGeneration_Available64sIs65536(): void
    {
        $r = generate_ula_prefix();
        $this->assertSame(65536, $r['available_64s']);
    }

    public function testFixedGlobalId_Deterministic(): void
    {
        $r1 = generate_ula_prefix('aabbccddee');
        $r2 = generate_ula_prefix('aabbccddee');
        $this->assertSame($r1['prefix'], $r2['prefix']);
        $this->assertSame($r1['global_id'], $r2['global_id']);
    }

    public function testFixedGlobalId_CorrectPrefix(): void
    {
        $r = generate_ula_prefix('aabbccddee');
        // fd + aa : bbcc : ddee :: /48
        $this->assertSame('fdaa:bbcc:ddee::/48', $r['prefix']);
    }

    public function testFixedGlobalId_UppercaseNormalised(): void
    {
        $r = generate_ula_prefix('AABBCCDDEE');
        $this->assertSame('fdaa:bbcc:ddee::/48', $r['prefix']);
        $this->assertSame('aabbccddee', $r['global_id']);
    }

    public function testFixedGlobalId_ExampleSlash64sSequential(): void
    {
        $r = generate_ula_prefix('aabbccddee');
        $this->assertSame([
            'fdaa:bbcc:ddee:0000::/64',
            'fdaa:bbcc:ddee:0001::/64',
            'fdaa:bbcc:ddee:0002::/64',
            'fdaa:bbcc:ddee:0003::/64',
            'fdaa:bbcc:ddee:0004::/64',
        ], $r['example_64s']);
    }

    public function testInvalidGlobalId_TooShort(): void
    {
        $r = generate_ula_prefix('aabb');
        $this->assertArrayHasKey('error', $r);
    }

    public function testInvalidGlobalId_TooLong(): void
    {
        $r = generate_ula_prefix('aabbccddeeff');
        $this->assertArrayHasKey('error', $r);
    }

    public function testRandomGeneration_TwoCallsDiffer(): void
    {
        // Statistically near-certain with 40 bits of entropy
        $r1 = generate_ula_prefix();
        $r2 = generate_ula_prefix();
        $this->assertNotSame($r1['global_id'], $r2['global_id']);
    }
}
