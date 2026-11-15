<?php

/**
 * @file
 * Test classes that skip a parent's setUp(), have @covers for missing code,
 * or have attributes and annotations parsed by PHPUnit differently from
 * what was meant.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\Attributes\RequiresPhpunit;
use PHPUnit\Framework\TestCase;

abstract class PreparedTestCase extends TestCase
{
    protected Thing $thing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->thing = new Thing();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }
}

final class CallsParentTest extends PreparedTestCase
{
    protected function setUp(): void
    {
        // A comment with parent::setUp() in it doesn't count, but this call does.
        parent::setUp();
    }

    public function testThing(): void
    {
        $this->thing->onlyOnThing();
        $this->addToAssertionCount(1);
    }
}

final class CommentInParentCallTest extends PreparedTestCase
{
    protected function setUp(): void
    {
        parent::/* the base */ setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown /* the base */();
    }

    public function testThing(): void
    {
        $this->addToAssertionCount(1);
    }
}

final class LineCommentInParentCallTest extends PreparedTestCase
{
    protected function setUp(): void
    {
        parent::// the base
        setUp();
    }

    public function testThing(): void
    {
        $this->addToAssertionCount(1);
    }
}

final class CallsParentByNameTest extends PreparedTestCase
{
    protected function setUp(): void
    {
        PreparedTestCase::setUp();
    }

    public function testThing(): void
    {
        $this->addToAssertionCount(1);
    }
}

final class SkipsParentTest extends PreparedTestCase
{
    // @mago-expect analysis:phpunit/missing-parent-call
    protected function setUp(): void
    {
        // parent::setUp();
    }

    // @mago-expect analysis:phpunit/missing-parent-call
    protected function tearDown(): void {}

    public function testThing(): void
    {
        $this->addToAssertionCount(1);
    }
}

/**
 * TestCase's own setUp() is empty, so a direct subclass doesn't need to call it.
 */
final class DirectSubclassTest extends TestCase
{
    protected function setUp(): void {}

    public function testNothing(): void
    {
        $this->addToAssertionCount(1);
    }
}

#[CoversClass(Thing::class)]
// @mago-expect analysis:phpunit/invalid-covers
#[CoversClass('Corpus\MissingClass')]
#[CoversFunction('Corpus\coveredFunction')]
// @mago-expect analysis:phpunit/invalid-covers
#[CoversFunction('Corpus\missingFunction')]
// An empty name isn't looked up.
// @mago-expect analysis:invalid-argument
#[CoversFunction('')]
final class CoversTest extends TestCase
{
    public function testNothing(): void
    {
        $this->addToAssertionCount(1);
    }
}

#[RequiresPhp('>= 8.1.0')]
final class RequiresWithOperatorTest extends TestCase
{
    #[RequiresPhpunit('^10.5')]
    public function testNothing(): void
    {
        $this->addToAssertionCount(1);
    }
}

// @mago-expect analysis:phpunit/requires-without-operator
#[RequiresPhp('8.1')]
final class RequiresTest extends TestCase
{
    // @mago-expect analysis:phpunit/requires-without-operator
    #[RequiresPhpunit('10.5')]
    public function testNothing(): void
    {
        $this->addToAssertionCount(1);
    }
}

abstract class AbstractSetUpTestCase extends TestCase
{
    abstract protected function tearDown(): void;
}

final class ImplementsAbstractTest extends AbstractSetUpTestCase
{
    protected function tearDown(): void {}

    public function testNothing(): void
    {
        $this->addToAssertionCount(1);
    }
}

final class ParentCallableTest extends PreparedTestCase
{
    // @mago-expect analysis:phpunit/missing-parent-call
    protected function setUp(): void
    {
        $setUp = parent::setUp(...);
        unset($setUp);
    }

    public function testNothing(): void
    {
        $this->addToAssertionCount(1);
    }
}
