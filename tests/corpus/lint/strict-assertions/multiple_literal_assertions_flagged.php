<?php

use PHPUnit\Framework\TestCase;

final class Test extends TestCase
{
    public function testFoo(): void
    {
        // @mago-expect lint:phpunit/strict-assertions
        $this->assertEquals(42, $a);
        // @mago-expect lint:phpunit/strict-assertions
        self::assertEquals('bar', $b);
        // @mago-expect lint:phpunit/strict-assertions
        static::assertNotEquals(1.5, $c);
    }
}
