<?php

/**
 * @file
 * `#[TestWith]` data sets that don't fit the test's parameters, and `#[TestWith]` on a test with a
 * data provider. PHPUnit 11 and earlier ignore it there without a warning.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\TestWithJson;
use PHPUnit\Framework\TestCase;

enum TestWithLevel: int
{
    case Low = 1;
}

final class TestWithDataTest extends TestCase
{
    private const NAME = 'one';

    #[TestWith([1, 'one'])]
    #[TestWith([2, 'two'])]
    public function testFits(int $number, string $name): void
    {
        $this->assertNotSame('', $name);
        $this->assertGreaterThan(0, $number);
    }

    /**
     * Constants and enum cases have their resolved types.
     */
    #[TestWith([TestWithLevel::Low->value, self::NAME, TestWithLevel::Low])]
    public function testConstants(int $number, string $name, TestWithLevel $level): void
    {
        $this->assertSame($level->value, $number);
        $this->assertNotSame('', $name);
    }

    /**
     * The second set is reported as #1.
     */
    // @mago-expect analysis:phpunit/data-provider-mismatch(1)
    #[TestWith([1, 'one'])]
    #[TestWith(['two', 'two'])]
    public function testWrongType(int $number, string $name): void
    {
        $this->assertNotSame('', $name);
        $this->assertGreaterThan(0, $number);
    }

    // @mago-expect analysis:phpunit/data-provider-mismatch(1)
    #[TestWith([1])]
    public function testMissing(int $number, string $name): void
    {
        $this->assertNotSame('', $name);
        $this->assertGreaterThan(0, $number);
    }

    /**
     * The `#[TestWithJson]` set fits, and takes the number #0.
     */
    // @mago-expect analysis:phpunit/data-provider-mismatch(1)
    #[TestWithJson('[1]')]
    #[TestWith(['one'])]
    public function testAfterJson(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * The JSON of a `#[TestWithJson]` set is decoded, as PHPUnit decodes it.
     */
    // @mago-expect analysis:phpunit/data-provider-mismatch(1)
    #[TestWithJson('[1, "one"]')]
    #[TestWithJson('["two", "two"]')]
    public function testJson(int $number, string $name): void
    {
        $this->assertNotSame('', $name);
        $this->assertGreaterThan(0, $number);
    }

    public function testValue(): int
    {
        $this->addToAssertionCount(1);

        return 1;
    }

    /**
     * The result of the test it depends on fills the second parameter.
     */
    #[Depends('testValue')]
    #[TestWith([1])]
    public function testWithDependency(int $number, int $result): void
    {
        $this->assertSame($number, $result);
    }
}

// Corpus: PHPUnit 11 and earlier.
final class TestWithNextToProviderTest extends TestCase
{
    /**
     * @return iterable<array{int}>
     */
    public static function numbers(): iterable
    {
        yield [1];
    }

    /**
     * PHPUnit ignores `#[TestWith]` on a test with a data provider.
     */
    #[DataProvider('numbers')]
    #[TestWith(['one'])]
    public function testWithProvider(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}
