<?php

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StrictAssertionsMarkedTest extends TestCase
{
    #[Test]
    public function withAttribute(mixed $result): void
    {
        // @mago-expect lint:phpunit/strict-assertions
        $this->assertEquals(42, $result);
    }

    /**
     * @test
     */
    public function withTag(mixed $result): void
    {
        // @mago-expect lint:phpunit/strict-assertions
        $this->assertEquals(42, $result);
    }

    public function helper(mixed $result): void
    {
        $this->assertEquals(42, $result);
    }
}
