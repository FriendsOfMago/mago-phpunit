<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Prophecy;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\TypeComparison;
use Mago\Sdk\Reporting\Level;

use function array_unique;
use function count;

/**
 * Compares an argument token with the parameter it stands for.
 *
 * Each comparison gives the level of the issue, its kind and the type shown
 * for the token, or null when the token can match the parameter. The issue
 * is an error when the native parameter type rules the token out too, or a
 * warning when only the docblock type does.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final class TokenCheck
{
    private function __construct() {}

    /**
     * The issue for a token that only matches arguments of one type, such
     * as `Argument::type('int')`, or null when the parameter can take one.
     *
     * @return array{Level, 'never', Type}|null
     */
    public static function need(
        NodeAnalysisContext $context,
        string $matcher,
        ?Type $value,
        Type $declared,
        ?Type $native,
    ): ?array {
        $need = TokenNeed::of($context->codebase, $matcher, $value?->getLiteralString());
        if ($need === null) {
            return null;
        }

        // `is_int()` is false for a float argument, and a `float` parameter
        // turns an int into a float.
        [$overlap, $nativeOverlap] = $context->types->compareMultiple([
            TypeComparison::canBeIdentical($declared, $need),
            TypeComparison::canBeIdentical($native ?? $declared, $need),
        ]);
        $overlap = $overlap && !self::numbersApart($declared, $need);
        $nativeOverlap = $nativeOverlap && !self::numbersApart($native ?? $declared, $need);
        if ($overlap) {
            return null;
        }

        return [$native !== null && !$nativeOverlap ? Level::Error : Level::Warning, 'never', $need];
    }

    /**
     * The issue for a value given to `Argument::is()` or `Argument::exact()`
     * that never matches the parameter's arguments, or null.
     *
     * `Argument::exact()` compares like a value passed as the argument, so
     * a prophecy of an object and a number for a string match too.
     * `Argument::is()` compares with `===`.
     *
     * @param ?string $exact The class that declares the method for
     *   `Argument::exact()`, or null for `Argument::is()`.
     *
     * @return array{Level, 'never', Type}|null
     */
    public static function value(
        NodeAnalysisContext $context,
        ?string $exact,
        ?Type $value,
        Type $declared,
        ?Type $native,
    ): ?array {
        $taken = $exact === null ? $declared : RevealedType::matching($declared, $exact, $context->codebase);
        $nativeTaken =
            $native === null || $exact === null ? $native : RevealedType::matching($native, $exact, $context->codebase);
        if ($value === null || $taken === null || UncheckedValue::at($value)) {
            return null;
        }

        // `===` is false between an int and a float.
        [$overlap, $nativeOverlap] = $context->types->compareMultiple([
            TypeComparison::canBeIdentical($value, $taken),
            TypeComparison::canBeIdentical($value, $nativeTaken ?? $taken),
        ]);
        $overlap = $overlap && ($exact !== null || !self::numbersApart($value, $taken));
        $nativeOverlap = $nativeOverlap && ($exact !== null || !self::numbersApart($value, $nativeTaken ?? $taken));
        if ($overlap) {
            return null;
        }

        return [$native !== null && !$nativeOverlap ? Level::Error : Level::Warning, 'never', $value];
    }

    /**
     * The issue for the callback of `Argument::that()` when its first
     * parameter can't take any argument of the method's parameter, or null.
     *
     * Prophecy calls the callback from a file without strict types, so PHP
     * converts a scalar argument to the callback's scalar parameter type. A
     * callback that takes only some of the arguments, such as a subclass of
     * the parameter's class, isn't reported. Tests write it on purpose.
     *
     * @param string $class The class that declares the method.
     *
     * @return array{Level, 'callback', Type}|null
     */
    public static function callback(
        NodeAnalysisContext $context,
        string $class,
        ?Type $value,
        Type $declared,
        ?Type $native,
    ): ?array {
        $atomics = $value->atomicTypes ?? [];
        $callback = count($atomics) === 1 ? $atomics[0] : null;
        $taken = $callback instanceof CallableType ? $callback->signature->parameters[0]->type ?? null : null;
        $converted = $taken === null ? null : RevealedType::converted($taken, $class);
        if ($taken === null || $converted === null || UncheckedValue::at($taken)) {
            return null;
        }

        [$overlap, $nativeOverlap] = $context->types->compareMultiple([
            TypeComparison::canBeIdentical($declared, $converted),
            TypeComparison::canBeIdentical($native ?? $declared, $converted),
        ]);
        if ($overlap) {
            return null;
        }

        return [$native !== null && !$nativeOverlap ? Level::Error : Level::Warning, 'callback', $taken];
    }

    /**
     * Whether one type only holds ints and the other only floats.
     */
    private static function numbersApart(Type $left, Type $right): bool
    {
        $left = self::number($left);
        $right = self::number($right);

        return $left !== null && $right !== null && $left !== $right;
    }

    /**
     * `int` or `float` when every member of the type is that kind of number,
     * or null.
     *
     * @return 'int'|'float'|null
     */
    private static function number(Type $type): ?string
    {
        $kinds = [];
        foreach ($type->atomicTypes as $atomic) {
            $kinds[] = match (true) {
                $atomic instanceof ScalarType && $atomic->kind === ScalarTypeKind::Integer => 'int',
                $atomic instanceof ScalarType && $atomic->kind === ScalarTypeKind::Float => 'float',
                default => null,
            };
        }

        $kinds = array_unique($kinds);

        return count($kinds) === 1 ? $kinds[0] : null;
    }
}
