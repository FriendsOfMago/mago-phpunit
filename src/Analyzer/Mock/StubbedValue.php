<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Syntax\CallArgument;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use MagoPHPUnit\Internal\Type\Types;

use function array_filter;
use function array_values;
use function count;
use function in_array;
use function str_starts_with;
use function strtolower;

/**
 * Finds the value returned by a double for a value given to
 * `willReturnOnConsecutiveCalls()`.
 *
 * PHPUnit calls a stub in the list, such as `new ReturnStub(5)`, and the
 * double returns its result. `ReturnStub` returns its value, `ReturnSelf`
 * returns the double and `ReturnArgument` returns an argument of the call.
 * The results of other stubs aren't known here.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class StubbedValue
{
    private const STUBS = 'phpunit\\framework\\mockobject\\stub\\';

    private const STUB_INTERFACE = 'phpunit\\framework\\mockobject\\stub\\stub';

    private function __construct() {}

    /**
     * The type of the returned value and whether a stub returns it, or null
     * when the returned value isn't known.
     *
     * @param ?Type $value The type of the value given to `willReturnOnConsecutiveCalls()`.
     *
     * @return array{Type, bool}|null
     */
    public static function of(
        NodeAnalysisContext $context,
        CallArgument $argument,
        ?Type $value,
        ConfiguredMethod $method,
    ): ?array {
        if ($value === null) {
            return null;
        }

        $stub = self::stub($value);
        if ($stub === null) {
            return [$value, false];
        }

        if (count($value->atomicTypes) !== 1) {
            return null;
        }

        $returned = match ($stub) {
            self::STUBS . 'returnself' => $method->double,
            self::STUBS . 'returnstub' => self::constructorValue($context, $argument),
            self::STUBS . 'returnargument' => self::argument($context, $argument, $method),
            default => null,
        };

        return $returned === null ? null : [$returned, true];
    }

    /**
     * Whether the value is an object of a class that implements PHPUnit's
     * `Stub` interface. Its result isn't known here.
     */
    public static function isCustomStub(NodeAnalysisContext $context, Type $value): bool
    {
        $names = array_values(array_filter(
            Types::names($value),
            static fn(string $name): bool => !Doubles::isDoubleName($name),
        ));

        foreach ($names === [] ? [] : $context->codebase->getMultipleClassLikes($names) as $metadata) {
            if (in_array(self::STUB_INTERFACE, $metadata->parentInterfaces ?? [], strict: true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The lowercase name of the PHPUnit stub class in the type, or null.
     */
    private static function stub(Type $type): ?string
    {
        foreach (Types::names($type) as $name) {
            $lower = strtolower($name);
            if (str_starts_with($lower, self::STUBS)) {
                return $lower;
            }
        }

        return null;
    }

    /**
     * The type of the argument returned by `new ReturnArgument($index)`, or
     * null when it isn't known.
     *
     * PHPUnit passes the call's arguments with the defaults filled in, so the
     * index of a parameter gets a value of the parameter's native type. A
     * negative index always gets null. A call can pass more arguments than
     * the method declares, so a larger index isn't checked.
     */
    private static function argument(
        NodeAnalysisContext $context,
        CallArgument $argument,
        ConfiguredMethod $method,
    ): ?Type {
        $index = self::constructorValue($context, $argument)?->getLiteralInt();
        if ($index === null) {
            return null;
        }

        if ($index < 0) {
            return Type::null();
        }

        $parameter = $method->metadata->parameters[$index] ?? null;
        if ($parameter === null || $parameter->flags->contains(MetadataFlags::VARIADIC)) {
            return null;
        }

        return $parameter->declaredType?->type;
    }

    /**
     * The type of the first value given to the stub's constructor, such as
     * `5` in `new ReturnStub(5)`, or null.
     */
    private static function constructorValue(NodeAnalysisContext $context, CallArgument $argument): ?Type
    {
        $expression = self::firstConstructorArgument($context, $argument->value);

        return $expression === null ? null : $context->analysis->getExpressionType($expression);
    }

    /**
     * The first positional argument of a `new` expression, or null.
     */
    private static function firstConstructorArgument(NodeAnalysisContext $context, Node $node): ?Node
    {
        $source = $context->source;
        if ($node->kind !== NodeKind::Instantiation) {
            return null;
        }

        foreach ($source->getChildren($node) as $child) {
            if ($child->kind !== NodeKind::ArgumentList) {
                continue;
            }

            // The node layout is the one read by `CallExpression`.
            $first = $source->getChildren($child)[0] ?? null;
            $variant = $first === null ? null : $source->getChildren($first)[0] ?? null;

            return $variant?->kind === NodeKind::PositionalArgument ? $source->getChildren($variant)[0] ?? null : null;
        }

        return null;
    }
}
