<?php

declare(strict_types=1);

namespace MagoPHPUnit\Internal\Type;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\AtomicType;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\ClassLikeStringType;
use Mago\Sdk\Analyzer\Type\IterableType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListElement;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ObjectProperty;
use Mago\Sdk\Analyzer\Type\ObjectShapeType;
use Mago\Sdk\Analyzer\Type\ScalarType;

use function array_map;

/**
 * Finds generic objects and callable signatures in a type.
 *
 * Mago compares the type arguments of a generic object as invariant, and
 * infers them from the constructor arguments. A `new Box(1)` is a
 * `Box<int>` and doesn't fit a `Box<int|string>`, even though PHP runs it
 * the same. A closure without declared types doesn't fit a callable
 * signature either.
 *
 * @internal
 */
final class GenericParts
{
    private function __construct() {}

    /**
     * Whether the type has a generic object or a callable signature, also
     * inside an array, a list, an iterable, an intersection, an object shape
     * or a class string.
     */
    public static function in(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            if (self::atomic($atomic)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the atomic type is or holds a generic object or a callable signature.
     */
    private static function atomic(AtomicType $atomic): bool
    {
        return match (true) {
            $atomic instanceof NamedObjectType => ($atomic->parameters ?? []) !== []
                || self::any(array_map(Type::fromAtomic(...), $atomic->intersections ?? [])),
            $atomic instanceof CallableType => $atomic->signature !== null,
            $atomic instanceof ListType => self::any([
                $atomic->elementType,
                ...array_map(static fn(ListElement $element): Type => $element->type, $atomic->knownElements ?? []),
            ]),
            $atomic instanceof KeyedArrayType => self::any([
                ...($atomic->keyType === null ? [] : [$atomic->keyType]),
                ...($atomic->valueType === null ? [] : [$atomic->valueType]),
                ...array_map(static fn(ArrayItem $item): Type => $item->type, $atomic->knownItems ?? []),
            ]),
            $atomic instanceof IterableType => self::any([$atomic->keyType, $atomic->valueType]),
            $atomic instanceof ObjectShapeType => self::any(array_map(
                static fn(ObjectProperty $property): Type => $property->type,
                $atomic->properties,
            )),
            $atomic instanceof ScalarType && $atomic->refinement instanceof ClassLikeStringType
                => $atomic->refinement->constraint !== null && self::atomic($atomic->refinement->constraint),
            default => false,
        };
    }

    /**
     * Whether any of the types has a generic object or a callable signature.
     *
     * @param list<Type> $types
     */
    private static function any(array $types): bool
    {
        foreach ($types as $type) {
            if (self::in($type)) {
                return true;
            }
        }

        return false;
    }
}
