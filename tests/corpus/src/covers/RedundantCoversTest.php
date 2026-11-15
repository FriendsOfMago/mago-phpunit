<?php

/**
 * @file
 * Method @covers tags with the same target as one of the class. PHPUnit parses them before version 12.
 */

// Corpus: PHPUnit 11 and earlier.

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Corpus\Thing
 * @covers \Corpus\coveredFunction
 */
final class RedundantCoversTest extends TestCase
{
    // @mago-expect analysis:phpunit/redundant-covers
    /**
     * @covers \Corpus\Thing
     */
    public function testSameTag(): void
    {
        $this->addToAssertionCount(1);
    }

    // @mago-expect analysis:phpunit/redundant-covers(2)
    /**
     * Without the leading backslash and in another case, the tags cover the same code.
     *
     * @covers Corpus\Thing
     * @covers \corpus\COVEREDFUNCTION
     */
    public function testSameTarget(): void
    {
        $this->addToAssertionCount(1);
    }

    /**
     * @covers \Corpus\Other
     * @covers \Corpus\Thing::onlyOnThing
     */
    public function testOtherTargets(): void
    {
        $this->addToAssertionCount(1);
    }

    /**
     * PHPUnit ignores the annotations of a method with a PHPUnit attribute.
     *
     * @covers \Corpus\Thing
     */
    #[Test]
    public function withAttribute(): void
    {
        $this->addToAssertionCount(1);
    }
}
