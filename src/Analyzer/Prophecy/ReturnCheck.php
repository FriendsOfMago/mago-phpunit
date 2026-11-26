<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Prophecy;

use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AtomicType;
use Mago\Sdk\Analyzer\TypeComparison;
use Mago\Sdk\Reporting\Level;

use function array_map;
use function in_array;

/**
 * Compares a value returned by a double with the prophesized method's
 * return type.
 *
 * @internal
 */
final class ReturnCheck
{
    private function __construct() {}

    /**
     * The types that a returned value is compared with, or null when the
     * return type isn't known or isn't checked.
     *
     * @param string $class The class that declares the method.
     *
     * @return array{Type, Type, ?Type}|null The return type widened with
     *   prophecies, the return type, and the native return type widened with
     *   prophecies and the scalars PHP converts to it.
     */
    public static function types(FunctionLikeMetadata $method, string $class): ?array
    {
        $native = $method->declaredReturnType?->type;
        $returned = $method->returnType->type ?? $native;
        $accepted = RevealedType::returning($returned, $class);
        if ($returned === null || $accepted === null) {
            return null;
        }

        return [$accepted, $returned, RevealedType::converted($native, $class)];
    }

    /**
     * The level of the issue for the value, or null when the method can return it.
     *
     * The issue is an error when the value fails with a `TypeError`. It's a
     * warning when the value only breaks the docblock type, when PHP converts
     * it to the native type, when some part of it fits, or when it's wider
     * than the return type, such as `object` for a `Foo`.
     *
     * @param array{Type, Type, ?Type} $types The return type widened with
     *   prophecies, the return type, and the native return type widened with
     *   prophecies and the scalars PHP converts to it.
     */
    public static function level(NodeAnalysisContext $context, Type $value, array $types): ?Level
    {
        // A `mixed` value and a bare prophecy, such as one documented as
        // `@var ObjectProphecy`, aren't checked, also inside an array. Their
        // real type isn't known.
        if (UncheckedValue::in($value)) {
            return null;
        }

        [$accepted, $returned, $converted] = $types;
        $fits = $context->types->compareMultiple([
            TypeComparison::containedBy($value, $accepted),
            TypeComparison::containedBy($returned, $value),
            ...array_map(static fn(AtomicType $atomic): TypeComparison => TypeComparison::containedBy(
                Type::fromAtomic($atomic),
                $accepted,
            ), $value->atomicTypes),
            ...($converted === null ? [] : [TypeComparison::containedBy($value, $converted)]),
        ]);
        if ($fits[0]) {
            return null;
        }

        // Without a native return type, PHP doesn't check the value.
        return $converted === null || in_array(true, $fits, strict: true) ? Level::Warning : Level::Error;
    }

    /**
     * Whether some part of the native return type, with the scalars PHP
     * converts to it, fits the parameter type.
     */
    public static function overlaps(NodeAnalysisContext $context, Type $parameter, ?Type $converted): bool
    {
        if ($converted === null) {
            return false;
        }

        return in_array(
            true,
            $context->types->compareMultiple(array_map(
                static fn(AtomicType $atomic): TypeComparison => TypeComparison::containedBy(
                    Type::fromAtomic($atomic),
                    $parameter,
                ),
                $converted->atomicTypes,
            )),
            strict: true,
        );
    }
}
