<?php

declare(strict_types=1);

namespace MagoPHPUnit\Internal\Type;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AliasType;
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\AtomicType;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\ClassLikeStringType;
use Mago\Sdk\Analyzer\Type\ConditionalType;
use Mago\Sdk\Analyzer\Type\DerivedType;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\IterableType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListElement;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ObjectShapeType;
use Mago\Sdk\Analyzer\Type\ReferenceType;
use Mago\Sdk\Analyzer\Type\ScalarType;

use function array_map;

/**
 * Checks whether a declared type is known without the call it's used in.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final class SettledType
{
    private function __construct() {}

    /**
     * Whether the type doesn't depend on a template or on a type computed in the call.
     */
    public static function is(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            if (!self::settledAtomic($atomic)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the atomic type doesn't depend on a template or on a type computed in the call.
     */
    private static function settledAtomic(AtomicType $atomic): bool
    {
        return match (true) {
            $atomic instanceof GenericParameterType,
            $atomic instanceof ConditionalType,
            $atomic instanceof DerivedType,
            $atomic instanceof AliasType,
            $atomic instanceof ReferenceType,
                => false,
            // A `class-string<T>` is a scalar refined by the class string type.
            $atomic instanceof ScalarType => !$atomic->refinement instanceof ClassLikeStringType
                || $atomic->refinement->parameterName === null
                && ($atomic->refinement->constraint === null || self::settledAtomic($atomic->refinement->constraint)),
            $atomic instanceof NamedObjectType => self::allSettled([
                ...($atomic->parameters ?? []),
                ...array_map(Type::fromAtomic(...), $atomic->intersections ?? []),
            ]),
            $atomic instanceof ListType => self::allSettled([
                $atomic->elementType,
                ...array_map(static fn(ListElement $element): Type => $element->type, $atomic->knownElements ?? []),
            ]),
            $atomic instanceof KeyedArrayType => self::allSettled([
                ...($atomic->keyType === null ? [] : [$atomic->keyType]),
                ...($atomic->valueType === null ? [] : [$atomic->valueType]),
                ...array_map(static fn(ArrayItem $item): Type => $item->type, $atomic->knownItems ?? []),
            ]),
            $atomic instanceof IterableType => self::allSettled([$atomic->keyType, $atomic->valueType]),
            $atomic instanceof CallableType => $atomic->signature === null
                || self::allSettled([
                    ...($atomic->signature->returnType === null ? [] : [$atomic->signature->returnType]),
                    ...array_map(
                        static fn($parameter): Type => $parameter->type ?? Type::mixed(),
                        $atomic->signature->parameters,
                    ),
                ]),
            $atomic instanceof ObjectShapeType => self::allSettled(array_map(
                static fn($property): Type => $property->type,
                $atomic->properties,
            )),
            default => true,
        };
    }

    /**
     * Whether none of the types depends on a template.
     *
     * @param list<Type> $types
     */
    private static function allSettled(array $types): bool
    {
        foreach ($types as $type) {
            if (!self::is($type)) {
                return false;
            }
        }

        return true;
    }
}
