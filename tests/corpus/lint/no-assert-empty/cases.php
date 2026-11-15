<?php

use PHPUnit\Framework\TestCase;

final class NoAssertEmptyTest extends TestCase
{
    public function testEmpty(): void
    {
        // @mago-expect lint:phpunit/no-assert-empty
        $this->assertEmpty([]);
        // @mago-expect lint:phpunit/no-assert-empty
        self::assertNotEmpty('a');
        // @mago-expect lint:phpunit/no-assert-empty
        static::AssertEmpty(null);
    }

    /**
     * A helper method is checked too.
     */
    private function assertNothing(array $values): void
    {
        // @mago-expect lint:phpunit/no-assert-empty
        $this->assertEmpty($values);
    }

    public function testStrict(): void
    {
        $this->assertSame([], []);
        $this->assertCount(0, []);
        $this->assertNothing([]);
    }
}
