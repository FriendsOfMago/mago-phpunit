<?php

/**
 * @file
 * Data sets of tests with dependencies. The cases:
 *
 * - A dependency named by a constant, which is resolved.
 * - From PHPUnit 11 on, a test that returns `void` or `never` doesn't pass a result to the tests
 *   that depend on it. PHPUnit 10 passes null.
 * - PHPUnit 11 stops passing the results of a test's dependencies at the first one that returns
 *   `void` or `never`. PHPUnit 12 skips each such dependency instead, so on 12 these tests get one
 *   result each.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\TestCase;

final class DependencyTargetsTest extends TestCase
{
    public function testValue(): string
    {
        $this->addToAssertionCount(1);

        return 'value';
    }

    public function testVoid(): void
    {
        $this->addToAssertionCount(1);
    }

    /**
     * PHPUnit 11 stops at the first void target. PHPUnit 12 skips it.
     * PHPUnit 10 passes null for it.
     */
    #[Depends('testVoid')]
    #[Depends('testValue')]
    #[DataProvider('single')]
    public function testVoidAndValue(int $number, ?string $value = ''): void
    {
        $this->assertGreaterThan(0, $number + strlen($value ?? ''));
    }

    /**
     * @return iterable<array{int}>
     */
    public static function single(): iterable
    {
        yield [1];
    }
}

// Corpus: PHPUnit 11 and later.
final class VoidDependencyTest extends TestCase
{
    public function testVoid(): void
    {
        $this->addToAssertionCount(1);
    }

    #[Depends('testVoid')]
    #[DataProvider('named')]
    public function testNamed(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    #[Depends('testVoid')]
    #[DataProvider('single')]
    public function testMissing(int $number, mixed $result): void
    {
        $this->assertNull($result);
        $this->assertGreaterThan(0, $number);
    }

    public function testValue(): int
    {
        $this->addToAssertionCount(1);

        return 1;
    }

    /**
     * PHPUnit 11 and 12 both pass the result of the first dependency.
     */
    #[Depends('testValue')]
    #[Depends('testVoid')]
    #[DataProvider('namedBeforeResult')]
    public function testNamedBeforeResult(int $number, int $result): void
    {
        $this->assertSame($number, $result);
    }

    /**
     * @return iterable<array{number: int}>
     */
    public static function named(): iterable
    {
        yield ['number' => 1];
    }

    /**
     * @return iterable<array{number: int}>
     */
    public static function namedBeforeResult(): iterable
    {
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield ['number' => 1];
    }

    /**
     * @return iterable<array{int}>
     */
    public static function single(): iterable
    {
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield [1];
    }
}

// Corpus: PHPUnit 11 only.
final class VoidDependencyFirstTest extends TestCase
{
    public function testVoid(): void
    {
        $this->addToAssertionCount(1);
    }

    public function testValue(): int
    {
        $this->addToAssertionCount(1);

        return 1;
    }

    #[Depends('testVoid')]
    #[Depends('testValue')]
    #[DataProvider('voidFirst')]
    public function testVoidFirst(int $number, int $result): void
    {
        $this->assertSame($number, $result);
    }

    /**
     * The dependencies are read in source order, across attribute kinds.
     */
    #[DependsExternal(VoidDependencyFirstTest::class, 'testVoid')]
    #[Depends('testValue')]
    #[DataProvider('externalVoidFirst')]
    public function testExternalVoidFirst(int $number, int $result): void
    {
        $this->assertSame($number, $result);
    }

    #[Depends('testValue')]
    #[Depends('testVoid')]
    #[DataProvider('valueFirst')]
    public function testValueFirst(int $number, int $result): void
    {
        $this->assertSame($number, $result);
    }

    /**
     * @return iterable<array{int}>
     */
    public static function voidFirst(): iterable
    {
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield [1];
    }

    /**
     * @return iterable<array{int}>
     */
    public static function externalVoidFirst(): iterable
    {
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield [1];
    }

    /**
     * @return iterable<array{int}>
     */
    public static function valueFirst(): iterable
    {
        yield [1];
    }
}

// Corpus: PHPUnit 11 and later.
final class ConstantDependencyTest extends TestCase
{
    public const TARGET = 'testValue';

    public function testValue(): string
    {
        $this->addToAssertionCount(1);

        return 'value';
    }

    /**
     * A target named by a constant is resolved. Its result comes after a
     * data set with string keys, which fails.
     */
    #[Depends(self::TARGET)]
    #[DataProvider('named')]
    public function testConstantTarget(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * @return iterable<array{number: int}>
     */
    public static function named(): iterable
    {
        // @mago-expect analysis:phpunit/data-provider-mismatch
        yield ['number' => 1];
    }
}
