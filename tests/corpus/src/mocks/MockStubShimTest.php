<?php

/**
 * @file
 * PHPUnit 12 removes `TestCase::returnValue()`, so a method of the same
 * name in a test class is the class's own, and its result isn't known.
 */

declare(strict_types=1);

// Corpus: PHPUnit 12 and later.
namespace Corpus;

use PHPUnit\Framework\MockObject\Stub\ReturnStub;
use PHPUnit\Framework\TestCase;

interface Shimmed
{
    public function name(): string;
}

final class MockStubShimTest extends TestCase
{
    public function testOwnHelper(): void
    {
        $stub = $this->createStub(Shimmed::class);
        $stub->method('name')->will(self::returnValue(5));
        $this->assertSame('fixed', $stub->name());
    }

    /**
     * Returns a stub that returns a fixed name.
     */
    private static function returnValue(mixed $value): ReturnStub
    {
        unset($value);

        return new ReturnStub('fixed');
    }
}
