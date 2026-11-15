<?php

/**
 * @file
 * Data sets of testWith tags, read by PHPUnit 10 and 11. The cases are rows of a testWith tag,
 * testWith tags without rows, and bare testWith tags with rows on the next lines.
 */

// Corpus: PHPUnit 11 and earlier.

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class TestWithAnnotationTest extends TestCase
{
    // @mago-expect analysis:phpunit/data-provider-mismatch(1)
    /**
     * @testWith [1, "one"]
     *           ["two", "two"]
     */
    public function testRows(int $number, string $name): void
    {
        $this->assertNotSame('', $name);
        $this->assertGreaterThan(0, $number);
    }

    // @mago-expect analysis:phpunit/data-provider-mismatch(1)
    /**
     * PHPUnit reads the testWith tag from the doc comment even next to a PHPUnit attribute.
     *
     * @testWith ["one"]
     */
    #[Group('corpus')]
    public function testNextToAttribute(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * The rows end at an empty line.
     *
     * @testWith [1]
     *
     *           ["one"]
     */
    public function testEndOfRows(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    // @mago-expect analysis:phpunit/invalid-data-provider(1)
    /**
     * A row that isn't valid JSON makes PHPUnit error out on the test, so only that row is reported.
     *
     * @testWith ["one"]
     *           [1,
     */
    public function testInvalidRow(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * @return iterable<array{int}>
     */
    public static function numbers(): iterable
    {
        yield [1];
    }

    /**
     * A dataProvider tag takes the place of the testWith tag.
     *
     * @dataProvider numbers
     * @testWith ["one"]
     */
    public function testWithProvider(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}

final class TestWithTagRowsTest extends TestCase
{
    // @mago-expect analysis:phpunit/invalid-data-provider
    /**
     * @testWith nope
     */
    public function testNoRows(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    // @mago-expect analysis:phpunit/data-provider-mismatch
    /**
     * PHPUnit reads the rows after a bare tag.
     *
     * @testWith
     *     [1, 2]
     *     [3, "x"]
     */
    public function testBareTag(int $first, int $second): void
    {
        $this->assertGreaterThan(0, $first + $second);
    }

    // @mago-expect analysis:phpunit/invalid-data-provider
    /**
     * @testWith
     * The rows are missing.
     */
    public function testBareTagWithoutRows(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    // @mago-expect analysis:phpunit/annotation-without-value
    /**
     * PHPUnit doesn't find a bare tag on the last line, so the test runs without data.
     *
     * @testWith
     */
    public function testBareTagOnLastLine(): void
    {
        $this->addToAssertionCount(1);
    }
}
