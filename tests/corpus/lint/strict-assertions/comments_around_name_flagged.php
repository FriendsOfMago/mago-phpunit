<?php

use PHPUnit\Framework\TestCase;

final class Test extends TestCase
{
    public function testFoo(mixed $value): void
    {
        // @mago-expect lint:phpunit/strict-assertions
        $this->/* first */assertEquals/* second */(3, $value);
    }
}
