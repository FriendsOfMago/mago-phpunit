<?php

/**
 * @file
 * Data sets that don't fit the parameters of the test.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

final class DataProviderDataTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function returned(): array
    {
        return [
            'fits' => [1, 'one'],
        ];
    }

    #[DataProvider('returned')]
    public function testReturned(int $number, string $name): void
    {
        $this->assertNotSame('', $name . $number);
    }

    public static function wrongTypes(): array
    {
        // @mago-expect analysis:phpunit/data-provider-mismatch(2)
        return [
            [1, 'one'],
            ['two', 2],
        ];
    }

    #[DataProvider('wrongTypes')]
    public function testWrongTypes(int $number, string $name): void
    {
        $this->assertNotSame('', $name . $number);
    }

    public static function yielded(): iterable
    {
        yield [1, 'one'];
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield 'missing a value' => [1];
    }

    #[DataProvider('yielded')]
    public function testYielded(int $number, string $name): void
    {
        $this->assertNotSame('', $name . $number);
    }

    public static function withDefault(): iterable
    {
        yield [1];
        yield [1, 2, 3];
    }

    #[DataProvider('withDefault')]
    public function testWithDefault(int $number, int $other = 0, int ...$rest): void
    {
        $this->assertGreaterThan(0, $number + $other + count($rest));
    }

    public static function computed(): iterable
    {
        foreach ([1, 2] as $number) {
            yield [$number, (string) $number];
        }

        $rows = array_map(static fn(int $n): array => [$n, $n], [1, 2]);
        yield from $rows;
    }

    #[DataProvider('computed')]
    public function testComputed(int $number, string $name): void
    {
        $this->assertNotSame('', $name . $number);
    }

    public static function renumbered(): iterable
    {
        // PHPUnit renumbers integer keys, so the value goes to $number.
        yield [7 => 1];
    }

    #[DataProvider('renumbered')]
    public function testRenumbered(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    public static function variadic(): iterable
    {
        yield [1, 2, 3];
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield [1, 'two'];
    }

    #[DataProvider('variadic')]
    public function testVariadic(int $first, int ...$rest): void
    {
        $this->assertGreaterThan(0, $first + count($rest));
    }

    public static function generatorReturn(): iterable
    {
        yield [1];

        // A generator's return value isn't a data set.
        // @mago-expect analysis:hidden-generator-return
        return [['wrong']];
    }

    #[DataProvider('generatorReturn')]
    public function testGeneratorReturn(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    public static function withClosure(): iterable
    {
        $double = static function (int $number): array {
            return [$number * 2];
        };
        $triple = static fn(int $number): int => $number * 3;
        yield [$triple(1)];
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield ['wrong'];
        yield $double(1);
    }

    #[DataProvider('withClosure')]
    public function testWithClosure(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    public static function docblockTyped(): iterable
    {
        // A value that only breaks the docblock type is a warning.
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield [0];
    }

    /**
     * @param positive-int $number
     */
    #[DataProvider('docblockTyped')]
    public function testDocblockTyped(int $number): void
    {
        $this->assertGreaterThanOrEqual(0, $number);
    }

    public static function forDependent(): iterable
    {
        yield [1];
    }

    public function testProducer(): string
    {
        $this->addToAssertionCount(1);

        return 'produced';
    }

    /**
     * The result of testProducer() is passed after the data.
     */
    #[Depends('testProducer')]
    #[DataProvider('forDependent')]
    public function testDependent(int $number, string $produced): void
    {
        $this->assertNotSame('', $produced . $number);
    }

    public static function forTooFew(): iterable
    {
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield [1];
    }

    /**
     * One dependency fills one parameter, so $missing doesn't get a value.
     */
    #[Depends('testProducer')]
    #[DataProvider('forTooFew')]
    public function testTooFew(int $number, string $produced, int $missing): void
    {
        $this->assertNotSame('', $produced . $number . $missing);
    }

    public static function arrowGenerator(): array
    {
        $factory = static fn(): \Generator => yield ['wrong'];
        unset($factory);

        return [[1]];
    }

    #[DataProvider('arrowGenerator')]
    public function testArrowGenerator(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * @return list<int>
     */
    private static function rest(): array
    {
        return [2];
    }

    public static function spread(): array
    {
        return [[1, ...self::rest()]];
    }

    #[DataProvider('spread')]
    public function testSpread(int $first, int $second): void
    {
        $this->assertGreaterThan(0, $first + $second);
    }

    public static function unorderedKeys(): array
    {
        // PHPUnit passes 'x' first. The key order isn't kept in Mago's type.
        return [[5 => 'x', 2 => 3]];
    }

    #[DataProvider('unorderedKeys')]
    public function testUnorderedKeys(string $name, int $number): void
    {
        $this->assertNotSame('', $name . $number);
    }

    public static function mayFit(): iterable
    {
        // A value typed mixed, or one that may be a float, isn't reported.
        yield [json_decode('[]', associative: true), self::floatOrObject()];
        yield [[self::class, 'mayFit'], 2.5];
    }

    public static function floatOrObject(): float|\stdClass
    {
        return 1.5;
    }

    #[DataProvider('mayFit')]
    public function testMayFit(array|callable $value, float $number): void
    {
        $this->assertNotNull($value);
        $this->assertGreaterThan(0, $number);
    }

    public static function forHelper(): iterable
    {
        yield [1];
    }

    /**
     * PHPUnit doesn't run a method that isn't a test, so its provider isn't
     * checked.
     */
    #[DataProvider('forHelper')]
    public function doHelper(int $number, string $missing): void
    {
        $this->assertNotSame('', $missing . $number);
    }

    public static function forRepeated(): iterable
    {
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield [1];
    }

    /**
     * PHPUnit passes one value for a test named by two attributes.
     */
    #[Depends('testProducer')]
    #[Depends('testProducer')]
    #[DataProvider('forRepeated')]
    public function testRepeated(int $number, string $produced, string $missing): void
    {
        $this->assertNotSame('', $produced . $number . $missing);
    }

    public static function forTag(): iterable
    {
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield [1];
    }

    /**
     * PHPUnit ignores the `@depends` tag of a method with an attribute.
     *
     * @depends testProducer
     */
    #[DataProvider('forTag')]
    public function testTag(int $number, string $produced): void
    {
        $this->assertNotSame('', $produced . $number);
    }

    public static function fn(): array
    {
        // A provider named fn is checked too.
        // @mago-expect analysis:phpunit/data-provider-mismatch
        return [['one']];
    }

    #[DataProvider('fn')]
    public function testNamedFn(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}

/**
 * A provider inherited by a test in a shorter file, InheritedProviderTest.
 */
abstract class ProviderBase extends TestCase
{
    public static function inherited(): array
    {
        return [[1]];
    }
}
