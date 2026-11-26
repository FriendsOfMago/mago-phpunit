<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\CallArgument;
use Mago\Sdk\Syntax\CallExpression;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Internal\Type\Types;

use function array_key_exists;
use function array_values;
use function count;
use function ltrim;
use function str_starts_with;
use function strtolower;

/**
 * Reports method names rejected by `onlyMethods()`, `createPartialMock()` or `addMethods()`.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class MockBuilderMethodsHook implements MethodCallAnalysisHook
{
    public const ADDED_CODE = 'mock-added-method-exists';

    /**
     * Targets the builder methods on PHPUnit 10 to 13, and `createPartialMock()`.
     *
     * `TestDoubleBuilder` declares `onlyMethods()` on PHPUnit 12.5 and later.
     */
    public function getTargets(): array
    {
        return [
            MethodTarget::exact('PHPUnit\Framework\MockObject\MockBuilder', method: 'onlyMethods'),
            MethodTarget::exact('PHPUnit\Framework\MockObject\MockBuilder', method: 'addMethods'),
            MethodTarget::exact('PHPUnit\Framework\MockObject\TestDoubleBuilder', method: 'onlyMethods'),
            MethodTarget::exact('PHPUnit\Framework\TestCase', method: 'createPartialMock'),
        ];
    }

    /**
     * Asks for the receiver and argument types, the call's subtree and the
     * file's text.
     */
    public function getRequirements(): array
    {
        return [
            FileAnalysisRequirement::ReceiverType,
            FileAnalysisRequirement::ArgumentTypes,
            FileAnalysisRequirement::TargetSubtree,
            FileAnalysisRequirement::SourceText,
        ];
    }

    /**
     * Checks the literal method names of the call.
     *
     * PHPUnit looks each name up with `ReflectionClass::hasMethod()`.
     * `onlyMethods()` throws for a method that's missing from the class.
     * `addMethods()` throws for a method that exists in it.
     * `createPartialMock()` passes its names to `onlyMethods()`. A method from
     * an `@method` tag or `__call()` isn't found by reflection. A private
     * method of a parent class is found.
     */
    public function analyze(NodeAnalysisContext $context): void
    {
        $call = CallExpression::fromNode($context->source, $context->node);
        [$written, $adds, $partial] = match (strtolower($call->getName($context->source) ?? '')) {
            'createpartialmock' => ['createPartialMock()', false, true],
            'addmethods' => ['addMethods()', true, false],
            default => ['onlyMethods()', false, false],
        };
        $methods = self::argument($call, $partial ? 1 : 0, 'methods');
        $class = $partial ? self::partialClass($context, $call) : self::boundClass($context);
        $names = $methods === null ? [] : self::names($context->argumentTypes[$methods->index] ?? null);
        $missing = $class === null || $names === [] ? null : ReflectedMethods::missing($context, $class, $names);
        if ($class === null || $methods === null || $missing === null) {
            return;
        }

        $reported = [];
        foreach ($names as $name) {
            $exists = $name !== '' && !array_key_exists(strtolower($name), $missing);
            if ($exists === $adds) {
                $reported[] = $name;
            }
        }

        // With a `MockBuilder<Foo>` receiver, the mocked class is `Foo` or a subclass. A method of
        // `Foo` exists in every subclass, so `addMethods()` is checked against `Foo` either way.
        // `onlyMethods()` is only checked when the chain starts at a literal class name.
        if ($reported === [] || !$partial && !$adds && !self::startsWithLiteral($context, $call)) {
            return;
        }

        foreach ($reported as $name) {
            [$message, $help] = match (true) {
                $adds => [
                    "The method `{$name}()` given to `addMethods()` already exists on `{$class}`.",
                    'PHPUnit throws when the test runs. Use `onlyMethods()` for a method of the class.',
                ],
                $name === '' => [
                    "An empty method name is given to `{$written}`.",
                    'PHPUnit throws when the test runs.',
                ],
                default => [
                    "The method `{$name}()` given to `{$written}` doesn't exist on `{$class}`.",
                    'PHPUnit throws when the test runs. A method from an `@method` tag or `__call()` isn\'t found by '
                        . 'reflection.',
                ],
            };
            $code = $adds ? self::ADDED_CODE : MockMethodHook::CODE;
            Reporter::report($context, Level::Error, $code, Reporter::issue($message, $methods->value->span, $help));
        }
    }

    /**
     * The argument at the position, or the one with the name, or null when
     * it's unpacked.
     */
    private static function argument(CallExpression $call, int $position, string $name): ?CallArgument
    {
        foreach ($call->arguments as $argument) {
            if ($argument->unpacked) {
                return null;
            }

            if ($argument->name === null ? $argument->index === $position : strtolower($argument->name) === $name) {
                return $argument;
            }
        }

        return null;
    }

    /**
     * The class of a `MockBuilder<Foo>` receiver, which may also be a subclass, or null.
     */
    private static function boundClass(NodeAnalysisContext $context): ?string
    {
        $atomic = $context->receiverType->atomicTypes[0] ?? null;
        $names = $atomic instanceof NamedObjectType ? Types::names($atomic->parameters[0] ?? null) : [];

        return count($names) === 1 ? $names[0] : null;
    }

    /**
     * Whether the builder chain starts at `getMockBuilder()` or
     * `getStubBuilder()` with a literal class name.
     */
    private static function startsWithLiteral(NodeAnalysisContext $context, CallExpression $call): bool
    {
        $expression = $call->receiver;
        while ($expression !== null) {
            $inner = CallExpression::fromExpression($context->source, $expression);
            if ($inner === null) {
                return false;
            }

            $name = strtolower($inner->getName($context->source) ?? '');
            if ($name === 'getmockbuilder' || $name === 'getstubbuilder') {
                $argument = $inner->arguments[0] ?? null;

                return $argument !== null
                && self::isLiteralClass($context, $argument, $context->analysis->getExpressionType($argument->value));
            }

            $expression = $inner->receiver;
        }

        return false;
    }

    /**
     * The literal class name given to `createPartialMock()`, or null.
     */
    private static function partialClass(NodeAnalysisContext $context, CallExpression $call): ?string
    {
        $argument = $call->arguments[0] ?? null;
        $type = $context->argumentTypes[0] ?? null;
        if ($argument === null || $argument->name !== null || !self::isLiteralClass($context, $argument, $type)) {
            return null;
        }

        $name = ltrim(Types::literalName($type), characters: '\\');

        return $name === '' ? null : $name;
    }

    /**
     * Whether the argument of the given type names one class, such as `Foo::class`.
     *
     * A constant read through `static::` can be overridden in a subclass.
     * A constant read through `$this::` doesn't have a literal type in a
     * class that isn't final, so it isn't taken either.
     */
    private static function isLiteralClass(NodeAnalysisContext $context, CallArgument $argument, ?Type $type): bool
    {
        if (str_starts_with(strtolower($context->source->getText($argument->value)), 'static::')) {
            return false;
        }

        return Types::literalName($type) !== '';
    }

    /**
     * The literal method names in an array of the given type, without repeats.
     *
     * Each literal of a union element is returned. Elements that aren't
     * literals are left out.
     *
     * @return list<string>
     */
    private static function names(?Type $type): array
    {
        $atomic = $type?->atomicTypes[0] ?? null;
        $items = match (true) {
            count($type->atomicTypes ?? []) !== 1 => null,
            $atomic instanceof ListType => $atomic->knownElements,
            $atomic instanceof KeyedArrayType => $atomic->knownItems,
            default => null,
        };

        $names = [];
        foreach ($items ?? [] as $item) {
            foreach (Types::literalStrings($item->type) ?? [] as $literal) {
                $names[strtolower($literal)] ??= $literal;
            }
        }

        return array_values($names);
    }
}
