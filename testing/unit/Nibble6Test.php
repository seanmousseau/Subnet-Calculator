<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Nibble6Test extends TestCase
{
    public function test_49_neighbours(): void
    {
        $r = nibble_neighbours('2001:db8::/49');
        $this->assertSame(48, $r['above']['length']);
        $this->assertSame(52, $r['below']['length']);
        $this->assertSame('2001:db8::/48', $r['above']['prefix']);
        $this->assertSame('2001:db8::/52', $r['below']['prefix']);
    }

    public function test_aligned_input_returns_self(): void
    {
        // /48 is already nibble-aligned: above stays at /48, below is the next
        // deeper nibble (/52).
        $r = nibble_neighbours('2001:db8::/48');
        $this->assertSame(48, $r['above']['length']);
        $this->assertSame(52, $r['below']['length']);
        $this->assertSame('2001:db8::/48', $r['above']['prefix']);
        $this->assertSame('2001:db8::/52', $r['below']['prefix']);
    }

    public function test_boundary_at_128(): void
    {
        // /128 has no further refinement — below collapses to /128 itself.
        $r = nibble_neighbours('2001:db8::1/128');
        $this->assertSame(128, $r['below']['length']);
        $this->assertSame(128, $r['above']['length']);
    }

    public function test_input_zero_prefix(): void
    {
        $r = nibble_neighbours('::/0');
        $this->assertSame(0, $r['above']['length']);
        $this->assertSame(4, $r['below']['length']);
        $this->assertSame('::/0', $r['above']['prefix']);
        $this->assertSame('::/4', $r['below']['prefix']);
    }

    public function test_input_127_below_caps_at_128(): void
    {
        // /127 → above=/124, below=/128 (cap).
        $r = nibble_neighbours('2001:db8::/127');
        $this->assertSame(124, $r['above']['length']);
        $this->assertSame(128, $r['below']['length']);
        $this->assertSame('2001:db8::/124', $r['above']['prefix']);
    }

    public function test_input_1(): void
    {
        // /1 → above=/0, below=/4.
        $r = nibble_neighbours('::/1');
        $this->assertSame(0, $r['above']['length']);
        $this->assertSame(4, $r['below']['length']);
        $this->assertSame('::/0', $r['above']['prefix']);
        $this->assertSame('::/4', $r['below']['prefix']);
    }

    public function test_input_3(): void
    {
        // /3 → above=/0, below=/4.
        $r = nibble_neighbours('2000::/3');
        $this->assertSame(0, $r['above']['length']);
        $this->assertSame(4, $r['below']['length']);
        // 2000::/3 above-snapped to /0 zeroes all bits.
        $this->assertSame('::/0', $r['above']['prefix']);
        $this->assertSame('2000::/4', $r['below']['prefix']);
    }

    public function test_input_125(): void
    {
        // /125 → above=/124, below=/128.
        $r = nibble_neighbours('2001:db8::/125');
        $this->assertSame(124, $r['above']['length']);
        $this->assertSame(128, $r['below']['length']);
    }

    public function test_input_carries_input_metadata(): void
    {
        $r = nibble_neighbours('2001:db8::/49');
        $this->assertSame(49, $r['input']['length']);
        // Input prefix is canonicalised (host bits zeroed beyond input length).
        $this->assertSame('2001:db8::/49', $r['input']['prefix']);
    }

    public function test_host_bits_canonicalised_above(): void
    {
        // /49 with host bits set in the input — above (/48) zeroes them.
        $r = nibble_neighbours('2001:db8:0:abcd::/49');
        // The /49 boundary keeps the high bit of the 4th hextet (a=1010 → bit 0
        // of the top nibble keeps with /49; rest is zeroed within the /49).
        // Above is /48 — zero all of hextet 4 onwards.
        $this->assertSame('2001:db8::/48', $r['above']['prefix']);
    }

    public function test_contains_64s_above_below(): void
    {
        $r = nibble_neighbours('2001:db8::/49');
        // /48 contains 2^16 /64s = 65536.
        $this->assertSame('65536', $r['above']['contains_64s']);
        // /52 contains 2^12 /64s = 4096.
        $this->assertSame('4096', $r['below']['contains_64s']);
    }

    public function test_contains_64s_at_64_boundary(): void
    {
        // Input /63 → above=/60, below=/64.
        $r = nibble_neighbours('2001:db8::/63');
        $this->assertSame(60, $r['above']['length']);
        $this->assertSame(64, $r['below']['length']);
        // /60 contains 2^4 = 16 /64s.
        $this->assertSame('16', $r['above']['contains_64s']);
        // /64 is exactly itself.
        $this->assertSame('1', $r['below']['contains_64s']);
    }

    public function test_contains_64s_smaller_than_64(): void
    {
        // /67 → above=/64, below=/68.
        $r = nibble_neighbours('2001:db8::/67');
        $this->assertSame('1', $r['above']['contains_64s']);
        $this->assertSame('subset of /64', $r['below']['contains_64s']);
    }

    public function test_invalid_missing_prefix_length(): void
    {
        $this->expectException(InvalidArgumentException::class);
        nibble_neighbours('2001:db8::');
    }

    public function test_invalid_address(): void
    {
        $this->expectException(InvalidArgumentException::class);
        nibble_neighbours('not-an-address/48');
    }

    public function test_invalid_length_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        nibble_neighbours('2001:db8::/-1');
    }

    public function test_invalid_length_too_large(): void
    {
        $this->expectException(InvalidArgumentException::class);
        nibble_neighbours('2001:db8::/129');
    }

    public function test_empty_input_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        nibble_neighbours('');
    }
}
