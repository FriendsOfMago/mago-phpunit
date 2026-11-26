<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;

use function count;

/**
 * Finds the values returned by a double for the rows of a value map.
 *
 * `ReturnValueMap` takes the rows whose number of values, without the last
 * one, is the number of arguments of the call. It returns the last value of
 * the first row whose other values are the arguments, or null when no row
 * matches. A strict map throws instead of returning null. PHPUnit passes
 * the call's arguments with the defaults filled in, so a call has an
 * argument for each parameter, and more when the call passes more.
 *
 * `willReturnMap()` fills in a short row with the parameters' defaults, and
 * cuts a long row to the number of parameters, when the method has a
 * parameter with a default and the row doesn't have one value per
 * parameter. PHPUnit 10.5 treats a null value in a row as a missing one, which
 * isn't followed here. For a variadic method, every row is taken as one that can
 * match.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class ValueMapRows
{
    public const NO_ROW = 'None of the rows has one value for each parameter of the method plus the returned value.';

    public const EXTRA_ROW = 'The row has more values than the method has parameters, so the double only returns its value for a call that passes more arguments.';

    private function __construct() {}

    /**
     * The values returned for the map's rows, or null when the rows aren't known.
     *
     * The rows are read from the type of the map. A row whose values aren't
     * all known is left out, and then no `null` is reported for the map.
     *
     * @param bool $filled Whether PHPUnit fills in the rows, as for `willReturnMap()`.
     * @param bool $strict Whether the map throws when no row matches.
     *
     * @return list<ReturnedValue>|null
     *
     * @mago-expect lint:no-boolean-flag-parameter
     */
    public static function values(?Type $map, ConfiguredMethod $method, bool $filled, bool $strict): ?array
    {
        $rows = self::rows($map);
        if ($rows === null) {
            return null;
        }

        [$known, $complete] = $rows;
        [$count, $defaults, $variadic] = self::parameters($method);
        $values = [];
        $matching = false;
        $extra = false;
        foreach ($known as [$size, $returned]) {
            $size =
                $filled && !$variadic && $size !== $count && $defaults !== []
                    ? self::filledSize($size, $count, $defaults)
                    : $size;
            if ($size === $count || $variadic && $size > $count) {
                $matching = true;
                $values[] = new ReturnedValue($returned, fromStub: true);
                continue;
            }

            if ($size > $count) {
                $extra = true;
                $values[] = new ReturnedValue(
                    $returned,
                    fromStub: true,
                    reporting: ReturnedValue::AS_WARNING,
                    reason: self::EXTRA_ROW,
                );
            }
        }

        if (!$matching && $complete && !$strict && !$variadic) {
            $values[] = new ReturnedValue(
                Type::null(),
                fromStub: true,
                reporting: $extra ? ReturnedValue::AS_WARNING : ReturnedValue::ANY,
                reason: self::NO_ROW,
            );
        }

        return $values;
    }

    /**
     * The rows with a known number of arguments and returned type, and
     * whether all rows are known.
     *
     * @return array{list<array{int, Type}>, bool}|null
     */
    private static function rows(?Type $map): ?array
    {
        $atomics = $map->atomicTypes ?? [];
        $atomic = count($atomics) === 1 ? $atomics[0] : null;
        $types = match (true) {
            $atomic instanceof ListType => self::listTypes($atomic),
            $atomic instanceof KeyedArrayType => self::keyedTypes($atomic),
            default => null,
        };
        if ($types === null) {
            return null;
        }

        [$rowTypes, $complete] = $types;
        $rows = [];
        foreach ($rowTypes as $rowType) {
            $row = self::row($rowType);
            if ($row === null) {
                $complete = false;
                continue;
            }

            $rows[] = $row;
        }

        return [$rows, $complete];
    }

    /**
     * The number of arguments in a row and the type of its last value, or
     * null when the row isn't a list with a known size.
     *
     * @return array{int, Type}|null
     */
    private static function row(Type $row): ?array
    {
        $atomics = $row->atomicTypes;
        $atomic = count($atomics) === 1 ? $atomics[0] : null;
        $types = match (true) {
            $atomic instanceof ListType => self::listTypes($atomic),
            $atomic instanceof KeyedArrayType => self::keyedTypes($atomic),
            default => null,
        };
        if ($types === null || !$types[1] || $types[0] === []) {
            return null;
        }

        $values = $types[0];

        return [count($values) - 1, $values[count($values) - 1]];
    }

    /**
     * The types of a list's elements, in order, and whether the list has
     * no other elements.
     *
     * @return array{list<Type>, bool}|null
     */
    private static function listTypes(ListType $list): ?array
    {
        $types = [];
        foreach ($list->knownElements ?? [] as $position => $element) {
            if ($element->optional || $element->index !== $position) {
                return null;
            }

            $types[] = $element->type;
        }

        return [$types, self::isNever($list->elementType) && $list->knownCount === count($types)];
    }

    /**
     * The types of a keyed array's values, when its keys are `0`, `1` and so
     * on, and whether the array has no other values.
     *
     * @return array{list<Type>, bool}|null
     */
    private static function keyedTypes(KeyedArrayType $array): ?array
    {
        $types = [];
        foreach ($array->knownItems ?? [] as $position => $item) {
            if ($item->optional || $item->key->kind !== ArrayKeyKind::Integer || $item->key->value !== $position) {
                return null;
            }

            $types[] = $item->type;
        }

        return [$types, $array->valueType === null];
    }

    /**
     * The number of parameters without a variadic one, the positions of the
     * parameters with a default, and whether the method is variadic.
     *
     * @return array{int, array<int, true>, bool}
     */
    private static function parameters(ConfiguredMethod $method): array
    {
        $count = 0;
        $defaults = [];
        $variadic = false;
        foreach ($method->metadata->parameters as $position => $parameter) {
            if ($parameter->flags->contains(MetadataFlags::VARIADIC)) {
                $variadic = true;
                continue;
            }

            $count++;
            if ($parameter->flags->contains(MetadataFlags::HAS_DEFAULT)) {
                $defaults[$position] = true;
            }
        }

        return [$count, $defaults, $variadic];
    }

    /**
     * The number of arguments in a row filled in by `willReturnMap()`.
     *
     * A long row is cut to one value per parameter. A short row gets the
     * default of each missing parameter that has one, and stays short when
     * one doesn't.
     *
     * @param array<int, true> $defaults
     */
    private static function filledSize(int $size, int $count, array $defaults): int
    {
        $filled = 0;
        for ($position = 0; $position < $count; $position++) {
            $filled += $position < $size || ($defaults[$position] ?? false) ? 1 : 0;
        }

        return $filled;
    }

    /**
     * Whether the type is `never`, as for the elements of a list without unknown elements.
     */
    private static function isNever(Type $type): bool
    {
        $atomics = $type->atomicTypes;

        return (
            count($atomics) === 1
            && $atomics[0] instanceof SimpleAtomicType
            && $atomics[0]->kind === SimpleAtomicTypeKind::Never
        );
    }
}
