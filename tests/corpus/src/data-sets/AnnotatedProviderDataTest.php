<?php

/**
 * @file
 * Data sets of providers named by `@dataProvider` tags, which are parsed by
 * PHPUnit 10 and 11.
 */

// Corpus: PHPUnit 11 and earlier.

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\TestCase;

final class AnnotatedProviderDataTest extends TestCase
{
    /**
     * @dataProvider numbers
     */
    public function testNumber(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * @dataProvider \Corpus\AnnotatedProviderDataTest::numbers()
     */
    public function testQualified(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    public function testFirst(): string
    {
        $this->addToAssertionCount(1);

        return 'first';
    }

    /**
     * The result of testFirst() is passed after the data.
     *
     * @depends testFirst
     * @dataProvider single
     */
    public function testDepends(int $number, string $first): void
    {
        $this->assertSame('first', $first);
        $this->assertGreaterThan(0, $number);
    }

    /**
     * @return iterable<array{int|string}>
     */
    public static function numbers(): iterable
    {
        yield [1];
        // @mago-expect analysis:phpunit/data-provider-mismatch(2)
        yield ['two'];
    }

    /**
     * @return iterable<array{int}>
     */
    public static function single(): iterable
    {
        yield [1];
    }

    /**
     * A value isn't passed for a dependency on a class.
     *
     * @depends Corpus\Thing::class
     * @dataProvider named
     */
    public function testClassDependency(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * @depends Corpus\Thing::class
     * @dataProvider singleForClass
     */
    public function testClassDependencyMissing(int $number, string $first): void
    {
        $this->assertSame('first', $first);
        $this->assertGreaterThan(0, $number);
    }

    // @mago-expect analysis:phpunit/annotation-without-value
    /**
     * A tag name with more letters joined to it is another tag.
     *
     * @depends-on testFirst
     * @dataProvider named
     */
    public function testOtherTag(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * PHPUnit passes the result of a test named twice only once.
     *
     * @depends testFirst
     * @depends Corpus\AnnotatedProviderDataTest::testFirst
     * @dataProvider singleForTwice
     */
    public function testSameTwice(int $number, string $first, string $again): void
    {
        $this->assertSame($first, $again);
        $this->assertGreaterThan(0, $number);
    }

    /**
     * @return iterable<array{number: int}>
     */
    public static function named(): iterable
    {
        yield ['number' => 1];
    }

    /**
     * @return iterable<array{int}>
     */
    public static function singleForClass(): iterable
    {
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield [1];
    }

    /**
     * @return iterable<array{int}>
     */
    public static function singleForTwice(): iterable
    {
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield [1];
    }
}
