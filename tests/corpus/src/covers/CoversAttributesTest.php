<?php

/**
 * @file
 * Covers attributes on test classes. The cases:
 *
 * - Covers attributes on an abstract test class. PHP doesn't inherit attributes, so PHPUnit doesn't
 *   read them.
 * - Covers attributes with a leading backslash. PHPUnit 12 and later look the name up as written.
 *   PHPUnit 10 and 11 find the name through reflection.
 * - Covers attributes for classes, methods and functions built into PHP.
 * - `CoversClass` naming an interface. PHPUnit 10 accepts a user-defined interface there, and
 *   PHPUnit 11 and later reject it.
 * - `CoversClass` naming a trait. PHPUnit 12 and later only look the name up among classes.
 * - `CoversTrait` as the only covers attribute of a class, naming a trait that doesn't exist.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

#[CoversClass('Corpus\Missing')]
#[CoversFunction('Corpus\missing_function')]
abstract class AbstractClassCoversAttributesCases extends TestCase
{
    public function testInherited(): void
    {
        $this->addToAssertionCount(1);
    }
}

#[CoversClass(Thing::class)]
final class AbstractClassCoversAttributesTest extends AbstractClassCoversAttributesCases {}

// Corpus: PHPUnit 12 and later.
// @mago-expect analysis:phpunit/invalid-covers(2)
#[CoversClass('\Corpus\Thing')]
#[CoversFunction('\Corpus\coveredFunction')]
#[CoversClass(Thing::class)]
final class CoversBackslashTest extends TestCase
{
    public function testNothing(): void
    {
        $this->addToAssertionCount(1);
    }
}

// Corpus: PHPUnit 11 and earlier.
#[CoversClass('\Corpus\Thing')]
#[CoversFunction('\Corpus\coveredFunction')]
final class CoversBackslashReflectedTest extends TestCase
{
    public function testNothing(): void
    {
        $this->addToAssertionCount(1);
    }
}

// Corpus: PHPUnit 11 and later.
// PHPUnit only maps user-defined code. It warns about each target here.
// @mago-expect analysis:phpunit/invalid-covers
#[CoversClass(\ArrayObject::class)]
// @mago-expect analysis:phpunit/invalid-covers
#[CoversFunction('strlen')]
// @mago-expect analysis:phpunit/invalid-covers
#[CoversMethod(\ArrayObject::class, 'count')]
#[CoversFunction('corpus_global_helper')]
final class CoversBuiltinAttributesTest extends TestCase
{
    public function testNothing(): void
    {
        $this->addToAssertionCount(1);
    }
}

// Corpus: PHPUnit 10 only.
#[CoversClass(ThingLabelInterface::class)]
final class CoversClassInterfaceTest extends TestCase
{
    public function testNothing(): void
    {
        $this->addToAssertionCount(1);
    }
}

// Corpus: PHPUnit 11 and later.
// @mago-expect analysis:phpunit/invalid-covers
#[CoversClass(Clock::class)]
final class CoversClassInterfaceRejectedTest extends TestCase
{
    public function testNothing(): void
    {
        $this->addToAssertionCount(1);
    }
}

// Corpus: PHPUnit 12 and later.
trait CoveredTrait
{
    /**
     * Gives the trait some code to cover.
     */
    public function covered(): void {}
}

// Corpus: PHPUnit 12 and later.
// @mago-expect analysis:phpunit/invalid-covers
#[CoversClass(CoveredTrait::class)]
#[CoversClass(Thing::class)]
final class CoversClassTraitTest extends TestCase
{
    public function testNothing(): void
    {
        $this->addToAssertionCount(1);
    }
}

// Corpus: PHPUnit 11 and later.
// @mago-expect analysis:phpunit/invalid-covers
#[CoversTrait('Corpus\MissingTrait')]
final class CoversTraitOnlyTest extends TestCase
{
    public function testNothing(): void
    {
        $this->addToAssertionCount(1);
    }
}
