<?php

use PHPUnit\Framework\TestCase;

final class Test extends TestCase
{
    public function testFoo(): void
    {
        // @mago-expect lint:phpunit/use-specific-assertions
        $this->assertNotEquals(null, $value);
    }
}
