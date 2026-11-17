<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Prophecy;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\AtomicType;
use Mago\Sdk\Analyzer\Type\IterableType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListElement;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

use function array_map;
use function in_array;

/**
 * Finds the parts of a value whose real type isn't known: `mixed`, and a
 * prophecy documented without its class, such as `@var ObjectProphecy`.
 *
 * @internal
 */
final class UncheckedValue
{
    private function __construct() {}

    /**
     * Whether a member of the type is `mixed` or a bare prophecy, not
     * counting the members inside an array or an iterable.
     *
     * A plain `array` holds `mixed` values, but it's still an array.
     */
    public static function at(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof MixedType || self::bareProphecy($atomic)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the type has a `mixed` part or a bare prophecy, also inside an
     * array or an iterable.
     */
    public static function in(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof MixedType || self::bareProphecy($atomic)) {
                return true;
            }

            $parts = match (true) {
                $atomic instanceof ListType => [
                    $atomic->elementType,
                    ...array_map(static fn(ListElement $element): Type => $element->type, $atomic->knownElements ?? []),
                ],
                $atomic instanceof KeyedArrayType => [
                    ...($atomic->valueType === null ? [] : [$atomic->valueType]),
                    ...array_map(static fn(ArrayItem $item): Type => $item->type, $atomic->knownItems ?? []),
                ],
                $atomic instanceof IterableType => [$atomic->valueType],
                default => [],
            };
            foreach ($parts as $part) {
                if (self::in($part)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the atomic type is a prophecy without the prophesized class.
     */
    private static function bareProphecy(AtomicType $atomic): bool
    {
        return (
            $atomic instanceof NamedObjectType
            && in_array(
                $atomic->name,
                [ProphesizedMethod::OBJECT_PROPHECY, 'Prophecy\Prophecy\ProphecyInterface'],
                strict: true,
            )
            && ProphesizedMethod::classes(Type::fromAtomic($atomic)) === []
        );
    }
}
