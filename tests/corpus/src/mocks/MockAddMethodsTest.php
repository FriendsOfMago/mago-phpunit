<?php

/**
 * @file
 * A mock builder that adds a method. `addMethods()` doesn't exist in PHPUnit
 * 12 and later.
 */

// Corpus: PHPUnit 11 and earlier.

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\TestCase;

final class MockAddMethodsTest extends TestCase
{
    public function testAddedMethod(): void
    {
        // @mago-expect analysis:deprecated-method
        $mock = $this->getMockBuilder(Thing::class)->addMethods(['extra'])->getMock();
        $mock->method('extra')->willReturn(null);
    }
}
