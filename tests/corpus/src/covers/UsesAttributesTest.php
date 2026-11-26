<?php

/**
 * @file
 * Uses attributes on test classes. PHPUnit checks their targets like the
 * targets of the matching covers attributes, and warns about each target that
 * can't be used.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesFunction;
use PHPUnit\Framework\Attributes\UsesMethod;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

// @mago-expect analysis:phpunit/invalid-covers
#[UsesClass('Corpus\Missing')]
// @mago-expect analysis:phpunit/invalid-covers
#[UsesFunction('Corpus\missing_function')]
#[UsesClass(Thing::class)]
#[UsesFunction('corpus_global_helper')]
final class UsesAttributesTest extends TestCase
{
    public function testThing(): void
    {
        $this->addToAssertionCount(1);
    }
}

/**
 * PHP doesn't inherit attributes, so PHPUnit doesn't read them on an
 * abstract class.
 */
#[UsesClass('Corpus\Missing')]
abstract class AbstractUsesAttributesCases extends TestCase
{
    public function testInherited(): void
    {
        $this->addToAssertionCount(1);
    }
}

// Corpus: PHPUnit 10 only.
/**
 * PHPUnit 10 accepts a user-defined interface.
 */
#[UsesClass(Clock::class)]
final class UsesInterfaceOnTenTest extends TestCase
{
    public function testThing(): void
    {
        $this->addToAssertionCount(1);
    }
}

// Corpus: PHPUnit 11 and later.
// @mago-expect analysis:phpunit/invalid-covers
#[UsesClass(Clock::class)]
// @mago-expect analysis:phpunit/invalid-covers
#[UsesMethod(Thing::class, 'missing')]
// @mago-expect analysis:phpunit/invalid-covers
#[UsesTrait('Corpus\MissingTrait')]
#[UsesMethod(Thing::class, 'onlyOnThing')]
#[UsesTrait(Greets::class)]
final class UsesMethodAttributesTest extends TestCase
{
    public function testThing(): void
    {
        $this->addToAssertionCount(1);
    }
}

/**
 * PHPUnit doesn't collect coverage for a class with `CoversNothing`.
 */
#[CoversNothing]
#[UsesClass('Corpus\Missing')]
final class UsesWithCoversNothingAttributeTest extends TestCase
{
    public function testThing(): void
    {
        $this->addToAssertionCount(1);
    }
}

// Corpus: PHPUnit 12 and later.
// @mago-expect analysis:phpunit/invalid-covers
#[UsesClass(Greets::class)]
final class UsesClassTraitTest extends TestCase
{
    public function testThing(): void
    {
        $this->addToAssertionCount(1);
    }
}

/**
 * The class attributes aren't read when every test has `CoversNothing`.
 */
#[UsesClass('Corpus\Missing')]
final class UsesWithTestsCoveringNothingTest extends TestCase
{
    #[CoversNothing]
    public function testThing(): void
    {
        $this->addToAssertionCount(1);
    }
}

// Corpus: PHPUnit 11 and earlier.
/**
 * A method covers tag overrides `#[CoversNothing]` on PHPUnit 10 and 11.
 */
#[CoversNothing]
// @mago-expect analysis:phpunit/invalid-covers
#[UsesClass('Corpus\Missing')]
final class UsesWithCoversOverrideTest extends TestCase
{
    /**
     * @covers \Corpus\Thing
     */
    public function testThing(): void
    {
        $this->addToAssertionCount(1);
    }
}
