<?php

use PHPUnit\Framework\TestCase;

final class SpecificEdgesTest extends TestCase
{
    /**
     * @param list<mixed> $rest
     */
    public function testEdges(mixed $value, array $rest): void
    {
        // An unpacked argument is reported without a fix.
        // @mago-expect lint:phpunit/use-specific-assertions
        self::assertSame(null, ...$rest);
        // A named argument in a nested call doesn't block the fix.
        // @mago-expect lint:phpunit/use-specific-assertions
        self::assertSame(null, self::id(a: 1, b: $value));
        // @mago-expect lint:phpunit/use-specific-assertions
        self::assertSame(expected: $value, actual: null);
        // @mago-expect lint:phpunit/use-specific-assertions
        self::assertSame($value, null, 'The value is null.');
        // @mago-expect lint:phpunit/use-specific-assertions
        self::assertSame(null, $value, 'The value is null.');
    }

    private static function id(int $a, mixed $b): mixed
    {
        return $b;
    }
}
