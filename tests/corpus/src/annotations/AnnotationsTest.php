<?php

/**
 * @file
 * Annotations, which are parsed by PHPUnit before version 12. One case has a `/*` in a string above
 * a docblock. On PHPUnit 10, one case has attributes added in PHPUnit 11. PHPUnit 10 skips them and
 * reads the annotations.
 */

// Corpus: PHPUnit 11 and earlier.

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnorePhpunitDeprecations;
use PHPUnit\Framework\TestCase;

// @mago-expect analysis:phpunit/invalid-covers(5)
/**
 * @coversDefaultClass \Corpus\Thing
 * @covers \Corpus\Thing
 * @covers \Corpus\Thing::onlyOnThing()
 * @covers \Corpus\Thing the thing
 * @covers \Corpus\coveredFunction
 * @covers \Corpus\Missing
 * @covers \Corpus\Thing::missing
 * @covers \Corpus\Clock
 * @covers Thing
 * @coversDefaultClass \Corpus\Other
 */
final class CoversAnnotationsTest extends TestCase
{
    // @mago-expect analysis:phpunit/invalid-covers(2)
    /**
     * Globs like src/*.php in the text don't end the docblock.
     *
     * @covers ::onlyOnThing
     * @covers ::missing
     * @coversDefaultClass \Corpus\Thing
     */
    public function testCovers(): void
    {
        $this->addToAssertionCount(1);
    }

    // @mago-expect analysis:phpunit/invalid-covers
    /**
     * @covers ::
     */
    public function testEmpty(): void
    {
        $this->addToAssertionCount(1);
    }
}

final class DataProviderAnnotationsTest extends TestCase
{
    /**
     * @return iterable<array{int}>
     */
    public static function valid(): iterable
    {
        yield [1];
    }

    /**
     * @dataProvider valid
     * @dataProvider \Corpus\Providers::numbers
     */
    public function testAnnotated(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    // @mago-expect analysis:phpunit/invalid-data-provider
    /**
     * @dataProvider missingAnnotated
     */
    public function testAnnotatedMissing(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * PHPUnit drops the parentheses after the name.
     *
     * @dataProvider valid()
     */
    public function testParentheses(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * @dataProvider ::valid
     * @dataProvider Corpus\Providers::
     */
    public function testEmptyParts(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}

// @mago-expect analysis:phpunit/annotation-without-value(3)
/**
 * @group
 * @covers\Corpus\Thing
 * @group fast
 * @coversNothing
 * @testdox
 */
final class AnnotationsTest extends TestCase
{
    // @mago-expect analysis:phpunit/annotation-without-value
    // @mago-expect analysis:phpunit/invalid-data-provider
    /**
     * @dataProvider
     */
    public function testNothing(): void
    {
        $this->addToAssertionCount(1);
    }

    // @mago-expect analysis:phpunit/annotation-without-value
    /**
     * PHPUnit treats a tag name with more letters joined to it as another
     * tag, so the group below isn't set.
     *
     * @group-x fast
     */
    public function testJoinedName(): void
    {
        $this->addToAssertionCount(1);
    }

    /**
     * Mail me@test.com about this helper, which isn't a test.
     *
     * @dataProvider missing
     */
    public function helperWithAnAddress(): void {}
}

/**
 * PHPUnit adds the default class in front of `::name`. Then it accepts a
 * method of that class or a user-defined function `name`. PHPUnit 10 tries
 * the function first and PHPUnit 11 the method. The default class also
 * applies to the tags on the class.
 *
 * @coversDefaultClass \Corpus\Thing
 * @covers ::onlyOnThing
 * @covers ::corpus_global_helper
 */
final class CoversDefaultsTest extends TestCase
{
    public function testNothing(): void
    {
        $this->addToAssertionCount(1);
    }
}

// The class body doesn't have any of the checked tags.
// @mago-expect analysis:phpunit/invalid-covers
/**
 * @covers \Corpus\Nowhere
 */
final class OnlyClassDocblockTest extends TestCase
{
    public function testNothing(): void
    {
        $this->addToAssertionCount(1);
    }
}

final class InnerDocblockStartTest extends TestCase
{
    // @mago-expect analysis:phpunit/invalid-data-provider
    /**
     * @dataProvider nowhere
     * See src/** for details.
     */
    public function testNothing(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}

/**
 * PHPUnit ignores the annotations of a class or method that has a PHPUnit
 * attribute.
 *
 * @covers \Corpus\Missing
 */
#[Group('corpus')]
final class AnnotationsBesideAttributesTest extends TestCase
{
    /**
     * @dataProvider missing
     * @covers \Corpus\Missing
     * @testdox
     */
    #[Group('corpus')]
    public function testIgnored(): void
    {
        $this->addToAssertionCount(1);
    }
}

final class CommentStartInStringTest extends TestCase
{
    public function testMime(): void
    {
        self::assertSame('image/*', 'image/' . '*');
    }

    // @mago-expect analysis:phpunit/invalid-data-provider
    /**
     * @dataProvider missingProvider
     */
    public function testAfterString(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}

// Corpus: PHPUnit 10 and earlier.
/**
 * @coversDefaultClass \Corpus\Thing
 */
// @mago-expect analysis:non-existent-attribute-class
#[CoversMethod(Thing::class, 'onlyOnThing')]
final class NewerAttributeTest extends TestCase
{
    /**
     * @covers ::onlyOnThing
     */
    public function testCovers(): void
    {
        $this->addToAssertionCount(1);
    }

    // @mago-expect analysis:phpunit/invalid-data-provider
    /**
     * @dataProvider missingProvider
     */
    // @mago-expect analysis:non-existent-attribute-class
    #[IgnorePhpunitDeprecations]
    public function testProvider(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}
