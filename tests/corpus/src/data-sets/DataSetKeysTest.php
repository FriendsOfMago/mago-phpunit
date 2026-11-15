<?php

/**
 * @file
 * Data sets with string keys. The cases:
 *
 * - PHPUnit 11 and later pass the values as named arguments.
 * - PHPUnit 10 passes them in order. Mago doesn't keep the order of string keys, so they aren't
 *   checked.
 * - PHPUnit 13 rejects an empty string as a key.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

// Corpus: PHPUnit 11 and later.
final class DataProviderNamedKeysTest extends TestCase
{
    public static function named(): iterable
    {
        yield 'named' => ['name' => 'three', 'number' => 3];
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield ['number' => 4, 'name' => 'four', 'extra' => true];
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield [5, 'number' => 5, 'name' => 'five'];
    }

    #[DataProvider('named')]
    public function testNamed(int $number, string $name): void
    {
        $this->assertNotSame('', $name . $number);
    }

    public static function intoVariadic(): iterable
    {
        yield [1, 'other' => 2];
    }

    #[DataProvider('intoVariadic')]
    public function testIntoVariadic(int $first, int ...$rest): void
    {
        $this->assertGreaterThan(0, $first + count($rest));
    }

    public static function namedWithDependency(): iterable
    {
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield ['number' => 1];
    }

    public function testProducer(): string
    {
        $this->addToAssertionCount(1);

        return 'produced';
    }

    #[Depends('testProducer')]
    #[DataProvider('namedWithDependency')]
    public function testNamedWithDependency(int $number, string $produced): void
    {
        $this->assertNotSame('', $produced . $number);
    }
}

// Corpus: PHPUnit 10 and earlier.
final class DataProviderKeysInOrderTest extends TestCase
{
    public static function keyed(): iterable
    {
        yield 'first' => ['input' => 1, 'expected' => 'x'];
        yield 'second' => ['in' => 1, 'out' => 'y'];
    }

    #[DataProvider('keyed')]
    public function testKeyed(int $in, string $out): void
    {
        $this->assertNotSame('', $out . $in);
    }
}

// Corpus: PHPUnit 13 and later.
final class EmptyDataSetKeyTest extends TestCase
{
    /**
     * @return array<string, array{int}>
     */
    public static function returnsEmptyKey(): array
    {
        // @mago-expect analysis:phpunit/invalid-data-provider
        return ['' => [1], 'other' => [2]];
    }

    #[DataProvider('returnsEmptyKey')]
    public function testReturnsEmptyKey(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function yieldsEmptyKey(): iterable
    {
        yield 'first' => [1];
        // @mago-expect analysis:phpunit/invalid-data-provider
        yield '' => [2];
    }

    #[DataProvider('yieldsEmptyKey')]
    public function testYieldsEmptyKey(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * PHPUnit doesn't check the name of a #[TestWith] data set. Mago reports
     * the argument, since the parameter type is `non-empty-string`.
     */
    // @mago-expect analysis:invalid-argument
    #[TestWith([1], '')]
    public function testTestWithEmptyName(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}
