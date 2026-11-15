<?php

use PHPUnit\Framework\TestCase;

final class Test extends TestCase
{
    public function testFoo(): void
    {
        self::assertEquals(Money::EUR('100'), $result);
    }
}
