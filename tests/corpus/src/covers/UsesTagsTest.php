<?php

/**
 * @file
 * `@uses` tags, which are parsed by PHPUnit before version 12. PHPUnit maps
 * them like `@covers` tags, but accepts an interface.
 */

// Corpus: PHPUnit 11 and earlier.

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\TestCase;

// @mago-expect analysis:phpunit/invalid-covers(2)
/**
 * @usesDefaultClass \Corpus\Thing
 * @uses ::onlyOnThing
 * @uses ::missing
 * @uses \Corpus\Clock
 * @uses \Corpus\Missing
 */
final class UsesTagsTest extends TestCase
{
    // @mago-expect analysis:phpunit/invalid-covers
    /**
     * @usesDefaultClass \Corpus\Thing
     */
    public function testDefaultOnMethod(): void
    {
        $this->addToAssertionCount(1);
    }

    // @mago-expect analysis:phpunit/invalid-covers
    /**
     * @uses \Corpus\Thing::missing
     */
    public function testMissingMethod(): void
    {
        $this->addToAssertionCount(1);
    }

    /**
     * PHPUnit only reads the tags of a test method.
     *
     * @uses \Corpus\Missing
     */
    protected function setUp(): void
    {
        parent::setUp();
    }

    // @mago-expect analysis:phpunit/invalid-covers
    /**
     * @uses self::helper()
     */
    public function testSelfTarget(): void
    {
        $this->addToAssertionCount(1);
    }

    /**
     * A helper with a tag that isn't read by PHPUnit.
     *
     * @uses self::helper()
     */
    public static function helper(): void {}
}

/**
 * PHPUnit doesn't collect coverage for the class's tests.
 *
 * @coversNothing
 * @uses \Corpus\Missing
 */
final class UsesWithCoversNothingTest extends TestCase
{
    /**
     * @coversNothing
     * @uses \Corpus\Missing
     */
    public function testThing(): void
    {
        $this->addToAssertionCount(1);
    }
}

// A method covers tag overrides the class's covers-nothing tag, so the
// targets of that test are checked, with the class tags.
// @mago-expect analysis:phpunit/invalid-covers
/**
 * @coversNothing
 * @uses \Corpus\MissingFromClass
 */
final class CoversOverridesNothingTest extends TestCase
{
    // @mago-expect analysis:phpunit/invalid-covers(2)
    /**
     * @covers \Corpus\Missing
     * @uses \Corpus\MissingToo
     */
    public function testCovers(): void
    {
        $this->addToAssertionCount(1);
    }

    /**
     * @uses \Corpus\MissingWithoutCovers
     */
    public function testWithoutCovers(): void
    {
        $this->addToAssertionCount(1);
    }
}
