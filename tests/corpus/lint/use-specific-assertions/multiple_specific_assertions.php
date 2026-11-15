<?php

use PHPUnit\Framework\TestCase;

final class Test extends TestCase
{
    public function testFoo(): void
    {
        // @mago-expect lint:phpunit/use-specific-assertions
        $this->assertEquals(null, $a);
        // @mago-expect lint:phpunit/use-specific-assertions
        self::assertSame(true, $b);
        // @mago-expect lint:phpunit/use-specific-assertions
        static::assertEquals(false, $c);
    }
}
