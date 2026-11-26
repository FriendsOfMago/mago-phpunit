<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\DataSets;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableSignature;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\IterableType;
use Mago\Sdk\Analyzer\TypeComparison;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use MagoPHPUnit\Analyzer\Support\KnownHierarchy;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Analyzer\TestClass\PHPUnitAttributes;
use MagoPHPUnit\Analyzer\TestClass\TestClass;
use MagoPHPUnit\Internal\PHPUnitFeatures;
use MagoPHPUnit\Internal\Type\TypeText;

use function array_map;

/**
 * Reports a closure provider that can't be called by PHPUnit, or whose result isn't iterable.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class ClosureProviderCheck
{
    private function __construct() {}

    /**
     * Checks the `#[DataProviderClosure]` closures of the class's tests.
     *
     * PHPUnit calls the closure without arguments, so a required parameter
     * makes the test error out. PHPUnit also fails the test when the result
     * isn't an array or a `Traversable`. A first-class callable such as
     * `self::cases(...)` must name a static method.
     */
    public static function check(TestClass $test): void
    {
        if (!PHPUnitFeatures::hasClosureProviders($test->codebase)) {
            return;
        }

        $closures = [];
        foreach ($test->methods() as $method) {
            if (!$test->isTest($method)) {
                continue;
            }

            foreach (PHPUnitAttributes::of($method, name: 'DataProviderClosure') as $attribute) {
                $span = $attribute->getArgument(0, 'closure')?->valueLocation?->span;
                if ($span !== null) {
                    $closures[] = [$span, $attribute->location, TestProviders::classCallable($test, $span)];
                }
            }
        }

        if ($closures === []) {
            return;
        }

        $types = $test->context->analysis->getMultipleExpressionTypes(array_map(
            static fn(array $closure): Span => $closure[0],
            $closures,
        ));
        $iterable = Type::fromAtomic(new IterableType(Type::mixed(), Type::mixed(), null));
        $returns = [];
        $comparisons = [];
        foreach ($closures as $index => [, $where, $callable]) {
            $atomic = $types[$index]->atomicTypes[0] ?? null;
            $signature = $atomic instanceof CallableType ? $atomic->signature : null;
            if (self::reportSignature($test, $where, $callable, $signature) || $signature?->returnType === null) {
                continue;
            }

            // A class whose parents aren't all known may still be iterable.
            if (!KnownHierarchy::isComplete($test->codebase, $signature->returnType)) {
                continue;
            }

            $comparisons[] = TypeComparison::containedBy($signature->returnType, $iterable);
            $comparisons[] = TypeComparison::canBeIdentical($signature->returnType, $iterable);
            $returns[] = [$signature->returnType, $where];
        }

        $answers = $comparisons === [] ? [] : $test->context->types->compareMultiple($comparisons);
        foreach ($returns as $index => [$returned, $where]) {
            if (($answers[2 * $index] ?? true) || ($answers[(2 * $index) + 1] ?? true)) {
                continue;
            }

            self::report(
                $test,
                'The data provider closure returns `' . TypeText::of($returned) . "`, which isn't iterable.",
                $where,
                'PHPUnit fails the test when its data provider returns something other than an array or a '
                . '`Traversable`.',
            );
        }
    }

    /**
     * Reports a method that isn't static, or a required parameter, and
     * returns whether one was reported.
     */
    private static function reportSignature(
        TestClass $test,
        SourceLocation $where,
        ?string $callable,
        ?CallableSignature $signature,
    ): bool {
        $method = $callable === null ? null : $test->method($callable);
        if ($method !== null && !$method->static) {
            $name = $method->originalName ?? $callable;
            self::report(
                $test,
                "The data provider closure names {$name}(), which isn't static.",
                $where,
                'PHP fails to create the closure, since there is no object to call the method on. Make the method '
                . 'static.',
            );

            return true;
        }

        foreach ($signature->parameters ?? [] as $parameter) {
            if ($parameter->hasDefault || $parameter->variadic) {
                continue;
            }

            self::report(
                $test,
                "The data provider closure has the required parameter {$parameter->name}. PHPUnit calls it "
                . 'without arguments.',
                $where,
                'Give the parameter a default value, or remove it.',
            );

            return true;
        }

        return false;
    }

    /**
     * Reports an `invalid-data-provider` error.
     */
    private static function report(TestClass $test, string $message, SourceLocation $where, string $help): void
    {
        $test->reporter->error(DataProviderCheck::CODE, Reporter::issue($message, $where, $help));
    }
}
