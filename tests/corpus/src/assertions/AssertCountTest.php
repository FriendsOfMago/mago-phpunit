<?php

/**
 * @file
 * Counts compared with assertSame() instead of checked with assertCount().
 */

declare(strict_types=1);

namespace Corpus;

use ArrayObject;
use PHPUnit\Framework\TestCase;

use function count;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertSame as verify;

enum Sizes: int implements \Countable
{
    case One = 1;

    /**
     * Returns the size.
     */
    public function count(): int
    {
        return $this->value;
    }
}

final class AssertCountTest extends TestCase
{
    /**
     * @param list<int> $items
     * @param ArrayObject<int, int> $object
     */
    public function testReported(array $items, ArrayObject $object): void
    {
        // @mago-expect analysis:phpunit/assert-same-count
        $this->assertSame(2, count($items));
        // @mago-expect analysis:phpunit/assert-same-count
        self::assertSame(2, \count($items));
        // @mago-expect analysis:phpunit/assert-same-count
        $this->assertSame(2, $object->count());
    }

    /**
     * The `assertSame()` function, also under another name, and a value
     * whose intersection type has `Countable` in it.
     *
     * @param list<int> $items
     */
    public function testOtherForms(array $items, Clock&\Countable $clock): void
    {
        // @mago-expect analysis:phpunit/assert-same-count
        assertSame(2, count($items));
        // @mago-expect analysis:phpunit/assert-same-count
        verify(2, count($items));
        // @mago-expect analysis:phpunit/assert-same-count
        verify(2, count(value: $items));
        // @mago-expect analysis:phpunit/assert-same-count
        self::assertSame(2, $clock->count());
        $mock = $this->createStubForIntersectionOfInterfaces([Clock::class, \Countable::class]);
        // @mago-expect analysis:phpunit/assert-same-count
        self::assertSame(0, $mock->count());
        // @mago-expect analysis:phpunit/assert-same-count
        self::assertSame(1, Sizes::One->count());
    }

    /**
     * A value that may be null isn't reported, and neither is an unpacked
     * `count()` argument, which may hold a mode.
     *
     * @param list<list<int>> $nested
     * @param ArrayObject<int, int>|null $maybe
     * @param array{list<int>, int} $arguments
     */
    public function testNotReported(array $nested, Clock $clock, ?ArrayObject $maybe, array $arguments): void
    {
        self::assertSame(2, $maybe?->count());
        $this->assertSame('2', count($nested));
        $this->assertSame(2.0, count($nested));
        self::assertSame(2, count(...$arguments));
        $this->assertCount(2, $nested);
        $this->assertSame(4, count($nested, COUNT_RECURSIVE));
        $this->assertSame(count($nested), 2);
        $this->assertSame(2, $clock->now());
    }
}
