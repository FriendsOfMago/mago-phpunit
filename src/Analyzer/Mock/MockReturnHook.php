<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\Node;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Internal\Type\TypeText;

use function in_array;
use function sprintf;
use function strtolower;

/**
 * Reports a value returned by a double that doesn't fit the native return
 * type of the configured method.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class MockReturnHook implements MethodCallAnalysisHook
{
    public const CODE = 'mock-invalid-return';

    /**
     * The builder methods, lowercased, with the name written in messages.
     */
    private const BUILDERS = [
        'willreturn' => 'willReturn',
        'willreturnonconsecutivecalls' => 'willReturnOnConsecutiveCalls',
        'willreturncallback' => 'willReturnCallback',
        'willreturnargument' => 'willReturnArgument',
        'willreturnself' => 'willReturnSelf',
        'willreturnmap' => 'willReturnMap',
        'willreturnstrictmap' => 'willReturnStrictMap',
        'will' => 'will',
    ];

    /**
     * The builder methods that take the returned values, rather than create a stub.
     */
    private const GIVEN = ['willReturn()', 'willReturnOnConsecutiveCalls()'];

    /**
     * Targets `will()` and the `willReturn*()` methods of PHPUnit's invocation builders.
     */
    public function getTargets(): array
    {
        $targets = [];
        foreach (self::BUILDERS as $name) {
            $targets = [...$targets, ...ConfiguredMethod::targets($name)];
        }

        return $targets;
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
     * Checks the values returned for `$double->method('name')->willReturn(...)`
     * and the other `willReturn*()` methods.
     *
     * PHPUnit 10.5 and later compare every `willReturn()` value with the
     * method's native return type when `willReturn()` is called, and throw an
     * `IncompatibleReturnValueException` for a value that doesn't fit. A
     * `void` or `never` method doesn't take any value, not even null.
     *
     * The other values aren't checked by PHPUnit. The double returns them,
     * and its code uses strict types, so PHP throws a `TypeError` for a value
     * that doesn't fit. PHPUnit also takes any `willReturn()` value for some
     * union types with `null`, and a stub given to `willReturn()` passes the
     * check when the method returns any object. These values are checked
     * when the double returns them.
     */
    public function analyze(NodeAnalysisContext $context): void
    {
        $call = CallExpression::fromNode($context->source, $context->node);
        $builder = self::BUILDERS[strtolower($call->getName($context->source) ?? '')] ?? null;
        $method = $builder === null ? null : ConfiguredMethod::of($context, $call);
        if ($builder === null || $method === null) {
            return;
        }

        $caller = "{$builder}()";
        if ($builder === 'willReturn') {
            self::checkWillReturn($context, $call, $method);

            return;
        }

        $bottom = $method->bottom();
        foreach (self::returned($context, $call, $builder, $method) as [$span, $values]) {
            if ($bottom === 'never' && $values !== []) {
                self::reportNever($context, $method, $caller);

                return;
            }

            if ($bottom === null) {
                self::checkValues($context, $method, $caller, $values, $span);
            }
        }
    }

    /**
     * The values returned for each argument of a builder method other than
     * `willReturn()`, with the span to report.
     *
     * @return list<array{Span, list<ReturnedValue>}>
     */
    private static function returned(
        NodeAnalysisContext $context,
        CallExpression $call,
        string $builder,
        ConfiguredMethod $method,
    ): array {
        $first = $call->arguments[0] ?? null;
        $type = $context->argumentTypes[0] ?? null;
        $values = match ($builder) {
            'willReturnOnConsecutiveCalls' => null,
            'willReturnSelf' => [new ReturnedValue($method->double, fromStub: true)],
            'willReturnCallback' => $first === null
                ? null
                : ArgumentStubs::callback($context, $first->value, $type, $method),
            'willReturnArgument' => ArgumentStubs::argument($method, $type?->getLiteralInt()),
            'will' => $first === null ? null : StubbedValue::of($context, $first->value, $type, $method),
            default => ValueMapRows::values($type, $method, filled: true, strict: $builder === 'willReturnStrictMap'),
        };

        if ($builder !== 'willReturnOnConsecutiveCalls') {
            $span = $first?->value->span ?? $context->node->span;

            return $values === null || $first?->unpacked === true ? [] : [[$span, $values]];
        }

        $returned = [];
        foreach (self::givenValues($context, $call) as [$node, $value]) {
            $returned[] = [$node->span, StubbedValue::of($context, $node, $value, $method) ?? []];
        }

        return $returned;
    }

    /**
     * Checks the values given to `willReturn()`.
     *
     * PHPUnit checks the values when `willReturn()` is called. A stub that
     * passes the check, or any value for a return type that PHPUnit doesn't
     * check, is checked when the double returns it.
     */
    private static function checkWillReturn(
        NodeAnalysisContext $context,
        CallExpression $call,
        ConfiguredMethod $method,
    ): void {
        $bottom = $method->bottom();
        if ($bottom !== null) {
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

            return;
        }

        $takesAny = ReturnFit::takesAnyAtSetUp($method->returnType);
        foreach (self::givenValues($context, $call) as [$node, $value]) {
            if ($takesAny) {
                $values = StubbedValue::of($context, $node, $value, $method) ?? [];
                self::checkValues($context, $method, 'willReturn()', $values, $node->span);
                continue;
            }

            $fit = ReturnFit::atSetUp($context, $value, $method->returnType, Doubles::isCreatedBy($context, $node));
            if ($fit === ReturnFit::FITS) {
                $values = StubbedValue::isStub($value) ? StubbedValue::of($context, $node, $value, $method) ?? [] : [];
                self::checkValues($context, $method, 'willReturn()', $values, $node->span);
                continue;
            }

            if ($fit !== null) {
                self::reportSetUp($context, $method, $fit, $value, $node->span);
            }
        }
    }

    /**
     * The values given to `willReturn()` or `willReturnOnConsecutiveCalls()`,
     * with their types. An unpacked array that's written out, such as
     * `...[1, 2]`, gives its elements. Other unpacked values are left out.
     *
     * @return list<array{Node, Type}>
     */
    private static function givenValues(NodeAnalysisContext $context, CallExpression $call): array
    {
        $given = [];
        $unpacked = [];
        foreach ($call->arguments as $index => $argument) {
            if (!$argument->unpacked) {
                $type = $context->argumentTypes[$index] ?? null;
                $given = $type === null ? $given : [...$given, [$argument->value, $type]];
                continue;
            }

            $unpacked = [...$unpacked, ...(StubSyntax::elements($context->source, $argument->value) ?? [])];
        }

        $types = $unpacked === [] ? [] : $context->analysis->getMultipleExpressionTypes($unpacked);
        foreach ($unpacked as $position => $node) {
            $type = $types[$position] ?? null;
            $given = $type === null ? $given : [...$given, [$node, $type]];
        }

        return $given;
    }

    /**
     * Reports the first value that doesn't fit, checked when the double returns it.
     *
     * @param list<ReturnedValue> $values
     */
    private static function checkValues(
        NodeAnalysisContext $context,
        ConfiguredMethod $method,
        string $caller,
        array $values,
        Span $span,
    ): void {
        $found = null;
        foreach ($values as $value) {
            $fit = ReturnFit::atReturn($context, $value->type, $method->returnType, $value->exact);
            $fit = match (true) {
                $fit === ReturnFit::FAILS && $value->reporting === ReturnedValue::AS_WARNING => ReturnFit::PARTIAL,
                $fit === ReturnFit::PARTIAL && $value->reporting === ReturnedValue::FAILS_ONLY => null,
                default => $fit,
            };
            if ($fit === ReturnFit::FAILS || $fit === ReturnFit::PARTIAL && $found === null) {
                $found = [$fit, $value];
            }

            if ($fit === ReturnFit::FAILS) {
                break;
            }
        }

        if ($found === null) {
            return;
        }

        [$fit, $value] = $found;
        $type = TypeText::of($value->type);
        $subject = match (true) {
            !in_array($caller, self::GIVEN, strict: true) => "`{$caller}` makes the double return `{$type}`",
            $value->fromStub => "`{$caller}` gets a stub that returns `{$type}`",
            default => "`{$caller}` gets `{$type}`",
        };
        $returned = TypeText::of($method->returnType);
        $fails = $fit === ReturnFit::FAILS;
        Reporter::report(
            $context,
            $fails ? Level::Error : Level::Warning,
            self::CODE,
            Reporter::issue(
                "{$subject}, but `{$method->name}` returns `{$returned}`, so the double fails with a `TypeError` when it returns "
                . ($fails ? 'this value.' : 'some of these values.')
                . ($value->reason === null ? '' : " {$value->reason}"),
                $span,
                help: "Return a value of the method's native return type. The double uses strict types, so PHP only converts an `int` to a `float`.",
            ),
        );
    }

    /**
     * Reports a `willReturn()` value that PHPUnit doesn't take, or takes only in part.
     *
     * @param ReturnFit::FAILS|ReturnFit::PARTIAL $fit
     */
    private static function reportSetUp(
        NodeAnalysisContext $context,
        ConfiguredMethod $method,
        string $fit,
        Type $value,
        Span $span,
    ): void {
        $fails = $fit === ReturnFit::FAILS;
        Reporter::report(
            $context,
            $fails ? Level::Error : Level::Warning,
            self::CODE,
            Reporter::issue(
                sprintf(
                    '`willReturn()` gets `%s`, but `%s` returns `%s`, so PHPUnit throws%s',
                    TypeText::of($value),
                    $method->name,
                    TypeText::of($method->returnType),
                    $fails ? '.' : ' for some of these values.',
                ),
                $span,
                help: "Return a value of the method's native return type. PHPUnit doesn't convert scalars, so `1` doesn't fit `float` and `'1'` doesn't fit `int`.",
            ),
        );
    }

    /**
     * Reports a value set up for a `never` method, other than by
     * `willReturn()`. The double ends without a return after it gets the
     * value, so PHP throws a `TypeError`.
     */
    private static function reportNever(NodeAnalysisContext $context, ConfiguredMethod $method, string $caller): void
    {
        Reporter::report(
            $context,
            Level::Error,
            self::CODE,
            Reporter::issue(
                sprintf(
                    '`%s` returns `never`, so the double fails with a `TypeError` after it gets a value from `%s`.',
                    $method->name,
                    $caller,
                ),
                $context->node->span,
                help: 'Use `willThrowException()` instead. A method that returns `never` must throw.',
            ),
        );
    }
}
