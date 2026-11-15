<?php

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AssertionStyleStaticMarkedTest extends TestCase
{
    #[Test]
    public function withAttribute(mixed $result): void
    {
        // @mago-expect lint:phpunit/assertion-style-static
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function withTag(mixed $result): void
    {
        // @mago-expect lint:phpunit/assertion-style-static
        $this->assertTrue($result);
    }

    public function helper(mixed $result): void
    {
        $this->assertTrue($result);
    }
}
