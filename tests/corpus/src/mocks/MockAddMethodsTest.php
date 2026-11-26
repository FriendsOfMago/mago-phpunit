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

    public function testExistingMethod(): void
    {
        // @mago-expect analysis:deprecated-method
        // @mago-expect analysis:phpunit/mock-added-method-exists
        $this->getMockBuilder(Thing::class)->addMethods(['onlyOnThing']);
        $this->addToAssertionCount(1);
    }

    /**
     * The real method behind an `@method` tag exists.
     */
    public function testRetagged(): void
    {
        // @mago-expect analysis:deprecated-method
        // @mago-expect analysis:phpunit/mock-added-method-exists
        $this->getMockBuilder(RetaggedThing::class)->addMethods(['onlyOnThing']);
        $this->addToAssertionCount(1);
    }
}
