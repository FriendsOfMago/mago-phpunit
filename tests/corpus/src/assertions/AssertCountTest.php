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
     * @param list<list<int>> $nested
     */
    public function testNotReported(array $nested, Clock $clock): void
    {
        $this->assertCount(2, $nested);
        $this->assertSame(4, count($nested, COUNT_RECURSIVE));
        $this->assertSame(count($nested), 2);
        $this->assertSame(2, $clock->now());
    }
}
