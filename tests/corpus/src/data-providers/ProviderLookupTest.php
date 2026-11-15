<?php

/**
 * @file
 * Data provider names that aren't method names, providers of abstract test classes, providers that stop early, and providers that are tests.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderLookupTest extends TestCase
{
    /**
     * @return iterable<array{int}>
     */
    public static function provideRows(): iterable
    {
        yield [1];
    }

    /**
     * A name with a `*` isn't a method name, so the provider doesn't exist.
     */
    // @mago-expect analysis:phpunit/invalid-data-provider
    #[DataProvider('pro*Rows')]
    // @mago-expect analysis:phpunit/invalid-data-provider
    #[DataProvider('provide*')]
    public function testWildcardName(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function legacy(): iterable
    {
        if (\PHP_VERSION_ID >= 80_300) {
            return;
        }

        yield 'case' => [1];
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function modern(): iterable
    {
        if (\PHP_VERSION_ID < 80_300) {
            return;
        }

        yield 'case' => [2];
    }

    /**
     * Only one of the providers yields, so the key isn't given twice.
     */
    #[DataProvider('legacy')]
    #[DataProvider('modern')]
    public function testExclusiveProviders(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * @return iterable<array{int}>
     */
    public static function testRows(): iterable
    {
        yield [1];
    }

    /**
     * PHPUnit warns about a provider that is a test too, and runs it as a test.
     */
    // @mago-expect analysis:phpunit/invalid-data-provider
    #[DataProvider('testRows')]
    public function testProviderIsTest(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}

/**
 * PHPUnit doesn't run an abstract class. It looks up a provider named without
 * a class in the subclass that runs the test.
 */
abstract class ProviderLookupCases extends TestCase
{
    /**
     * A placeholder overridden by each subclass.
     *
     * @return array<array{int}>
     */
    public static function placeholder(): array
    {
        return [];
    }

    /**
     * @return iterable<array{int}>
     */
    public function notStatic(): iterable
    {
        yield [1];
    }

    /**
     * The provider can be redeclared as public in a subclass.
     *
     * @return array<array{int}>
     */
    protected static function widened(): array
    {
        return [[1]];
    }

    #[DataProvider('widened')]
    public function testWidened(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    #[DataProvider('cases')]
    public function testCase(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    #[DataProvider('placeholder')]
    public function testPlaceholder(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * The provider can't be made static in a subclass.
     */
    // @mago-expect analysis:phpunit/invalid-data-provider
    #[DataProvider('notStatic')]
    public function testNotStatic(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}

final class ProviderLookupCasesTest extends ProviderLookupCases
{
    /**
     * @return array<array{int}>
     */
    public static function cases(): array
    {
        return [[1], [2]];
    }

    /**
     * @return array<array{int}>
     */
    #[\Override]
    public static function placeholder(): array
    {
        return [[3]];
    }

    /**
     * @return array<array{int}>
     */
    #[\Override]
    public static function widened(): array
    {
        return [[4]];
    }
}
