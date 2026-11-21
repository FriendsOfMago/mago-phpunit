<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\DataSets;

use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Metadata\MethodMetadataProjection;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Span;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Analyzer\TestClass\PHPUnitAttributes;
use MagoPHPUnit\Analyzer\TestClass\TestClass;
use MagoPHPUnit\Internal\PHPUnitFeatures;

use function array_intersect;
use function array_key_exists;
use function array_slice;
use function count;
use function explode;
use function ltrim;
use function str_contains;
use function stripos;
use function strtolower;

/**
 * Checks the data sets of each test's data providers in the same class.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class DataProviderDataCheck
{
    private function __construct() {}

    /**
     * Checks each test against the data sets of its providers in the same class.
     */
    public static function check(TestClass $test): void
    {
        if (stripos($test->text, needle: 'DataProvider') === false) {
            return;
        }

        [$uses, $providers, $tests] = self::uses($test);
        [$values, $uses] = self::typed($test, $providers, $uses);
        $sets = [];
        $stringKeys = [];
        $empty = [];
        foreach ($values as $key => $found) {
            $sets[$key] = ProviderReturns::build($found);
            $stringKeys[$key] = ProviderReturns::stringKeys($found);
            $provider = $providers[$key] ?? null;
            $first = $found[0] ?? null;
            if ($provider !== null && $first !== null && ProviderReturns::empty($found)) {
                $empty[$key] = [$provider, $first->span];
            }
        }

        // A provider returning only empty arrays is invalid when the test's other
        // providers also lack data. PHPUnit 10 and later merge the providers' data sets.
        // PHPUnit fails the test when the merged sets are empty, unless `skipWhenEmpty`
        // is set on a provider. This matches phpstan-phpunit's `EmptyDataProviderRule`,
        // plus merged providers and `skipWhenEmpty`.
        EmptyProviders::report($test, $tests, $empty);

        $emptyKeys = PHPUnitFeatures::rejectsEmptyDataSetKeys($test->codebase)
            ? self::reportEmptyKeys($test, $stringKeys)
            : [];
        self::checkUses($test, $uses, $sets, $stringKeys, $emptyKeys);
    }

    /**
     * The providers used by each test, the providers by lowercased name, and the tests for the empty check.
     *
     * @return array{
     *     list<array{MethodMetadataProjection, list<string>, list<bool|Span>}>,
     *     array<string, MethodMetadataProjection>,
     *     list<list<string>>,
     * }
     */
    private static function uses(TestClass $test): array
    {
        $providers = [];
        $uses = [];
        $tests = [];
        $abstract = $test->class->flags->contains(MetadataFlags::ABSTRACT);
        foreach ($test->methods() as $method) {
            if (!$test->isTest($method)) {
                continue;
            }

            $keys = [];
            $counts = [];
            $late = false;
            foreach (self::providers($test, $method) as [$name, $countsValues, $unqualified]) {
                $provider = $test->method($name);
                if ($provider === null) {
                    continue;
                }

                $key = strtolower($name);
                $providers[$key] ??= $provider;
                $keys[] = $key;
                $counts[] = $countsValues;
                $late = $late || $unqualified && $abstract;
            }

            $uses[] = [$method, $keys, $counts];
            // A provider in another class may have data. A provider not found here may
            // have data too. All providers must be in this class for the empty check.
            // PHPUnit doesn't run an abstract class. A provider named without a class is
            // looked up in the concrete subclass. It can be overridden there.
            if ($keys !== [] && !$late && EmptyProviders::knowsAll($test, $method, count($keys))) {
                $tests[] = $keys;
            }
        }

        return [$uses, $providers, $tests];
    }

    /**
     * The typed values of each provider, and the uses with each `validateArgumentCount` flag resolved.
     *
     * @param array<string, MethodMetadataProjection> $providers
     * @param list<array{MethodMetadataProjection, list<string>, list<bool|Span>}> $uses
     * @return array{
     *     array<string, list<ReturnedValue>>,
     *     list<array{MethodMetadataProjection, list<string>, list<bool>}>,
     * }
     */
    private static function typed(TestClass $test, array $providers, array $uses): array
    {
        // One query fetches the types of every provider's values.
        $values = [];
        $spans = [];
        foreach ($providers as $key => $provider) {
            $location = $provider->location;
            $values[$key] = $location === null ? [] : ProviderReturns::find($test->contents, $location->span);
            foreach ($values[$key] as $value) {
                $spans[] = $value->span;
            }
        }

        // The `validateArgumentCount` flags left to the analysis are put last
        // in the query.
        $flags = [];
        foreach ($uses as [, , $counts]) {
            foreach ($counts as $count) {
                if (!$count instanceof Span) {
                    continue;
                }

                $flags[] = $count;
            }
        }

        $wanted = [...$spans, ...$flags];
        $types = $wanted === [] ? [] : $test->context->analysis->getMultipleExpressionTypes($wanted);
        $next = 0;
        foreach ($values as $key => $found) {
            foreach ($found as $index => $value) {
                $values[$key][$index] = $value->withType($types[$next++] ?? null);
            }
        }

        return [$values, self::resolveFlags($uses, array_slice($types, count($spans)))];
    }

    /**
     * Checks the data sets of each test against its parameters.
     *
     * @param list<array{MethodMetadataProjection, list<string>, list<bool>}> $uses
     * @param array<string, list<DataSet>> $sets The data sets of each provider.
     * @param array<string, array<array-key, Span>> $stringKeys The string keys of each provider.
     * @param list<string> $emptyKeys The providers with a reported empty key.
     */
    private static function checkUses(
        TestClass $test,
        array $uses,
        array $sets,
        array $stringKeys,
        array $emptyKeys,
    ): void {
        $named = PHPUnitFeatures::namedDataSetKeys($test->codebase);
        $warns = PHPUnitFeatures::warnsAboutDataSetShape($test->codebase);
        // PHPUnit passes each data set to the test as arguments in order. PHPUnit 11 and later pass
        // string-keyed values by parameter name. Dependency results are passed after the data. A
        // value rejected by a native parameter type causes an error. A missing value causes an
        // error too. A value given twice also causes an error. A key that isn't a parameter name
        // causes an error. This matches phpstan-phpunit's `DataProviderDataRule`.
        foreach ($uses as [$method, $keys, $counts]) {
            // PHPUnit stops the test at an empty key, so its sets aren't checked.
            if (array_intersect($keys, $emptyKeys) !== [] || self::reportDuplicateKey($test, $keys, $stringKeys)) {
                continue;
            }

            foreach ($keys as $index => $key) {
                DataSetCheck::checkSets(
                    $test,
                    $method,
                    $sets[$key] ?? [],
                    $named,
                    countsValues: $warns && $counts[$index],
                );
            }
        }
    }

    /**
     * Reports providers with a data set key `''` that is always given.
     *
     * @param array<string, array<array-key, Span>> $stringKeys The string keys of each provider.
     * @return list<string> The lowercased names of the reported providers.
     */
    private static function reportEmptyKeys(TestClass $test, array $stringKeys): array
    {
        // PHPUnit 13 stops each test of such a provider.
        $found = [];
        foreach ($stringKeys as $key => $given) {
            $where = $given[''] ?? null;
            if ($where === null) {
                continue;
            }

            $found[] = $key;
            $test->reporter->error(DataProviderCheck::CODE, Reporter::issue(
                "The data set key '' is an empty string.",
                $where,
                'PHPUnit 13 stops the test when a data set has an empty string as its key. Give the data set a '
                . 'name, or leave the key out.',
            ));
        }

        return $found;
    }

    /**
     * Reports the first string key given twice by a test's providers.
     *
     * @param list<string> $keys The lowercased names of the test's providers in the class, in order.
     * @param array<string, array<array-key, Span>> $stringKeys The string keys of each provider.
     * @return bool Whether a duplicate was reported.
     */
    private static function reportDuplicateKey(TestClass $test, array $keys, array $stringKeys): bool
    {
        // PHPUnit merges the data sets of a test's providers. PHPUnit stops the test at a repeated
        // string key. Keys from one provider aren't compared with each other here. Its `yield`
        // statements can be on different branches.
        $seen = [];
        foreach ($keys as $key) {
            $given = $stringKeys[$key] ?? [];
            foreach ($given as $setKey => $where) {
                if (!array_key_exists($setKey, $seen)) {
                    continue;
                }

                $test->reporter->error(DataProviderCheck::CODE, Reporter::issue(
                    "The data set key '{$setKey}' is already used by another data provider of the test.",
                    $where,
                    'PHPUnit stops the test when two data sets have the same string key. Give each data set its '
                    . 'own key.',
                ));

                return true;
            }

            $seen += $given;
        }

        return false;
    }

    /**
     * The names and flags of the test method's providers in the test class.
     *
     * @return list<array{string, bool|Span, bool}> The name, count flag and whether the class name is omitted.
     */
    private static function providers(TestClass $test, MethodMetadataProjection $method): array
    {
        // Only same-class providers are included. The `DataProvider` attributes are checked. The
        // `@dataProvider` tags are checked when annotations are parsed by PHPUnit.
        $names = [];
        foreach (PHPUnitAttributes::of($method, name: 'DataProvider') as $attribute) {
            $name = PHPUnitAttributes::string($attribute, index: 0, name: 'methodName');
            $validate = $attribute->getArgument(1, 'validateArgumentCount');
            // PHPUnit counts the set's values unless `validateArgumentCount` is false.
            // A flag that isn't a literal is passed to the analysis as its expression span.
            $literal = $validate?->valueType?->getLiteralBool();
            $counts = $validate === null ? true : $literal ?? $validate->valueLocation->span ?? false;
            if ($name !== null) {
                $names[] = [$name, $counts, true];
            }
        }

        foreach ($test->methodTags($method, ['dataProvider']) as $tag) {
            $value = DataProviderCheck::tagValue($tag['value']);
            [$class, $name] = str_contains($value, '::') ? explode('::', $value, limit: 2) : ['', $value];
            if (
                $tag['gap'] !== ''
                && $name !== ''
                && ($class === '' || strtolower(ltrim($class, characters: '\\')) === strtolower($test->class->name))
            ) {
                $names[] = [$name, true, $class === ''];
            }
        }

        return $names;
    }

    /**
     * The uses with each `validateArgumentCount` span replaced by whether its value is `true`.
     *
     * @param list<array{MethodMetadataProjection, list<string>, list<bool|Span>}> $uses
     * @param list<?Type> $types The type of each span, in the order of the uses.
     * @return list<array{MethodMetadataProjection, list<string>, list<bool>}>
     */
    private static function resolveFlags(array $uses, array $types): array
    {
        // A flag with an unknown value is treated as off. The warning isn't reported in that case.
        $next = 0;
        $resolved = [];
        foreach ($uses as [$method, $keys, $counts]) {
            $flags = [];
            foreach ($counts as $count) {
                $flags[] = $count instanceof Span ? ($types[$next++] ?? null)?->getLiteralBool() === true : $count;
            }

            $resolved[] = [$method, $keys, $flags];
        }

        return $resolved;
    }
}
