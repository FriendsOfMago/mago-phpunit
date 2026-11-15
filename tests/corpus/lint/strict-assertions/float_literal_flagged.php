<?php

use PHPUnit\Framework\TestCase;

final class Test extends TestCase
{
    public function testFoo(): void
    {
        // @mago-expect lint:phpunit/strict-assertions
        static::assertEquals(3.14, $value);
    }
}
