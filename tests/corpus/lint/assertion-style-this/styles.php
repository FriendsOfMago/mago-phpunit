<?php

use PHPUnit\Framework\TestCase;

final class StyleTest extends TestCase
{
    public function testStyles(): void
    {
        $this->assertSame(1, 1);
        // @mago-expect lint:phpunit/assertion-style-this
        static::assertSame(1, 1);
        // @mago-expect lint:phpunit/assertion-style-this
        self::assertSame(1, 1);
        parent::assertSame(1, 1);
        $this
            ->assertTrue(true);
        // @mago-expect lint:phpunit/assertion-style-this
        static :: assertTrue(true);
        $this->markTestSkipped('x');
        // @mago-expect lint:phpunit/assertion-style-this
        self::isTrue();
        $check = $this->assertNull(...);
        $closure = function (): void { $this->assertTrue(true); };
        $arrow = fn() => self::assertTrue(true);
        new class ($this->assertTrue(true)) { public function x(): void { self::assertTrue(true); } };
        $this->helper();
        // @mago-expect lint:phpunit/assertion-style-this
        if ($x) { self::assertFalse(false); } else { $this->assertFalse(false); }
        // @mago-expect lint:phpunit/assertion-style-this
        foreach ([1] as $i) { try { self::assertSame(1, $i); } catch (\Throwable) { $this->fail(); } }
        // @mago-expect lint:phpunit/assertion-style-this
        $this->assertSame($this->assertTrue(true), self::assertTrue(true));
    }

    public function helper(): void
    {
        self::assertTrue(true);
    }

    public function test(): void
    {
        self::assertTrue(true);
    }

    public function test_snake(): void
    {
        // @mago-expect lint:phpunit/assertion-style-this
        self::assertTrue(true);
    }
}
