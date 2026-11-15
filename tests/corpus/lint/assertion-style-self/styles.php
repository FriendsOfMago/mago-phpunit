<?php

use PHPUnit\Framework\TestCase;

final class StyleTest extends TestCase
{
    public function testStyles(): void
    {
        // @mago-expect lint:phpunit/assertion-style-self
        $this->assertSame(1, 1);
        // @mago-expect lint:phpunit/assertion-style-self
        static::assertSame(1, 1);
        self::assertSame(1, 1);
        parent::assertSame(1, 1);
        // @mago-expect lint:phpunit/assertion-style-self
        $this
            ->assertTrue(true);
        // @mago-expect lint:phpunit/assertion-style-self
        static :: assertTrue(true);
        // @mago-expect lint:phpunit/assertion-style-self
        $this->markTestSkipped('x');
        self::isTrue();
        // @mago-expect lint:phpunit/assertion-style-self
        $check = $this->assertNull(...);
        $closure = function (): void { $this->assertTrue(true); };
        $arrow = fn() => self::assertTrue(true);
        // @mago-expect lint:phpunit/assertion-style-self
        new class ($this->assertTrue(true)) { public function x(): void { self::assertTrue(true); } };
        $this->helper();
        // @mago-expect lint:phpunit/assertion-style-self
        if ($x) { self::assertFalse(false); } else { $this->assertFalse(false); }
        // @mago-expect lint:phpunit/assertion-style-self
        foreach ([1] as $i) { try { self::assertSame(1, $i); } catch (\Throwable) { $this->fail(); } }
        // @mago-expect lint:phpunit/assertion-style-self(2)
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
        self::assertTrue(true);
    }
}
