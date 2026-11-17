<?php

declare(strict_types=1);

namespace MagoPHPUnit\Internal\Type;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Type\AtomicType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\StringCasing;
use Mago\Sdk\Analyzer\Type\StringLiteralKind;
use Mago\Sdk\Analyzer\Type\StringType;

use function array_map;
use function in_array;
use function strtolower;

/**
 * Widens a type with the values that Prophecy's loose comparison or PHP's
 * scalar conversion turn into it.
 *
 * @internal
 */
final class LooseScalar
{
    private function __construct() {}

    /**
     * The scalar type of an argument and the values that Prophecy matches
     * against it by their text.
     *
     * Prophecy compares a number, a numeric string and a `Stringable` object
     * by their text, so `1` matches the argument `'1'`. Two numbers are
     * compared with `==`, so `5.0` matches the argument `5`.
     *
     * @return non-empty-list<AtomicType>
     */
    public static function matched(ScalarType $atomic): array
    {
        return match ($atomic->kind) {
            ScalarTypeKind::String => [$atomic, self::integer(), self::float(), self::stringable()],
            ScalarTypeKind::Integer, ScalarTypeKind::Float => [
                $atomic,
                self::numericString(),
                self::integer(),
                self::float(),
            ],
            default => [$atomic],
        };
    }

    /**
     * The values that Prophecy matches against an argument of the class,
     * other than its objects.
     *
     * An object with `__toString()` matches a string or a number with its
     * text. Two `DateTimeInterface` objects of any classes match when they
     * hold the same time.
     *
     * @return list<AtomicType>
     */
    public static function matchedObject(Codebase $codebase, string $class): array
    {
        if ($class === '' || !$codebase->classLikeExists($class)) {
            return [];
        }

        $ancestors = array_map(strtolower(...), [$class, ...$codebase->getClassAncestors($class)]);

        return [
            ...(
                $codebase->getDeclaringMethod($class, method: '__toString') !== null
                    ? [new ScalarType(ScalarTypeKind::String), self::integer(), self::float()]
                    : []
            ),
            ...(
                in_array('datetimeinterface', $ancestors, strict: true)
                    ? [new NamedObjectType('DateTimeInterface', null, null, false, false, null, false)]
                    : []
            ),
        ];
    }

    /**
     * The native scalar return type and the values that PHP converts to it
     * outside strict mode.
     *
     * A number, a numeric string, a bool and a `Stringable` object are
     * converted to a `string`. A number, a numeric string and a bool are
     * converted to an `int` or a `float`. Any scalar is converted to a
     * `bool`. Nothing is converted to a `true` or a `false` type.
     *
     * @return non-empty-list<AtomicType>
     */
    public static function converted(ScalarType $atomic): array
    {
        return match ($atomic->kind) {
            ScalarTypeKind::String => [$atomic, self::integer(), self::float(), self::stringable(), self::boolean()],
            ScalarTypeKind::Integer, ScalarTypeKind::Float => [
                $atomic,
                self::numericString(),
                self::integer(),
                self::float(),
                self::boolean(),
            ],
            ScalarTypeKind::Boolean => $atomic->refinement === null
                ? [$atomic, self::integer(), self::float(), new ScalarType(ScalarTypeKind::String)]
                : [$atomic],
            default => [$atomic],
        };
    }

    /**
     * The type `int`.
     */
    private static function integer(): ScalarType
    {
        return new ScalarType(ScalarTypeKind::Integer);
    }

    /**
     * The type `float`.
     */
    private static function float(): ScalarType
    {
        return new ScalarType(ScalarTypeKind::Float);
    }

    /**
     * The type `bool`.
     */
    private static function boolean(): ScalarType
    {
        return new ScalarType(ScalarTypeKind::Boolean);
    }

    /**
     * The type `Stringable`.
     */
    private static function stringable(): NamedObjectType
    {
        return new NamedObjectType('Stringable', null, null, false, false, null, false);
    }

    /**
     * The type `numeric-string`.
     */
    private static function numericString(): ScalarType
    {
        return new ScalarType(
            ScalarTypeKind::String,
            new StringType(StringLiteralKind::General, null, true, false, true, false, StringCasing::Unspecified),
        );
    }
}
