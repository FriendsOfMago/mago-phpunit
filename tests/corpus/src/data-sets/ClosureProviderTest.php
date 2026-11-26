<?php

/**
 * @file
 * Data sets of `#[DataProviderClosure]` closures. They're matched with the
 * test's parameters like the data sets of a provider method.
 */

// Corpus: PHPUnit 13 and later.

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderClosure;
use PHPUnit\Framework\TestCase;

final class ClosureProviderTest extends TestCase
{
    #[DataProviderClosure(static function (): array {
        // @mago-expect analysis:phpunit/data-provider-mismatch
        return [
            [1, 'one'],
            [2],
        ];
    })]
    public function testMissingValue(int $number, string $label): void
    {
        $this->assertNotSame('', $label . $number);
    }

    #[DataProviderClosure(static function (): iterable {
        yield [1, 'one'];
        // @mago-expect analysis:phpunit/data-provider-mismatch(2)
        yield ['two', 2];
    })]
    public function testWrongTypes(int $number, string $label): void
    {
        $this->assertNotSame('', $label . $number);
    }

    #[DataProviderClosure(static function (): iterable {
        yield 'first' => ['label' => 'one', 'number' => 1];
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield 'second' => ['number' => 2, 'extra' => 3];
    })]
    public function testNamed(int $number, string $label = ''): void
    {
        $this->assertNotSame('', $label . $number);
    }

    /**
     * A closure next to a provider method is checked with it.
     */
    #[DataProvider('numbers')]
    #[DataProviderClosure(static function (): iterable {
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield ['three'];
    })]
    public function testWithMethod(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * PHPUnit warns about a data set with more values than parameters, unless
     * the count isn't validated.
     */
    #[DataProviderClosure(static function (): iterable {
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield [1, 2];
    })]
    #[DataProviderClosure(static function (): iterable {
        yield [3, 4];
    }, false)]
    public function testExtraValues(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    #[DataProviderClosure(static function (): iterable {
        yield [1, 'one'];
        yield 'two' => [2, 'two'];
    })]
    public function testFits(int $number, string $label): void
    {
        $this->assertNotSame('', $label . $number);
    }

    #[DataProviderClosure(static function (): array {
        // @mago-expect analysis:phpunit/invalid-data-provider
        return [];
    })]
    public function testEmpty(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * An empty provider method next to a closure with data isn't reported.
     */
    #[DataProvider('none')]
    #[DataProviderClosure(static function (): iterable {
        yield [1];
    })]
    public function testEmptyMethodNextToClosure(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    // @mago-expect analysis:phpunit/invalid-data-provider
    #[DataProviderClosure(static function (int $first): array {
        return [[$first]];
    })]
    public function testRequiredParameter(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    #[DataProviderClosure(static function (int $first = 1): array {
        return [[$first]];
    })]
    public function testOptionalParameter(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    // @mago-expect analysis:phpunit/invalid-data-provider
    #[DataProviderClosure(static function (): int {
        return 1;
    })]
    public function testNotIterable(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * A first-class callable of the class is read like a provider method.
     */
    #[DataProviderClosure(self::words(...))]
    public function testCallable(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * Values in a nested closure, a `return` after `yield`, and the rows of
     * `yield from` are handled like in a provider method.
     */
    #[DataProviderClosure(static function (): iterable {
        $make = static function (): array {
            return ['nested'];
        };
        yield [1];
        yield from [[2], [3]];

        // @mago-expect analysis:hidden-generator-return
        return [$make()];
    })]
    public function testBody(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * The same key in a closure and a provider method is reported once.
     */
    #[DataProvider('keyed')]
    #[DataProviderClosure(static function (): iterable {
        // @mago-expect analysis:phpunit/invalid-data-provider
        yield 'same' => [2];
    })]
    public function testSameKey(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    #[DataProviderClosure(static function (): iterable {
        // @mago-expect analysis:phpunit/invalid-data-provider
        yield '' => [1];
    })]
    public function testEmptyKey(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * A variadic test takes any number of values.
     */
    #[DataProviderClosure(static function (): iterable {
        yield [1, 2, 3];
    }, self::VALIDATE)]
    public function testVariadic(int ...$numbers): void
    {
        $this->assertCount(3, $numbers);
    }

    #[DataProviderClosure(static function (int ...$extra): array {
        return [[count($extra) + 1]];
    })]
    public function testVariadicParameter(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    // @mago-expect analysis:phpunit/invalid-data-provider
    #[DataProviderClosure(static function (): void {})]
    public function testVoid(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    #[DataProvider('none')]
    #[DataProviderClosure(static function (): array {
        // @mago-expect analysis:phpunit/invalid-data-provider
        return [];
    })]
    public function testBothEmpty(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    // @mago-expect analysis:phpunit/invalid-data-provider
    #[DataProviderClosure(self::instanceRows(...))]
    public function testInstanceMethod(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    // @mago-expect analysis:phpunit/invalid-data-provider
    #[DataProviderClosure(self::withParameter(...))]
    public function testMethodWithParameter(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * A callable of another class isn't followed.
     */
    #[DataProviderClosure(OtherClosureRows::rows(...))]
    public function testOtherClass(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * @return array<array{int}>
     */
    public function instanceRows(): array
    {
        return [[1]];
    }

    /**
     * @return array<array{int}>
     */
    public static function withParameter(int $number): array
    {
        return [[$number]];
    }

    public const VALIDATE = true;

    /**
     * @return iterable<array{string}>
     */
    public static function words(): iterable
    {
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield ['word'];
    }

    /**
     * @return iterable<array{int}>
     */
    public static function keyed(): iterable
    {
        yield 'same' => [1];
    }

    /**
     * @return array<array{int}>
     */
    public static function none(): array
    {
        // @mago-expect analysis:phpunit/invalid-data-provider
        return [];
    }

    /**
     * @return iterable<array{int}>
     */
    public static function numbers(): iterable
    {
        yield [1];
    }
}

final class OtherClosureRows
{
    /**
     * @return array<array{string}>
     */
    public static function rows(): array
    {
        return [['word']];
    }
}
