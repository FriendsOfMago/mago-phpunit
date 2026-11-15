<?php

use PHPUnit\Framework\TestCase;

final class StyleTest extends TestCase
{
    public function testStyles(): void
    {
        // @mago-expect lint:phpunit/assertion-style-static
        $this->assertSame(1, 1);
        static::assertSame(1, 1);
        // @mago-expect lint:phpunit/assertion-style-static
        self::assertSame(1, 1);
        parent::assertSame(1, 1);
        // @mago-expect lint:phpunit/assertion-style-static
        $this
            ->assertTrue(true);
        static :: assertTrue(true);
        // @mago-expect lint:phpunit/assertion-style-static
        $this->markTestSkipped('x');
        // @mago-expect lint:phpunit/assertion-style-static
        self::isTrue();
        // @mago-expect lint:phpunit/assertion-style-static
        $check = $this->assertNull(...);
        $closure = function (): void { $this->assertTrue(true); };
        $arrow = fn() => self::assertTrue(true);
        // @mago-expect lint:phpunit/assertion-style-static
        new class ($this->assertTrue(true)) { public function x(): void { self::assertTrue(true); } };
        $this->helper();
        // @mago-expect lint:phpunit/assertion-style-static(2)
        if ($x) { self::assertFalse(false); } else { $this->assertFalse(false); }
        // @mago-expect lint:phpunit/assertion-style-static(2)
        foreach ([1] as $i) { try { self::assertSame(1, $i); } catch (\Throwable) { $this->fail(); } }
        // @mago-expect lint:phpunit/assertion-style-static(3)
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
        // @mago-expect lint:phpunit/assertion-style-static
        self::assertTrue(true);
    }
}
