<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\DataSets;

use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Metadata\MethodMetadataProjection;
use Mago\Sdk\Analyzer\Metadata\ParameterMetadata;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\TypeComparison;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use MagoPHPUnit\Analyzer\Support\KnownHierarchy;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Analyzer\TestClass\TestClass;
use MagoPHPUnit\Internal\Type\TypeText;
use WeakMap;

use function array_key_exists;
use function count;
use function strtolower;

/**
 * Reports a dependency whose result doesn't fit the test's parameter.
 *
 * PHPUnit passes the result of each dependency after the test's data, and
 * its test runner uses strict types. The test errors out with a `TypeError`
 * when a result doesn't fit its parameter. The result has the native return
 * type of the test it comes from.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final class DependencyCheck
{
    public const CODE = 'dependency-mismatch';

    /**
     * The reported dependencies of each class, keyed by test, position and
     * parameter. A test is checked once per data provider and per number of
     * values in its data sets.
     *
     * @var WeakMap<TestClass, array<string, true>>|null
     */
    private static ?WeakMap $reported = null;

    /**
     * Whether each class passes its own arguments to its tests.
     *
     * @var WeakMap<TestClass, bool>|null
     */
    private static ?WeakMap $ownArguments = null;

    private function __construct() {}

    /**
     * Checks the results passed to each test of the class that doesn't get data.
     *
     * The results of a test with data are checked with its data sets.
     */
    public static function check(TestClass $test): void
    {
        foreach ($test->methods() as $method) {
            if (!$test->isTest($method)) {
                continue;
            }

            $passed = Dependencies::passed($test, $method);
            if ($passed !== null && $passed !== [] && !TestWithSets::givesData($test, $method)) {
                self::checkResults($test, $method, $passed, 0);
            }
        }
    }

    /**
     * Checks the results passed to a test after the given number of values.
     *
     * A test runs with the arguments of `invokeTestMethod()` on PHPUnit 12.5
     * and later. A class that overrides it isn't checked.
     *
     * @param non-empty-list<array{string, ?Type, Span|SourceLocation, 'null'|'exception'|null}> $passed
     * @param int $after The number of data values before the results.
     *
     * @mago-expect lint:halstead
     */
    public static function checkResults(
        TestClass $test,
        MethodMetadataProjection $method,
        array $passed,
        int $after,
    ): void {
        $own = self::$ownArguments ?? new WeakMap();
        self::$ownArguments = $own;
        $own[$test] ??= self::passesOwnArguments($test);
        if ($own[$test]) {
            return;
        }

        // A result that may fit isn't reported. That includes the null passed by a test that expects an exception.
        $comparisons = [];
        $pending = [];
        foreach (self::parameters($method, $after, $passed) as $position => $parameter) {
            [, $type, , $null] = $passed[$position];
            $expected = $parameter->declaredType?->type;
            // A class whose parents aren't all known may still fit.
            if (
                $type === null
                || $expected === null
                || !KnownHierarchy::isComplete($test->codebase, $type, $expected)
            ) {
                continue;
            }

            $comparisons[] = TypeComparison::containedBy($type, $expected);
            $comparisons[] = TypeComparison::canBeIdentical(
                $null === 'exception' ? Type::union($type, Type::null()) : $type,
                $expected,
            );
            $pending[] = [$position, $parameter->name, $type, $expected];
        }

        // Each pending result has two answers. The first is contained by, and the second is can be identical.
        $answers = $comparisons === [] ? [] : $test->context->types->compareMultiple($comparisons);
        $map = self::$reported ?? new WeakMap();
        self::$reported = $map;

        /** @var array<string, true> $reported */
        $reported = $map[$test] ?? [];
        $name = $method->originalName ?? $method->name ?? '';
        foreach ($pending as $index => [$position, $parameter, $type, $expected]) {
            $key = "{$name}:{$position}:{$parameter}";
            if (
                ($answers[2 * $index] ?? true)
                || ($answers[(2 * $index) + 1] ?? true)
                || array_key_exists($key, $reported)
            ) {
                continue;
            }

            $reported[$key] = true;
            [$target, , $where, $null] = $passed[$position];
            $given = $null === 'null'
                ? "PHPUnit passes null for `{$target}`"
                : "`{$target}` returns `" . TypeText::of($type) . '`';
            $test->reporter->error(self::CODE, Reporter::issue(
                "{$given}, but {$parameter} of {$name}() takes `" . TypeText::of($expected) . '`.',
                $where,
                'PHPUnit passes the result of each dependency to the test after its data, so the test errors out with a '
                . '`TypeError`.',
            ));
        }

        $map[$test] = $reported;
    }

    /**
     * The parameter filled by each result, keyed by the result's position.
     *
     * A variadic parameter takes the results after the other parameters. A
     * result past the parameters isn't checked.
     *
     * @param list<mixed> $passed
     *
     * @return array<int, ParameterMetadata>
     */
    private static function parameters(MethodMetadataProjection $method, int $after, array $passed): array
    {
        $parameters = $method->parameters ?? [];
        $last = $parameters[count($parameters) - 1] ?? null;
        $variadic = $last !== null && $last->flags->contains(MetadataFlags::VARIADIC) ? $last : null;
        $found = [];
        foreach ($passed as $position => $_) {
            $index = $after + $position;
            $parameter =
                $variadic !== null && $index >= (count($parameters) - 1) ? $variadic : $parameters[$index] ?? null;
            if ($parameter === null) {
                break;
            }

            $found[$position] = $parameter;
        }

        return $found;
    }

    /**
     * Whether the class or a parent other than `TestCase` overrides `invokeTestMethod()`.
     *
     * The test may get other arguments then.
     * PHPUnit 12.5 and later call the test through `invokeTestMethod()`.
     * Earlier versions don't have it.
     */
    private static function passesOwnArguments(TestClass $test): bool
    {
        $codebase = $test->codebase;
        $declaring = $codebase->getDeclaringMethod($test->class->name, 'invokeTestMethod')?->identifier->class;

        return (
            $declaring !== null
            && strtolower($declaring) !== 'phpunit\framework\testcase'
            && $codebase->methodExists('PHPUnit\Framework\TestCase', method: 'invokeTestMethod')
        );
    }
}
