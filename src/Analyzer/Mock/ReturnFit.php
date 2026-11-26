<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AnyObjectType;
use Mago\Sdk\Analyzer\Type\AtomicType;
use Mago\Sdk\Analyzer\Type\EnumType;
use Mago\Sdk\Analyzer\Type\IterableType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use MagoPHPUnit\Internal\Type\Closures;

use function array_filter;
use function array_key_exists;
use function count;

/**
 * Checks a value returned by a double against the method's native return
 * type.
 *
 * PHPUnit compares a `willReturn()` value with the native return type and
 * throws when it doesn't fit. Scalars aren't converted, so `1` doesn't fit a
 * `float` and `'5'` doesn't fit an `int`. An object fits a class when it's
 * an instance of that class. Any object fits an intersection type. A method
 * without a native return type takes any value.
 *
 * A value that PHPUnit doesn't check, such as one from
 * `willReturnOnConsecutiveCalls()`, is checked by PHP when the double
 * returns it. The double uses strict types, so the rules are the same,
 * except that an `int` fits a `float`. An intersection type isn't checked
 * then.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class ReturnFit
{
    public const FITS = 'fits';

    public const FAILS = 'fails';

    public const PARTIAL = 'partial';

    private const PHPUNIT = 'phpunit';

    private const PHP = 'php';

    private function __construct() {}

    /**
     * Whether PHPUnit takes the value when it's set up with `willReturn()`:
     * FITS, FAILS when no part of it fits, PARTIAL when some parts fit, or
     * null when it isn't known.
     *
     * @param bool $created Whether the value is a double created right there,
     *   such as `$this->createStub(Foo::class)`, so its class is known.
     *
     * @return self::FITS|self::FAILS|self::PARTIAL|null
     */
    public static function atSetUp(NodeAnalysisContext $context, Type $value, Type $returnType, bool $created): ?string
    {
        return self::fit($context, $value, $returnType, self::PHPUNIT, $created);
    }

    /**
     * Whether PHP takes the value when the double returns it, with the same
     * results as `atSetUp()`.
     *
     * @param bool $created Whether the value is a double created right there.
     *
     * @return self::FITS|self::FAILS|self::PARTIAL|null
     */
    public static function atReturn(NodeAnalysisContext $context, Type $value, Type $returnType, bool $created): ?string
    {
        return self::fit($context, $value, $returnType, self::PHP, $created);
    }

    /**
     * Whether PHPUnit takes any value for the return type when it's set up.
     *
     * In PHPUnit, the `null` member of a union takes any value. That's the
     * case for a union of two or more types and `null`, an intersection with
     * `null`, and `null` alone. A nullable type such as `?int` or `int|null`
     * is checked.
     */
    public static function takesAnyAtSetUp(Type $returnType): bool
    {
        $null = false;
        $others = [];
        foreach ($returnType->atomicTypes as $atomic) {
            if ($atomic instanceof SimpleAtomicType && $atomic->kind === SimpleAtomicTypeKind::Null) {
                $null = true;
                continue;
            }

            $others[] = $atomic;
        }

        $single = count($others) === 1 ? $others[0] : null;

        return $null && ($single === null || self::isIntersection($single));
    }

    /**
     * Whether the value fits the native return type, as checked by PHPUnit
     * or by PHP.
     *
     * @param self::PHPUNIT|self::PHP $checker
     * @param bool $created Whether the value is a double created right there.
     *
     * @return self::FITS|self::FAILS|self::PARTIAL|null
     */
    private static function fit(
        NodeAnalysisContext $context,
        Type $value,
        Type $returnType,
        string $checker,
        bool $created,
    ): ?string {
        $value = Closures::asObjects($value);
        $returnType = Closures::asObjects($returnType);
        $accepted = self::accepted($returnType, $checker);
        if ($accepted === null) {
            return null;
        }

        [$kinds, $classes] = $accepted;
        [$atomics, $bare] = self::withoutDoubleNames($value);

        // A double of an unknown class only fails for certain when the return type doesn't take any object.
        $results = [];
        if ($bare) {
            if ($classes !== [] || array_key_exists('object', $kinds)) {
                return null;
            }

            $results[] = self::FAILS;
        }

        // PHP returns an `int` as a `float`, also with strict types.
        if ($checker === self::PHP && array_key_exists('float', $kinds)) {
            $kinds['int'] = true;
        }

        $objects = [];
        foreach ($atomics as $atomic) {
            if (!$atomic instanceof NamedObjectType && !$atomic instanceof EnumType) {
                $result = self::plainFit($atomic, $kinds);
                if ($result === null) {
                    return null;
                }

                $results[] = $result;
                continue;
            }

            if (array_key_exists('object', $kinds)) {
                $results[] = self::FITS;
                continue;
            }

            // A return type without a class, such as `int|array`, doesn't take any object.
            if ($classes === []) {
                $results[] = self::FAILS;
                continue;
            }

            $objects[] = $atomic;
        }

        $objectResults = $objects === [] ? [] : ObjectFit::of($context, $objects, $classes, $created);
        if ($objectResults === null) {
            return null;
        }

        $fits = false;
        $fails = false;
        foreach ([...$results, ...$objectResults] as $result) {
            $fits = $fits || $result !== self::FAILS;
            $fails = $fails || $result !== self::FITS;
        }

        if ($fits && $fails) {
            return self::PARTIAL;
        }

        return $fails ? self::FAILS : self::FITS;
    }

    /**
     * The parts of the value without the bare double names of a legacy
     * union, and whether a bare double name is left.
     *
     * `Foo|MockObject` is a double of `Foo`, so only `Foo` is compared. A
     * value typed `MockObject` alone can be a double of any class.
     *
     * @return array{list<AtomicType>, bool}
     */
    private static function withoutDoubleNames(Type $value): array
    {
        $atomics = [];
        $bare = false;
        $classes = false;
        foreach ($value->atomicTypes as $atomic) {
            if (
                $atomic instanceof NamedObjectType
                && !self::isIntersection($atomic)
                && Doubles::isDoubleName($atomic->name)
            ) {
                $bare = true;
                continue;
            }

            $classes = $classes || $atomic instanceof NamedObjectType;
            $atomics[] = $atomic;
        }

        return [$atomics, $bare && !$classes];
    }

    /**
     * The kinds of value accepted by the native return type, and its classes,
     * or null when it takes any value or isn't checked here.
     *
     * @param self::PHPUNIT|self::PHP $checker
     *
     * @return array{array<string, true>, list<string>}|null
     */
    private static function accepted(Type $returnType, string $checker): ?array
    {
        $kinds = [];
        $classes = [];
        foreach ($returnType->atomicTypes as $atomic) {
            $intersection = self::isIntersection($atomic);

            // PHPUnit takes any object for an intersection type. PHP's check isn't repeated here.
            if ($intersection && $checker === self::PHP) {
                return null;
            }

            if ($atomic instanceof NamedObjectType && !$intersection || $atomic instanceof EnumType) {
                $classes[] = $atomic->name;
                continue;
            }

            $kind = match (true) {
                $intersection, $atomic instanceof AnyObjectType => ['object'],
                $atomic instanceof IterableType => ['array', 'traversable'],
                default => self::kinds($atomic),
            };

            // `mixed`, `callable` and other types aren't checked.
            if ($kind === null) {
                return null;
            }

            foreach ($kind as $name) {
                $kinds[$name] = true;
            }
        }

        if (array_key_exists('traversable', $kinds)) {
            $classes[] = 'Traversable';
        }

        return [$kinds, $classes];
    }

    /**
     * Whether a part of the value that isn't an object fits the kinds, or
     * null when it isn't known.
     *
     * @param array<string, true> $kinds
     *
     * @return self::FITS|self::FAILS|self::PARTIAL|null
     */
    private static function plainFit(AtomicType $atomic, array $kinds): ?string
    {
        $needed = self::kinds($atomic);
        if ($needed === null) {
            return null;
        }

        $found = count(array_filter($needed, static fn(string $kind): bool => array_key_exists($kind, $kinds)));

        return match ($found) {
            count($needed) => self::FITS,
            0 => self::FAILS,
            // A `bool` for a `true` return type fits in part.
            default => self::PARTIAL,
        };
    }

    /**
     * The kinds of value in a part of a type that isn't an object, or null
     * when it isn't checked here.
     *
     * A `bool` has the kinds `true` and `false`.
     *
     * @return list<string>|null
     */
    private static function kinds(AtomicType $atomic): ?array
    {
        return match (true) {
            $atomic instanceof SimpleAtomicType && $atomic->kind === SimpleAtomicTypeKind::Null => ['null'],
            $atomic instanceof ScalarType => match ($atomic->kind) {
                ScalarTypeKind::Boolean => match ($atomic->refinement) {
                    true => ['true'],
                    false => ['false'],
                    default => ['true', 'false'],
                },
                ScalarTypeKind::Integer => ['int'],
                ScalarTypeKind::Float => ['float'],
                ScalarTypeKind::String, ScalarTypeKind::ClassLikeString => ['string'],
                default => null,
            },
            $atomic instanceof KeyedArrayType, $atomic instanceof ListType => ['array'],
            default => null,
        };
    }

    /**
     * Whether the part of a type is an intersection, such as `Foo&Bar`.
     */
    private static function isIntersection(AtomicType $atomic): bool
    {
        return $atomic instanceof NamedObjectType && $atomic->intersections !== null && $atomic->intersections !== [];
    }
}
