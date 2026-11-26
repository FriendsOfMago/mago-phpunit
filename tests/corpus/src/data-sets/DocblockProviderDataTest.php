<?php

/**
 * @file
 * Data sets checked against the docblock types of the test's parameters.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class DocblockProviderDataTest extends TestCase
{
    public static function untyped(): iterable
    {
        yield ['between', '26'];
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield ['between', 26];
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield ['between', null];
        yield ['between', mt_rand(0, 1) === 1 ? '1' : 1];
    }

    /**
     * @param string $operator
     * @param string $min
     */
    #[DataProvider('untyped')]
    public function testUntyped($operator, $min): void
    {
        $this->assertSame('between', $operator);
        $this->assertNotSame('', $min);
    }

    public static function nativeFirst(): iterable
    {
        // A value rejected by the native type is only reported once, as an error.
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield ['one'];
        yield [1];
    }

    /**
     * @param positive-int $number
     */
    #[DataProvider('nativeFirst')]
    public function testNativeFirst(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    public static function lists(): iterable
    {
        yield [['a', 'b']];
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield [[1, 2]];
    }

    /**
     * @param list<string> $names
     */
    #[DataProvider('lists')]
    public function testLists(array $names): void
    {
        $this->assertNotEmpty($names);
    }

    public static function fitting(): iterable
    {
        yield [null, 'Foo', [self::class, 'fitting']];
        yield ['UTC', self::class, 'strlen'];
    }

    /**
     * @param string|null $timezone
     * @param class-string|string $class
     * @param callable $callback
     */
    #[DataProvider('fitting')]
    public function testFitting($timezone, $class, $callback): void
    {
        $this->assertTrue($timezone === null || $timezone !== '');
        $this->assertNotSame('', $class);
        $this->assertNotNull($callback);
    }

    public static function templated(): iterable
    {
        yield [1];
        yield ['one'];
    }

    /**
     * A docblock type that depends on a template isn't checked.
     *
     * @template T of int
     *
     * @param T $value
     */
    #[DataProvider('templated')]
    public function testTemplated($value): void
    {
        $this->assertNotNull($value);
    }

    public static function generic(): iterable
    {
        yield [new \ArrayObject([1, 2]), [new \ArrayObject([1])]];
    }

    /**
     * Mago infers `ArrayObject<int, int>` from the constructor and compares
     * the type arguments as invariant, so a generic object isn't checked.
     *
     * @param \ArrayObject<int, int|string> $object
     * @param list<\ArrayObject<int, int|string>> $objects
     */
    #[DataProvider('generic')]
    public function testGeneric(\ArrayObject $object, array $objects): void
    {
        $this->assertCount(2, $object);
        $this->assertCount(1, $objects);
    }

    public static function genericMismatch(): iterable
    {
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield [new \ArrayObject([1]), 'a'];
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield ['a', static fn() => 1];
    }

    /**
     * A generic object or a closure is still checked against a docblock
     * type without a generic object or a callable signature.
     *
     * @param string $name
     * @param string $label
     */
    #[DataProvider('genericMismatch')]
    public function testGenericMismatch($name, $label): void
    {
        $this->assertNotSame('', $name);
        $this->assertNotSame('', $label);
    }

    public static function callables(): iterable
    {
        yield [
            static fn($value) => $value,
            static function ($value) {
                return $value;
            },
        ];
    }

    /**
     * A closure without declared types isn't checked against a signature.
     *
     * @param callable(string): string $first
     * @param \Closure(string): string $second
     */
    #[DataProvider('callables')]
    public function testCallables(callable $first, \Closure $second): void
    {
        $this->assertSame('a', $first('a'));
        $this->assertSame('a', $second('a'));
    }

    public static function strings(): iterable
    {
        yield [self::class, 'abc'];
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield [\stdClass::class, 'abc'];
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield [self::class, 'ABC'];
    }

    /**
     * @param class-string<TestCase> $class
     * @param lowercase-string $name
     */
    #[DataProvider('strings')]
    public function testStrings(string $class, string $name): void
    {
        $this->assertNotSame('', $class);
        $this->assertNotSame('', $name);
    }

    /**
     * @param non-empty-string $name
     */
    #[TestWith(['name'])]
    // @mago-expect analysis:phpunit/data-provider-mismatch
    #[TestWith([''])]
    public function testWithSets(string $name): void
    {
        $this->assertNotSame('', $name);
    }
}
