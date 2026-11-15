<?php

/**
 * @file
 * The `CoversMethod` attribute, added in PHPUnit 11. The cases:
 *
 * - Methods that exist and methods that don't.
 * - A method of an `@method` tag or a method added by PHP to an enum.
 * - PHPUnit 12 and later only find a method under the class or trait it's declared in.
 * - PHPUnit 11 finds inherited and trait methods with reflection. This case also has `CoversTrait`.
 */

// Corpus: PHPUnit 11 and later.

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Thing::class, 'onlyOnThing')]
// @mago-expect analysis:phpunit/invalid-covers
#[CoversMethod(Thing::class, 'missing')]
// @mago-expect analysis:phpunit/invalid-covers
#[CoversMethod('Corpus\MissingClass', 'missing')]
final class CoversMethodTest extends TestCase
{
    public function testNothing(): void
    {
        $this->addToAssertionCount(1);
    }
}

/**
 * @method string magicLabel()
 * @method static string magicMake()
 */
class MagicTarget
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

    /**
     * Answers the static methods of the `@method` tags.
     */
    public static function __callStatic(string $name, array $arguments): string
    {
        return $name;
    }
}

enum Suit: string
{
    case Hearts = 'h';

    /**
     * Gives the enum a real method to cover.
     */
    public function label(): string
    {
        return $this->value;
    }
}

enum Shape
{
    case Round;

    /**
     * Gives the pure enum a `from()` method. PHP adds one only to a backed enum.
     */
    public static function from(int $sides): self
    {
        return self::Round;
    }
}

#[CoversMethod(MagicTarget::class, 'real')]
#[CoversMethod(Suit::class, 'label')]
// A pure enum can declare its own `from()`.
#[CoversMethod(Shape::class, 'from')]
// PHPUnit only covers methods written in the source.
// @mago-expect analysis:phpunit/invalid-covers
#[CoversMethod(MagicTarget::class, 'magicLabel')]
// @mago-expect analysis:phpunit/invalid-covers
#[CoversMethod(MagicTarget::class, 'magicMake')]
// @mago-expect analysis:phpunit/invalid-covers
#[CoversMethod(Suit::class, 'cases')]
// @mago-expect analysis:phpunit/invalid-covers
#[CoversMethod(Suit::class, 'tryFrom')]
final class CoversGeneratedMethodsTest extends TestCase
{
    public function testNothing(): void
    {
        $this->addToAssertionCount(1);
    }
}

// Corpus: PHPUnit 12 and later.
#[CoversMethod(Thing::class, 'onlyOnThing')]
#[CoversMethod(Greets::class, 'greet')]
#[CoversTrait(Greets::class)]
// `onlyOnThing()` is declared in `Thing`.
// @mago-expect analysis:phpunit/invalid-covers
#[CoversMethod(SpecialThing::class, 'onlyOnThing')]
// `greet()` is declared in the trait `Greets`.
// @mago-expect analysis:phpunit/invalid-covers
#[CoversMethod(Greeter::class, 'greet')]
// @mago-expect analysis:phpunit/invalid-covers
#[CoversMethod(ThingLabelInterface::class, 'label')]
final class CoversDeclaredMethodTest extends TestCase
{
    public function testNothing(): void
    {
        $this->addToAssertionCount(1);
    }
}

// Corpus: PHPUnit 11 only.
#[CoversMethod(SpecialThing::class, 'onlyOnThing')]
#[CoversMethod(Greeter::class, 'greet')]
#[CoversTrait(Greets::class)]
#[CoversMethod(ThingLabelInterface::class, 'label')]
// `current()` is inherited from `EmptyIterator`, which is built into PHP.
// @mago-expect analysis:phpunit/invalid-covers
#[CoversMethod(NoItems::class, 'current')]
final class CoversInheritedMethodTest extends TestCase
{
    public function testNothing(): void
    {
        $this->addToAssertionCount(1);
    }
}
