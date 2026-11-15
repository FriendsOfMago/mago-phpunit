<?php

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UseSpecificAssertionsMarkedTest extends TestCase
{
    #[Test]
    public function withAttribute(mixed $result): void
    {
        // @mago-expect lint:phpunit/use-specific-assertions
        $this->assertSame(null, $result);
    }

    /**
     * @test
     */
    public function withTag(mixed $result): void
    {
        // @mago-expect lint:phpunit/use-specific-assertions
        $this->assertSame(null, $result);
    }

    public function helper(mixed $result): void
    {
        $this->assertSame(null, $result);
    }
}
