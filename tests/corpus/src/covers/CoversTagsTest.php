<?php

/**
 * @file
 * `@covers` tags, which are parsed by PHPUnit before version 12. The cases:
 *
 * - Class tags on an abstract test class. PHPUnit 10 and 11 only read class tags on the concrete
 *   class.
 * - `@covers ::name` in an abstract test class without `@coversDefaultClass`.
 * - `@covers` tags for classes and methods built into PHP.
 * - `@covers ::name` for a function built into PHP.
 * - `@covers` naming a method of an `@method` tag or a method added by PHP to an enum.
 * - `@covers ::name` in a class that uses a trait. PHPUnit 10 and 11 also read the class tags from
 *   the trait's docblock.
 */

// Corpus: PHPUnit 11 and earlier.

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\TestCase;

/**
 * PHPUnit doesn't read these tags, so they aren't checked, and the method
 * tags aren't redundant.
 *
 * @coversDefaultClass \Corpus\Other
 * @covers \Corpus\Missing
 * @covers \Corpus\Thing
 */
abstract class AbstractClassCoversCases extends TestCase
{
    // The default class comes from the subclass, so `::onlyOnThing` isn't
    // resolved against `Other`.
    /**
     * @covers ::onlyOnThing
     * @covers \Corpus\Thing
     */
    public function testInherited(): void
    {
        $this->addToAssertionCount(1);
    }

    // PHPUnit reads method tags on the inherited method.
    // @mago-expect analysis:phpunit/invalid-covers
    /**
     * @covers \Corpus\Thing::missing
     */
    public function testMissingMethod(): void
    {
        $this->addToAssertionCount(1);
    }
}

/**
 * @coversDefaultClass \Corpus\Thing
 */
final class AbstractClassCoversTest extends AbstractClassCoversCases {}

/**
 * PHPUnit runs the tests of this class in each concrete subclass, and reads
 * the default class from the subclass's docblock.
 */
abstract class AbstractCoversDefaultCases extends TestCase
{
    /**
     * @covers ::onlyOnThing
     */
    public function testInherited(): void
    {
        $this->addToAssertionCount(1);
    }
}

/**
 * @coversDefaultClass \Corpus\Thing
 */
final class AbstractCoversDefaultTest extends AbstractCoversDefaultCases {}

final class AbstractCoversDefaultMissingTest extends TestCase
{
    // @mago-expect analysis:phpunit/invalid-covers
    /**
     * @covers ::onlyOnThing
     */
    public function testWithoutDefault(): void
    {
        $this->addToAssertionCount(1);
    }
}

// PHPUnit only maps user-defined code. It warns about the built-in targets.
// @mago-expect analysis:phpunit/invalid-covers(2)
/**
 * @coversDefaultClass \DateTime
 * @covers ::format
 * @covers ::corpus_global_helper
 * @covers \ArrayObject
 */
final class CoversBuiltinAnnotationsTest extends TestCase
{
    // `NoItems` is user-defined, but its `current()` is inherited from
    // `EmptyIterator`, which is built into PHP.
    // @mago-expect analysis:phpunit/invalid-covers
    /**
     * @covers \Corpus\NoItems::current
     */
    public function testInheritedBuiltinMethod(): void
    {
        $this->addToAssertionCount(1);
    }

    /**
     * @covers \Corpus\SpecialThing::onlyOnThing
     * @covers \Corpus\Greeter::greet
     */
    public function testInheritedUserMethods(): void
    {
        $this->addToAssertionCount(1);
    }

    // PHPUnit warns about `@covers` for a whole interface, but it accepts a
    // method of the interface.
    /**
     * @covers \Corpus\ThingLabelInterface::label
     */
    public function testInterfaceMethod(): void
    {
        $this->addToAssertionCount(1);
    }
}

/**
 * @coversDefaultClass \Corpus\Thing
 */
final class CoversBuiltinFunctionTest extends TestCase
{
    // PHPUnit doesn't count `log()`, which is built into PHP, and `Thing`
    // doesn't have a `log()` method.
    // @mago-expect analysis:phpunit/invalid-covers
    /**
     * @covers ::log
     */
    public function testLog(): void
    {
        $this->addToAssertionCount(1);
    }

    // PHPUnit also accepts `Class::name` when `name` is a function.
    /**
     * @covers \Corpus\Other::corpus_global_helper
     */
    public function testFunctionAfterClass(): void
    {
        $this->addToAssertionCount(1);
    }
}

final class CoversBuiltinFunctionWithoutDefaultTest extends TestCase
{
    // @mago-expect analysis:phpunit/invalid-covers
    /**
     * @covers ::count
     */
    public function testCount(): void
    {
        $this->addToAssertionCount(1);
    }
}

/**
 * @method string tagLabel()
 */
class TaggedTarget
{
    /**
     * Gives the class a real method to cover.
     */
    public function real(): void {}

    /**
     * Answers the methods of the `@method` tags.
     */
    public function __call(string $name, array $arguments): string
    {
        return $name;
    }
}

enum Coin: string
{
    case Heads = 'h';
}

final class CoversGeneratedMethodAnnotationsTest extends TestCase
{
    /**
     * @covers \Corpus\TaggedTarget::real
     */
    public function testReal(): void
    {
        $this->addToAssertionCount(1);
    }

    // PHPUnit only covers user-defined methods.
    // @mago-expect analysis:phpunit/invalid-covers
    /**
     * @covers \Corpus\TaggedTarget::tagLabel
     */
    public function testPseudoMethod(): void
    {
        $this->addToAssertionCount(1);
    }

    // @mago-expect analysis:phpunit/invalid-covers
    /**
     * @covers \Corpus\Coin::from
     */
    public function testEnumMethod(): void
    {
        $this->addToAssertionCount(1);
    }
}

/**
 * @coversDefaultClass \Corpus\Thing
 */
trait CoversThing {}

final class CoversTraitDefaultClassTest extends TestCase
{
    use CoversThing;

    /**
     * @covers ::onlyOnThing
     */
    public function testFromTrait(): void
    {
        $this->addToAssertionCount(1);
    }
}
