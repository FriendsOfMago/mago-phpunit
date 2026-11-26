<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\CallExpression;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Internal\Type\TypeText;

use function strtolower;

/**
 * Reports a `willReturn()` or `willReturnOnConsecutiveCalls()` value that
 * doesn't fit the native return type of the configured method.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final class MockReturnHook implements MethodCallAnalysisHook
{
    public const CODE = 'mock-invalid-return';

    /**
     * Targets `willReturn()` and `willReturnOnConsecutiveCalls()` on PHPUnit's invocation builders.
     */
    public function getTargets(): array
    {
        return [
            ...ConfiguredMethod::targets('willReturn'),
            ...ConfiguredMethod::targets('willReturnOnConsecutiveCalls'),
        ];
    }

    /**
     * Asks for the argument types, the call's subtree, the file's text and
     * the types of all of the file's expressions.
     *
     * The types of the double and of the method's name are read for every
     * call. Each request for them costs more in a bigger file, so a file
     * with many calls is much faster with all types sent at once.
     */
    public function getRequirements(): array
    {
        return [
            FileAnalysisRequirement::ArgumentTypes,
            FileAnalysisRequirement::ExpressionTypes,
            FileAnalysisRequirement::TargetSubtree,
            FileAnalysisRequirement::SourceText,
        ];
    }

    /**
     * Checks each value of `$double->method('name')->willReturn(...)` and of
     * `willReturnOnConsecutiveCalls(...)`.
     *
     * PHPUnit 10.5 and later compare every `willReturn()` value with the
     * method's native return type when `willReturn()` is called, and throw an
     * `IncompatibleReturnValueException` for a value that doesn't fit. A
     * `void` or `never` method doesn't take any value, not even null.
     *
     * The values of `willReturnOnConsecutiveCalls()` aren't checked by
     * PHPUnit. The double returns them, one per call, and its code uses
     * strict types, so PHP throws a `TypeError` for a value that doesn't fit.
     * PHPUnit also takes any `willReturn()` value for some union types with
     * `null`. These values are checked the same way.
     */
    public function analyze(NodeAnalysisContext $context): void
    {
        $call = CallExpression::fromNode($context->source, $context->node);
        $method = ConfiguredMethod::of($context, $call);
        if ($method === null) {
            return;
        }

        $caller = strtolower($call->getName($context->source) ?? '') === 'willreturn'
            ? 'willReturn()'
            : 'willReturnOnConsecutiveCalls()';
        $consecutive = $caller !== 'willReturn()';
        if (self::reportBottom($context, $call, $method, $caller)) {
            return;
        }

        $atCall = $consecutive || ReturnFit::takesAnyAtSetUp($method->returnType);
        foreach ($call->arguments as $index => $argument) {
            $value = $context->argumentTypes[$index] ?? null;
            // When PHPUnit doesn't check a value at setup, a stub given as the value is called.
            $returned = $atCall ? StubbedValue::of($context, $argument, $value, $method) : [$value, false];
            if ($returned === null || $returned[0] === null) {
                continue;
            }

            [$returnedType, $fromStub] = $returned;
            $created = !$fromStub && Doubles::isCreatedBy($context, $argument->value);
            $fit = $atCall
                ? ReturnFit::atReturn($context, $returnedType, $method->returnType, $created)
                : ReturnFit::atSetUp($context, $returnedType, $method->returnType, $created);
            if (
                $fit === null
                || $fit === ReturnFit::FITS
                || $atCall && !$fromStub && StubbedValue::isCustomStub($context, $returnedType)
            ) {
                continue;
            }

            $given = TypeText::of($returnedType);
            $given = $fromStub ? "a stub that returns `{$given}`" : "`{$given}`";
            Reporter::report(
                $context,
                $fit === ReturnFit::FAILS ? Level::Error : Level::Warning,
                self::CODE,
                self::valueIssue($method, "`{$caller}` gets {$given}", $argument->value->span, $fit, $atCall),
            );
        }
    }

    /**
     * The issue for a value that doesn't fit, or fits only in part.
     *
     * @param ReturnFit::FAILS|ReturnFit::PARTIAL $fit
     * @param bool $atCall Whether PHP checks the value when the double returns it.
     *
     * @mago-expect lint:no-boolean-flag-parameter
     */
    private static function valueIssue(
        ConfiguredMethod $method,
        string $given,
        Span $span,
        string $fit,
        bool $atCall,
    ): Issue {
        $fails = $fit === ReturnFit::FAILS;
        $returned = TypeText::of($method->returnType);
        if (!$atCall) {
            return Reporter::issue(
                "{$given}, but `{$method->name}` returns `{$returned}`, so PHPUnit throws"
                . ($fails ? '.' : ' for some of these values.'),
                $span,
                help: "Return a value of the method's native return type. PHPUnit doesn't convert scalars, so `1` doesn't fit `float` and `'1'` doesn't fit `int`.",
            );
        }

        return Reporter::issue(
            "{$given}, but `{$method->name}` returns `{$returned}`, so the double fails with a `TypeError` when it returns "
            . ($fails ? 'this value.' : 'some of these values.'),
            $span,
            help: "Return a value of the method's native return type. The double uses strict types, so PHP only converts an `int` to a `float`.",
        );
    }

    /**
     * Reports values set up for a `void` or `never` method. Returns whether
     * the method is one of them.
     *
     * PHPUnit throws for any `willReturn()` value of such a method. A double
     * of a `void` method drops the values of `willReturnOnConsecutiveCalls()`.
     * A double of a `never` method ends without a return after getting a
     * value, so PHP throws a `TypeError`, unless the value is a stub that
     * throws.
     */
    private static function reportBottom(
        NodeAnalysisContext $context,
        CallExpression $call,
        ConfiguredMethod $method,
        string $caller,
    ): bool {
        $bottom = $method->bottom();
        if ($bottom === null) {
            return false;
        }

        if ($caller === 'willReturn()') {
            Reporter::report(
                $context,
                Level::Error,
                self::CODE,
                Reporter::issue(
                    "`{$method->name}` returns `{$bottom}`, so PHPUnit throws for `willReturn()`.",
                    $context->node->span,
                    help: $bottom === 'void'
                        ? 'Remove the `willReturn()` call. A double returns null from a void method.'
                        : 'Use `willThrowException()` instead. A method that returns `never` must throw.',
                ),
            );

            return true;
        }

        if ($bottom === 'never' && self::returnsValue($context, $call, $method)) {
            Reporter::report(
                $context,
                Level::Error,
                self::CODE,
                Reporter::issue(
                    "`{$method->name}` returns `never`, so the double fails with a `TypeError` after it gets a value from `willReturnOnConsecutiveCalls()`.",
                    $context->node->span,
                    help: 'Use `willThrowException()` instead. A method that returns `never` must throw.',
                ),
            );
        }

        return true;
    }

    /**
     * Whether one of the values is returned by the double, rather than a stub
     * that throws or a stub whose result isn't known.
     */
    private static function returnsValue(
        NodeAnalysisContext $context,
        CallExpression $call,
        ConfiguredMethod $method,
    ): bool {
        foreach ($call->arguments as $index => $argument) {
            $returned = StubbedValue::of($context, $argument, $context->argumentTypes[$index] ?? null, $method);
            if ($returned !== null && ($returned[1] || !StubbedValue::isCustomStub($context, $returned[0]))) {
                return true;
            }
        }

        return false;
    }
}
