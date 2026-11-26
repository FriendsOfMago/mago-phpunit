<?php

/**
 * @file
 * Value maps that may be strict. PHPUnit 13 adds `willReturnStrictMap()` and
 * the `$strict` argument of `ReturnValueMap`. A strict map throws instead of
 * returning null when no row matches.
 */

declare(strict_types=1);

// Corpus: PHPUnit 13 and later.
namespace Corpus;

use PHPUnit\Framework\MockObject\Stub\ReturnValueMap;
use PHPUnit\Framework\TestCase;

interface Mapped
{
    public function name(int $id): string;
}

final class MockStrictMapTest extends TestCase
{
    /**
     * A strict map, or one whose `$strict` argument isn't known, isn't reported for a missing row.
     */
    public function testStrictMaps(bool $strict): void
    {
        $stub = $this->createStub(Mapped::class);
        $stub->method('name')->willReturnStrictMap([]);
        $stub->method('name')->willReturnOnConsecutiveCalls(new ReturnValueMap([], $strict));
        $stub->method('name')->willReturnOnConsecutiveCalls(new ReturnValueMap([[1, 'a']], $this->isStrict()));
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnOnConsecutiveCalls(new ReturnValueMap([], false));
        // @mago-expect analysis:phpunit/mock-invalid-return
        $stub->method('name')->willReturnStrictMap([[1, 2]]);
    }

    /**
     * Returns whether a map is strict.
     */
    private function isStrict(): bool
    {
        return true;
    }
}
