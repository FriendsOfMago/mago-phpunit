<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\DataSets;

use Mago\Sdk\Analyzer\Metadata\MethodMetadataProjection;
use Mago\Sdk\Span;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Analyzer\TestClass\TestClass;

use function array_key_exists;
use function count;

/**
 * Reports the data sets of a test, from its data providers or its `#[TestWith*]` sets, that don't fit its parameters.
 *
 * @internal
 */
final class DataSetCheck
{
    public const CODE = 'data-provider-mismatch';

    private function __construct() {}

    /**
     * Reports the data sets that don't fit the test's parameters.
     *
     * @param list<DataSet> $sets
     * @param bool $countsValues Whether to report sets with more values than parameters.
     *
     * @mago-expect lint:no-boolean-flag-parameter
     */
    public static function checkSets(
        TestClass $test,
        MethodMetadataProjection $method,
        array $sets,
        bool $named,
        bool $countsValues,
    ): void {
        $name = $method->originalName ?? $method->name ?? '';
        // PHPUnit stops the test at a data set that isn't an array. The other sets aren't checked
        // in that case.
        $notArray = DataSetMatch::notArray($sets);
        if ($notArray !== null) {
            [$label, $where] = $notArray;
            $test->reporter->error(DataProviderCheck::CODE, Reporter::issue(
                "Data set {$label} isn't an array.",
                $where,
                "PHPUnit stops {$name}() when one of its data sets isn't an array. Put the values of the data set "
                . 'in an array.',
            ));

            return;
        }

        $passed = Dependencies::passed($test, $method);
        $dependencies = $passed === null ? null : count($passed);
        if ($passed !== null && $passed !== []) {
            foreach (DataSetMatch::valueCounts($sets, $named) as $count) {
                DependencyCheck::checkResults($test, $method, $passed, $count);
            }
        }

        $reported = [];
        foreach (DataSetMatch::problems($test->context->types, $method, $sets, $named, $dependencies) as [
            $label,
            $problem,
            $where,
        ]) {
            self::report($test, $method, "Data set {$label} {$problem}", $where);
            $reported[$label] = true;
        }

        // A set with a value that doesn't fit already has an error, so the
        // warning about its value count is left out.
        $parameters = count($method->parameters ?? []);
        foreach ($countsValues ? DataSetMatch::extraValues($method, $sets) : [] as [$label, $count, $where]) {
            if (array_key_exists($label, $reported)) {
                continue;
            }

            $test->reporter->warning(self::CODE, Reporter::issue(
                "Data set {$label} has {$count} values, but {$name}() only takes {$parameters}.",
                $where,
                help: 'PHPUnit warns about a data set with more values than the test has parameters.',
            ));
        }
    }

    /**
     * Reports a `data-provider-mismatch` error on the data set.
     */
    private static function report(
        TestClass $test,
        MethodMetadataProjection $method,
        string $message,
        Span $where,
    ): void {
        $name = $method->originalName ?? $method->name ?? '';
        $test->reporter->error(self::CODE, Reporter::issue(
            $message,
            $where,
            "PHPUnit passes each data set to {$name}() as its arguments, so the test errors out.",
        ));
    }
}
