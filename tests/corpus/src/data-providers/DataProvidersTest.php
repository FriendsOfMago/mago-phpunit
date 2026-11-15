<?php

/**
 * @file
 * Data providers that can't be called by PHPUnit, and a test method that isn't public. PHPUnit
 * doesn't run that method as a test.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

final class DataProvidersTest extends TestCase
{
    /**
     * @return iterable<array{int}>
     */
    public static function valid(): iterable
    {
        yield [1];
    }

    /**
     * @return iterable<array{int}>
     */
    public function notStatic(): iterable
    {
        yield [1];
    }

    /**
     * @return iterable<array{int}>
     */
    // @mago-expect analysis:unused-method
    protected static function notPublic(): iterable
    {
        yield [1];
    }

    /**
     * @return iterable<array{int}>
     */
    public static function withArgument(int $count): iterable
    {
        yield [$count];
    }

    #[DataProvider('valid')]
    #[DataProviderExternal(Providers::class, 'numbers')]
    // PHPUnit finds the class through reflection. PHP's reflection ignores a leading backslash.
    #[DataProviderExternal('\Corpus\Providers', 'numbers')]
    public function testValid(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    // @mago-expect analysis:phpunit/invalid-data-provider
    #[DataProvider('missing')]
    // @mago-expect analysis:phpunit/invalid-data-provider
    #[DataProvider('notStatic')]
    // @mago-expect analysis:phpunit/invalid-data-provider
    #[DataProvider('notPublic')]
    // @mago-expect analysis:phpunit/invalid-data-provider
    #[DataProvider('withArgument')]
    // @mago-expect analysis:phpunit/invalid-data-provider
    #[DataProviderExternal(Providers::class, 'missing')]
    public function testInvalid(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * @return iterable<array{int}>
     */
    public static function TestCases(): iterable
    {
        yield [1];
    }

    /**
     * PHPUnit doesn't treat a name that starts with `Test` in another case as
     * a test.
     */
    #[DataProvider('TestCases')]
    public function testCapital(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }

    /**
     * Named arguments, in the order of the parameters or not. PHPUnit's
     * attributes are marked as not taking named arguments, so Mago reports
     * them.
     */
    // @mago-expect analysis:named-argument-not-allowed(2)
    #[DataProviderExternal(methodName: 'numbers', className: Providers::class)]
    // @mago-expect analysis:named-argument-not-allowed(2)
    // @mago-expect analysis:phpunit/invalid-data-provider
    #[DataProviderExternal(methodName: 'missing', className: Providers::class)]
    // An empty name isn't looked up.
    // @mago-expect analysis:invalid-argument
    #[DataProvider('')]
    public function testNamedArguments(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}

final class PrivateTestMethodTest extends TestCase
{
    public function testRunsHelper(): void
    {
        $this->testHelper(1);
    }

    #[DataProvider('missing')]
    private function testHelper(int $number): void
    {
        $this->assertGreaterThan(0, $number);
    }
}
