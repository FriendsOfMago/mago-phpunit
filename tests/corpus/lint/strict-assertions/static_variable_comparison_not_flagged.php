<?php

use PHPUnit\Framework\TestCase;

final class Test extends TestCase
{
    public function testFoo(): void
    {
        static::assertNotEquals($obj1, $obj2);
    }
}
