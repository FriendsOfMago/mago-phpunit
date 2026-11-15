<?php

use PHPUnit\Framework\TestCase;

final class Test extends TestCase
{
    public function testFoo(): void
    {
        // @mago-expect lint:phpunit/strict-assertions
        $this->assertNotEquals(42, $value);
    }
}
