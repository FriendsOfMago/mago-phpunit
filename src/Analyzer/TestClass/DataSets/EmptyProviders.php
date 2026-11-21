<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\DataSets;

use Mago\Sdk\Analyzer\Metadata\MethodMetadataProjection;
use Mago\Sdk\Span;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Analyzer\TestClass\PHPUnitAttributes;
use MagoPHPUnit\Analyzer\TestClass\TestClass;

use function array_diff;
use function array_keys;
use function count;
use function in_array;

/**
 * Reports data providers without data sets, for the tests whose providers are all empty.
 *
 * @internal
 */
final class EmptyProviders
{
    private function __construct() {}

    /**
     * Whether the given number of providers in this class are all of the test's providers, and it doesn't skip when empty.
     *
     * A provider in another class, or one that isn't found, may have data.
     */
    public static function knowsAll(TestClass $test, MethodMetadataProjection $method, int $found): bool
    {
        return $found === self::providerCount($test, $method) && !self::skipsWhenEmpty($method);
    }

    /**
     * Reports each empty provider of a test whose providers are all empty.
     *
     * PHPUnit 10 and later merge the data sets of a test's providers, so one
     * provider with data is enough for the test to run.
     *
     * @param list<list<string>> $tests The provider keys of each test, from knowsAll().
     * @param array<string, array{MethodMetadataProjection, Span}> $empty Each empty provider, with its first `return`.
     */
    public static function report(TestClass $test, array $tests, array $empty): void
    {
        $doomed = [];
        foreach ($tests as $keys) {
            if (array_diff($keys, array_keys($empty)) !== []) {
                continue;
            }

            $doomed = [...$doomed, ...$keys];
        }

        foreach ($empty as $key => [$provider, $span]) {
            if (!in_array($key, $doomed, strict: true)) {
                continue;
            }

            self::reportEmpty($test, $provider, $span);
        }
    }

    /**
     * How many providers the test has, in this class, another class or a closure.
     */
    private static function providerCount(TestClass $test, MethodMetadataProjection $method): int
    {
        // A closure provider, added in PHPUnit 13.4, may have data too.
        $count =
            count(PHPUnitAttributes::of($method, name: 'DataProvider'))
            + count(PHPUnitAttributes::of($method, name: 'DataProviderExternal'))
            + count(PHPUnitAttributes::of($method, name: 'DataProviderClosure'));
        foreach ($test->methodTags($method, ['dataProvider']) as $tag) {
            if ($tag['gap'] === '' || DataProviderCheck::tagValue($tag['value']) === '') {
                continue;
            }

            ++$count;
        }

        return $count;
    }

    /**
     * Whether a provider attribute of the test sets `skipWhenEmpty`, or may set it.
     *
     * PHPUnit 13 skips the test instead of failing it then.
     */
    private static function skipsWhenEmpty(MethodMetadataProjection $method): bool
    {
        foreach (['DataProvider' => 2, 'DataProviderExternal' => 3] as $name => $position) {
            foreach (PHPUnitAttributes::of($method, $name) as $attribute) {
                $argument = $attribute->getArgument($position, 'skipWhenEmpty');
                if ($argument === null || $argument->valueType?->getLiteralBool() === false) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Reports an `invalid-data-provider` error on the first `return` of a provider without data sets.
     */
    private static function reportEmpty(TestClass $test, MethodMetadataProjection $provider, Span $where): void
    {
        $name = $provider->originalName ?? $provider->name ?? '';
        $test->reporter->error(DataProviderCheck::CODE, Reporter::issue(
            "The data provider {$name}() doesn't return any data sets.",
            where: $where,
            help: 'PHPUnit 10 and later fail each test that uses a data provider without data sets. Return at least one data set.',
        ));
    }
}
