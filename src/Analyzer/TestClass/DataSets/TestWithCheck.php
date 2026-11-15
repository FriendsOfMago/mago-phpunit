<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\DataSets;

use Mago\Sdk\Analyzer\Metadata\MethodMetadataProjection;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\SourceLocation;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Analyzer\TestClass\TestClass;
use MagoPHPUnit\Internal\PHPUnitFeatures;

use function count;
use function stripos;

/**
 * Reports `#[TestWith]` data sets that don't fit the test's parameters.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class TestWithCheck
{
    private function __construct() {}

    /**
     * Checks the `#[TestWith]`, `#[TestWithJson]` and `@testWith` data sets of each test in the class.
     *
     * The names and types left to resolve by TestWithSets are fetched in one
     * query for the class.
     */
    public static function check(TestClass $test): void
    {
        if (stripos($test->text, needle: 'TestWith') === false) {
            return;
        }

        $tests = [];
        foreach ($test->methods() as $method) {
            if (!$test->isTest($method)) {
                continue;
            }

            [$sets, $mixed] = TestWithSets::find($test, $method);
            $tests[] = [$method, $sets, $mixed];
        }

        $named = PHPUnitFeatures::namedDataSetKeys($test->codebase);
        $warns = PHPUnitFeatures::warnsAboutDataSetShape($test->codebase);
        foreach (self::resolve($test, $tests) as [$method, $sets, $mixed]) {
            if ($warns && $mixed !== null) {
                self::reportMixed($test, $mixed);
            }

            // PHPUnit stops the test, or the whole run, when it can't use a set.
            if (self::reportProblems($test, $sets)) {
                continue;
            }

            $names = self::names($sets);
            if ($names === null || $named && self::reportDuplicate($test, $sets, $names)) {
                continue;
            }

            $typed = self::typed($sets, $names, $test->context->phpVersion);
            if ($typed !== []) {
                DataSetCheck::checkSets($test, $method, $typed, $named, countsValues: $warns);
            }
        }
    }

    /**
     * The tests with the name and set types of their sets, fetched in one query.
     *
     * @param list<array{MethodMetadataProjection, list<TestWithSet>, ?SourceLocation}> $tests
     * @return list<array{MethodMetadataProjection, list<TestWithSet>, ?SourceLocation}>
     */
    private static function resolve(TestClass $test, array $tests): array
    {
        $wanted = [];
        foreach ($tests as [, $sets]) {
            foreach ($sets as $set) {
                if ($set->nameSpan !== null) {
                    $wanted[] = $set->nameSpan;
                }

                if ($set->type === null && $set->where !== null) {
                    $wanted[] = $set->where;
                }
            }
        }

        // The types come back in the order of the spans above.
        $found = $wanted === [] ? [] : $test->context->analysis->getMultipleExpressionTypes($wanted);
        $next = 0;
        $resolved = [];
        foreach ($tests as [$method, $sets, $mixed]) {
            $typed = [];
            foreach ($sets as $set) {
                $nameType = $set->nameSpan === null ? null : $found[$next++] ?? null;
                $type = $set->type ?? ($set->where === null ? null : $found[$next++] ?? null);
                $typed[] = $set->withTypes($nameType, $type);
            }

            $resolved[] = [$method, $typed, $mixed];
        }

        return $resolved;
    }

    /**
     * Reports a `#[TestWith*]` attribute on a test that also has a data provider attribute.
     */
    private static function reportMixed(TestClass $test, SourceLocation $where): void
    {
        $test->reporter->warning(DataProviderCheck::CODE, Reporter::issue(
            'PHPUnit ignores the #[TestWith*] data sets of a test with a data provider.',
            $where,
            'PHPUnit warns about mixing #[DataProvider*] and #[TestWith*] attributes, and only uses the data '
            . 'provider. Move the data sets into the data provider.',
        ));
    }

    /**
     * Reports each JSON set that can't be used by PHPUnit.
     *
     * @param list<TestWithSet> $sets
     * @return bool Whether a problem was reported.
     */
    private static function reportProblems(TestClass $test, array $sets): bool
    {
        $found = false;
        foreach ($sets as $set) {
            $where = $set->where;
            if ($set->problem === null || $where === null) {
                continue;
            }

            $found = true;
            [$message, $help] = $set->problem;
            $test->reporter->error(DataProviderCheck::CODE, Reporter::issue($message, $where, $help));
        }

        return $found;
    }

    /**
     * Reports the first set with the key of an earlier set.
     *
     * @param list<TestWithSet> $sets
     * @param list<?string> $names
     * @return bool Whether a duplicate key was found.
     */
    private static function reportDuplicate(TestClass $test, array $sets, array $names): bool
    {
        $index = TestWithSets::duplicate($names, $test->context->phpVersion);
        if ($index === null) {
            return false;
        }

        $where = ($sets[$index] ?? null)?->where;
        if ($where !== null) {
            $label = TestWithSets::labels($names, $test->context->phpVersion)[$index] ?? '';
            $test->reporter->error(DataProviderCheck::CODE, Reporter::issue(
                "Data set {$label} has the key of an earlier data set of the test.",
                $where,
                help: 'PHPUnit stops the test when two data sets have the same key. Give each data set its own name.',
            ));
        }

        return true;
    }

    /**
     * The set names with null for unnamed sets, or null if a name cannot be resolved.
     *
     * @param list<TestWithSet> $sets
     * @return list<?string>|null
     */
    private static function names(array $sets): ?array
    {
        $names = [];
        foreach ($sets as $set) {
            if ($set->nameSpan === null) {
                $names[] = $set->name;
                continue;
            }

            // PHPUnit treats a set with a null name as unnamed.
            $type = $set->nameType;
            $name = $type?->getLiteralString();
            if ($name === null && !self::isNull($type)) {
                return null;
            }

            $names[] = $name;
        }

        return $names;
    }

    /**
     * Whether the type is `null`.
     */
    private static function isNull(?Type $type): bool
    {
        $atomic = $type !== null && count($type->atomicTypes) === 1 ? $type->atomicTypes[0] : null;

        return $atomic instanceof SimpleAtomicType && $atomic->kind === SimpleAtomicTypeKind::Null;
    }

    /**
     * The label, type and span of each set with a known type and report span.
     *
     * @param list<TestWithSet> $sets
     * @param list<?string> $names
     * @return list<DataSet>
     */
    private static function typed(array $sets, array $names, PHPVersion $php): array
    {
        $typed = [];
        foreach (TestWithSets::labels($names, $php) as $index => $label) {
            $set = $sets[$index] ?? null;
            $type = $set?->type;
            $where = $set?->where;
            if ($type !== null && $where !== null) {
                $typed[] = new DataSet($label, $type, $where);
            }
        }

        return $typed;
    }
}
