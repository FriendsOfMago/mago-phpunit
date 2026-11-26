<?php

/**
 * @file
 * A namespace with its own count() function. An unqualified count() calls
 * that function, not PHP's.
 */

declare(strict_types=1);

namespace Corpus\Counting;

use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertSame;

/**
 * Counts the items in a different way.
 *
 * @param list<int> $items
 */
function count(array $items): int
{
    return \count($items) * 2;
}

final class AssertCountOwnFunctionTest extends TestCase
{
    /**
     * @param list<int> $items
     */
    public function testOwnFunction(array $items): void
    {
        self::assertSame(4, count($items));
        assertSame(4, count($items));
        // @mago-expect analysis:phpunit/assert-same-count
        self::assertSame(2, \count($items));
    }
}
