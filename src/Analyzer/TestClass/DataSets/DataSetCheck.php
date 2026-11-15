<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\DataSets;

use Mago\Sdk\Analyzer\Metadata\MemberIdentifier;
use Mago\Sdk\Analyzer\Metadata\MethodMetadataProjection;
use Mago\Sdk\Span;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Analyzer\TestClass\PHPUnitAttributes;
use MagoPHPUnit\Analyzer\TestClass\TestClass;
use MagoPHPUnit\Internal\PHPUnitFeatures;

use function array_key_exists;
use function count;
use function end;
use function explode;
use function ltrim;
use function preg_split;
use function rtrim;
use function str_contains;
use function strtolower;
use function trim;

/**
 * Reports the data sets of a test, from its data providers or its `#[TestWith*]` sets, that don't fit its parameters.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class DataSetCheck
{
    public const CODE = 'data-provider-mismatch';

    /**
     * The dependency attributes, keyed by lowercased short name.
     *
     * @var array<string, bool> Whether the target class is given as an attribute argument.
     */
    private const DEPENDS = [
        'depends' => false,
        'dependsusingdeepclone' => false,
        'dependsusingshallowclone' => false,
        'dependsexternal' => true,
        'dependsexternalusingdeepclone' => true,
        'dependsexternalusingshallowclone' => true,
    ];

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

        $dependencies = self::dependencies($test, $method);
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
     * The number of dependency results passed after the data, or null when unknown.
     *
     * @mago-expect lint:halstead
     */
    private static function dependencies(TestClass $test, MethodMetadataProjection $method): ?int
    {
        // A dependency without a class name is on a test of this class.
        $class = $test->class->name;
        $codebase = $test->codebase;
        // Each dependency result is passed as one argument.
        // Targets are keyed by lowercased class and method. PHPUnit keeps one
        // result per test, so naming a test twice passes one value.
        $targets = [];
        // `@depends` tags only count when the method's annotations are parsed
        // by PHPUnit. They aren't parsed next to a PHPUnit attribute.
        foreach ($test->methodTags($method, ['depends']) as $tag) {
            // `@depends-on` is another tag. The target comes last, after an
            // optional `clone` or `shallowClone`. A dependency on
            // `Foo::class` doesn't pass a value.
            $words = $tag['gap'] === '' ? false : preg_split('/\s+/', trim($tag['value']));
            $target = $words === false ? '' : rtrim((string) end($words), characters: '()');
            [$targetClass, $name] = str_contains($target, '::') ? explode('::', $target, limit: 2) : [$class, $target];
            if ($name !== '' && strtolower($name) !== 'class') {
                $targets[strtolower(ltrim($targetClass, characters: '\\') . '::' . $name)] = [$targetClass, $name];
            }
        }

        // The attributes are read in source order, since the count on PHPUnit
        // 11 depends on it.
        foreach ($method->attributes ?? [] as $attribute) {
            $external = self::DEPENDS[PHPUnitAttributes::shortName($attribute->name) ?? ''] ?? null;
            if ($external === null) {
                continue;
            }

            $targetClass = $external
                ? PHPUnitAttributes::string($attribute, index: 0, name: 'className') ?? ''
                : $class;
            $name = PHPUnitAttributes::string($attribute, index: $external ? 1 : 0, name: 'methodName') ?? '';
            $targets[strtolower(ltrim($targetClass, characters: '\\') . '::' . $name)] = [$targetClass, $name];
        }

        // The count isn't known when a target is named by a constant, or
        // doesn't have a class or a method.
        $identifiers = [];
        foreach ($targets as [$targetClass, $name]) {
            $targetClass = ltrim($targetClass, characters: '\\');
            if ($targetClass === '' || $name === '') {
                return null;
            }

            $identifiers[] = new MemberIdentifier($targetClass, $name);
        }

        // On PHPUnit 11 and later, a dependency on a test with a native `void`
        // or `never` return type doesn't pass a value.
        if ($identifiers === [] || !PHPUnitFeatures::skipsVoidDependencies($codebase)) {
            return count($identifiers);
        }

        // PHPUnit 11 stops passing results at the first void target, and
        // PHPUnit 12 skips each one. This branch only runs on PHPUnit 11 and
        // later, and only PHPUnit 10 and 11 parse annotations, so PHPUnit 11
        // is detected with readsAnnotations().
        $stops = PHPUnitFeatures::readsAnnotations($codebase);
        $count = 0;
        foreach ($codebase->getMultipleDeclaringMethods($identifiers) as $target) {
            $type = strtolower((string) $target?->declaredReturnType?->type);
            if ($type !== 'void' && $type !== 'never') {
                ++$count;
                continue;
            }

            if ($stops) {
                break;
            }
        }

        return $count;
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
