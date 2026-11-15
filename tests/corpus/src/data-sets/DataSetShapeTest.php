<?php

/**
 * @file
 * Data sets that aren't arrays, and string keys given by two data providers of one test. Some of
 * the providers yield inside blocks in the alternative syntax.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataSetShapeTest extends TestCase
{
    public static function yieldsInteger(): iterable
    {
        // @mago-expect analysis:phpunit/invalid-data-provider
        yield 1;
        yield [2];
    }

    #[DataProvider('yieldsInteger')]
    public function testYieldsInteger(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    public static function returnsFlatList(): array
    {
        // @mago-expect analysis:phpunit/invalid-data-provider
        return [1, 2, 3];
    }

    #[DataProvider('returnsFlatList')]
    public function testReturnsFlatList(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * A data set that may be an array isn't reported.
     *
     * @return iterable<int|array{int}>
     */
    public static function maybeArray(): iterable
    {
        yield self::row();
    }

    /**
     * A data set or a number.
     *
     * @return int|array{int}
     */
    private static function row(): int|array
    {
        return [1];
    }

    #[DataProvider('maybeArray')]
    public function testMaybeArray(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    public static function first(): iterable
    {
        yield 'same' => [1];
        yield 'first' => [2];
    }

    public static function second(): iterable
    {
        // @mago-expect analysis:phpunit/invalid-data-provider
        yield 'same' => [3];
    }

    /**
     * The key `same` is in both providers, so PHPUnit stops the test.
     */
    #[DataProvider('first')]
    #[DataProvider('second')]
    public function testSameKey(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    public static function returnsKeys(): array
    {
        return ['first' => [1], 'second' => [2]];
    }

    public static function returnsKeysAgain(): array
    {
        // @mago-expect analysis:phpunit/invalid-data-provider
        return ['second' => [3]];
    }

    #[DataProvider('returnsKeys')]
    #[DataProvider('returnsKeysAgain')]
    public function testSameReturnedKey(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    public static function branches(): iterable
    {
        if (\PHP_INT_SIZE === 4) {
            yield 'same' => [1];
        }

        yield 'other' => [2];
    }

    /**
     * A key yielded on a branch may not be given, so it isn't compared.
     */
    #[DataProvider('branches')]
    #[DataProvider('second')]
    public function testKeyOnBranch(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    public static function returnsNumbered(): array
    {
        return ['1' => [1]];
    }

    /**
     * PHP turns the key `'1'` of an array into an integer, and PHPUnit appends
     * a data set with an integer key.
     */
    #[DataProvider('returnsNumbered')]
    #[DataProvider('returnsNumbered')]
    public function testNumberedKeys(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    public static function yieldsNumbered(): iterable
    {
        // @mago-expect analysis:phpunit/invalid-data-provider
        yield '1' => [1];
        yield [2];
    }

    /**
     * A generator keeps the key `'1'` as a string, so PHPUnit sees it twice.
     */
    #[DataProvider('yieldsNumbered')]
    #[DataProvider('yieldsNumbered')]
    public function testYieldedNumberedKeys(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}

final class AlternativeSyntaxProviderTest extends TestCase
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function onWindows(): iterable
    {
        if (\PHP_OS_FAMILY === 'Windows'):
            yield 'x' => [1];
            yield 'win' => [2];
        endif;

        yield 'y' => [3];
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function eachNumber(): iterable
    {
        foreach (self::numbers() as $number):
            yield 'start' => [$number];
            yield 'win' => [$number];
        endforeach;
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function other(): iterable
    {
        yield 'win' => [3];
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function another(): iterable
    {
        yield 'win' => [4];
    }

    /**
     * One number when the `NUMBERS` environment variable is set, or none.
     *
     * @return list<int>
     */
    private static function numbers(): array
    {
        return \getenv('NUMBERS') === false ? [] : [1];
    }

    /**
     * The key in the `if` block is only given on Windows.
     */
    #[DataProvider('onWindows')]
    #[DataProvider('other')]
    public function testIfBlock(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * The key in the `foreach` block is only given when there are numbers.
     */
    #[DataProvider('eachNumber')]
    #[DataProvider('another')]
    public function testForeachBlock(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}
