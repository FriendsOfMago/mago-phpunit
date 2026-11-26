<?php

/**
 * @file
 * Methods of PHP's own classes that Mago's definitions of them don't have,
 * such as `RegexIterator::getMode()`. PHP's reflection finds them.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\TestCase;

final class MockBuilderBuiltinTest extends TestCase
{
    public function testBuiltinMethods(): void
    {
        $this
            ->getMockBuilder(\RegexIterator::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMode'])
            ->getMock();
        $this
            ->getMockBuilder(\ArrayObject::class)
            ->onlyMethods(['__debugInfo'])
            ->getMock();
        $this
            ->getMockBuilder(\ArrayObject::class)
            // @mago-expect analysis:phpunit/mock-undefined-method
            ->onlyMethods(['notThere'])
            ->getMock();
        $this->addToAssertionCount(1);
    }
}
