<?php

/**
 * @file
 * A count() function imported from another namespace, which isn't known to
 * Mago. PHP doesn't fall back to the global function for an imported name.
 */

declare(strict_types=1);

namespace Corpus\Imported;

use PHPUnit\Framework\TestCase;

use function Vendor\Missing\count;

final class AssertCountImportedTest extends TestCase
{
    /**
     * @param list<int> $items
     */
    public function testImportedFunction(array $items): void
    {
        self::assertSame(2, count($items));
    }
}
