<?php

use PHPUnit\Framework\TestCase;

final class Test extends TestCase
{
    public function testFoo(): void
    {
        $this->assertEquals(42, $value);
        self::assertSame('foo', $bar);
    }
}
