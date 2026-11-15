<?php

/**
 * @file
 * An unqualified count() without an import, resolved by PHP to the global function.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\TestCase;

final class AssertCountUnimportedTest extends TestCase
{
    /**
     * @param list<int> $items
     */
    public function testReported(array $items): void
    {
        // @mago-expect analysis:phpunit/assert-same-count
        $this->assertSame(2, count($items));
    }
}
