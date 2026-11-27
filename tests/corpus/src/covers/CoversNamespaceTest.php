<?php

/**
 * @file
 * The namespace, subclass and implementation attributes of PHPUnit 12 and later. The cases:
 *
 * - A namespace with code, also written in another case, one with only a function, and one
 *   without any code.
 * - A class for `CoversClassesThatExtendClass` and an interface for
 *   `CoversClassesThatImplementInterface`, and the wrong kind for each.
 * - A class without a subclass, and interfaces without a class that names
 *   them in its `implements` list, also through a child interface or an enum.
 * - Code built into PHP, names with a leading backslash, and a lone backslash.
 */

// Corpus: PHPUnit 12 and later.

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\CoversClassesThatExtendClass;
use PHPUnit\Framework\Attributes\CoversClassesThatImplementInterface;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\UsesClassesThatExtendClass;
use PHPUnit\Framework\Attributes\UsesClassesThatImplementInterface;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversNamespace('Corpus')]
#[CoversNamespace('Corpus\Counting')]
#[CoversNamespace('corpus\counting')]
// @mago-expect analysis:phpunit/invalid-covers
#[CoversNamespace('Corpus\Count')]
// @mago-expect analysis:phpunit/invalid-covers
#[CoversNamespace('\Corpus')]
// @mago-expect analysis:phpunit/invalid-covers
#[UsesNamespace('Corpus\Missing')]
// Only a function is declared in `Corpus\Helpers`.
#[UsesNamespace('Corpus\Helpers')]
final class CoversNamespaceTest extends TestCase
{
    public function testNothing(): void
    {
        $this->addToAssertionCount(1);
    }
}

interface CoveredShape {}

interface CoveredRoundShape extends CoveredShape {}

interface CoveredSized {}

class CoveredCircle implements CoveredRoundShape, CoveredSized {}

class CoveredLeaf {}

interface CoveredAnonymousOnly {}

class CoveredAnonymousBase {}

function coveredAnonymous(): object
{
    return new class extends CoveredAnonymousBase implements CoveredAnonymousOnly {};
}

enum CoveredSquare implements CoveredShape
{
    case Unit;
}

#[CoversClassesThatExtendClass(Thing::class)]
// @mago-expect analysis:phpunit/invalid-covers
#[CoversClassesThatExtendClass(CoveredLeaf::class)]
// @mago-expect analysis:phpunit/invalid-covers
#[CoversClassesThatExtendClass(CoveredAnonymousBase::class)]
// @mago-expect analysis:phpunit/invalid-covers
// @mago-expect analysis:possibly-invalid-argument
#[CoversClassesThatExtendClass('\\')]
// @mago-expect analysis:phpunit/invalid-covers
#[CoversClassesThatExtendClass(Clock::class)]
// @mago-expect analysis:phpunit/invalid-covers
#[CoversClassesThatExtendClass(Greets::class)]
// @mago-expect analysis:phpunit/invalid-covers
#[CoversClassesThatExtendClass(\ArrayObject::class)]
// @mago-expect analysis:phpunit/invalid-covers
#[UsesClassesThatExtendClass('\Corpus\Thing')]
#[UsesClassesThatExtendClass(Thing::class)]
final class CoversSubclassesTest extends TestCase
{
    public function testNothing(): void
    {
        $this->addToAssertionCount(1);
    }
}

#[CoversClassesThatImplementInterface(CoveredRoundShape::class)]
// @mago-expect analysis:phpunit/invalid-covers
#[CoversClassesThatImplementInterface(CoveredShape::class)]
// @mago-expect analysis:phpunit/invalid-covers
#[CoversClassesThatImplementInterface(Clock::class)]
// @mago-expect analysis:phpunit/invalid-covers
#[CoversClassesThatImplementInterface(CoveredAnonymousOnly::class)]
// @mago-expect analysis:phpunit/invalid-covers
#[CoversClassesThatImplementInterface(Thing::class)]
// @mago-expect analysis:phpunit/invalid-covers
#[CoversClassesThatImplementInterface(\Countable::class)]
#[UsesClassesThatImplementInterface(CoveredSized::class)]
final class CoversImplementationsTest extends TestCase
{
    public function testNothing(): void
    {
        $this->addToAssertionCount(1);
    }
}
