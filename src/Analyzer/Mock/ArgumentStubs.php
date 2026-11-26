<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Syntax\Node;
use MagoPHPUnit\Internal\Type\Bottom;

use function count;

/**
 * Finds the values returned by `ReturnArgument` and `ReturnCallback`
 * stubs, which are also created by `willReturnArgument()` and
 * `willReturnCallback()`.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final class ArgumentStubs
{
    private function __construct() {}

    /**
     * The value returned for the argument at the index, as by
     * `ReturnArgument` and `willReturnArgument()`, or null when it isn't
     * known.
     *
     * The argument has the parameter's native type. A variadic parameter
     * takes the index and those after it. A negative index gets null, and so
     * does an index past the parameters, unless the call passes more
     * arguments than the method declares. A parameter type that's wider than
     * the return type is only reported when no part of it fits, since the
     * test may only pass values that fit.
     *
     * @return list<ReturnedValue>|null
     */
    public static function argument(ConfiguredMethod $method, ?int $index): ?array
    {
        if ($index === null) {
            return null;
        }

        if ($index < 0) {
            return [new ReturnedValue(Type::null(), fromStub: true, reason: 'A negative index always gets null.')];
        }

        $parameter = null;
        foreach ($method->metadata->parameters as $position => $candidate) {
            $variadic = $position < $index && $candidate->flags->contains(MetadataFlags::VARIADIC);
            $parameter = $position === $index || $variadic ? $candidate : $parameter;
        }

        if ($parameter === null) {
            return [new ReturnedValue(
                Type::null(),
                fromStub: true,
                reporting: ReturnedValue::AS_WARNING,
                reason: "The method doesn't have a parameter at index {$index}, so the argument is null unless the call passes more arguments.",
            )];
        }

        $type = $parameter->declaredType?->type;

        return $type === null ? null : [new ReturnedValue($type, fromStub: true, reporting: ReturnedValue::FAILS_ONLY)];
    }

    /**
     * The value returned for a callback, as by `ReturnCallback` and
     * `willReturnCallback()`, or null when it isn't known.
     *
     * A `void` callback returns null, and a `never` callback throws. A
     * return type inferred by Mago is only reported when no part of it
     * fits. A closure inferred to return `void` or `null` may still always
     * throw, through a `void` helper, so it isn't reported for a `never`
     * method.
     *
     * @param Node $node The callback given to the stub.
     *
     * @return list<ReturnedValue>|null
     */
    public static function callback(StubScope $scope, Node $node, ?Type $callback): ?array
    {
        [$scope, $node] = CallbackReturn::isClosure($callback) ? $scope->expression($node) : [$scope, $node];
        $returned = CallbackReturn::of($scope->context, $scope->source, $node, $callback);
        $method = $scope->method;
        if ($returned === null) {
            return null;
        }

        [$type, $inferred] = $returned;
        $bottom = Bottom::of($type);
        if ($bottom === 'never' || $inferred && $method->bottom() === 'never' && self::isNothing($type)) {
            return [];
        }

        return [new ReturnedValue(
            $bottom === 'void' ? Type::null() : $type,
            fromStub: true,
            reporting: $inferred ? ReturnedValue::FAILS_ONLY : ReturnedValue::ANY,
        )];
    }

    /**
     * Whether the type is `void` or `null`.
     */
    private static function isNothing(Type $type): bool
    {
        $atomics = $type->atomicTypes;
        $atomic = count($atomics) === 1 ? $atomics[0] : null;

        return (
            $atomic instanceof SimpleAtomicType
            && ($atomic->kind === SimpleAtomicTypeKind::Void || $atomic->kind === SimpleAtomicTypeKind::Null)
        );
    }
}
