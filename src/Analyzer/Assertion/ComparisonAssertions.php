<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Assertion;

use Mago\Sdk\Analyzer\Assertion\Assertion;
use Mago\Sdk\Analyzer\Assertion\IntegerAssertion;
use Mago\Sdk\Analyzer\Assertion\IntegerAssertionKind;
use Mago\Sdk\Analyzer\Assertion\TypeAssertion;
use Mago\Sdk\Analyzer\Assertion\TypeAssertionKind;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\EnumType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use MagoPHPUnit\Internal\Type\Types;

use function array_intersect_key;
use function array_slice;
use function count;

/**
 * Builds the narrowing for `assertNotSame()`, the bound assertions and `assertContains()`.
 *
 * @internal
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class ComparisonAssertions
{
    private function __construct() {}

    /**
     * Returns the not-identical assertion for `assertNotSame()` with a single literal value.
     *
     * Not being identical to some `int` doesn't remove anything from a type,
     * so only a literal null, bool, int, string or enum case is used.
     */
    public static function notSame(Invocation $invocation): ?Assertion
    {
        $expected = $invocation->getArgument(0, 'expected')?->type;

        return $expected !== null && self::literalKey($expected) !== null
            ? new TypeAssertion(TypeAssertionKind::IsNotIdentical, $expected)
            : null;
    }

    /**
     * Returns the bound assertion for `assertGreaterThan()` and the like with an integer literal bound.
     *
     * PHPUnit 10 names the bound `$expected`. PHPUnit 11 and later name it
     * `$minimum` or `$maximum`.
     */
    public static function bound(Invocation $invocation, IntegerAssertionKind $kind, string $name): ?Assertion
    {
        $bound = $invocation->getArgument(0, $name, 'expected')?->type?->getLiteralInt();

        return $bound === null ? null : new IntegerAssertion($kind, $bound);
    }

    /**
     * Returns the in-array assertion for the needle of `assertContains()`.
     *
     * `assertContains()` compares strictly. The `ContainsEquals` variants
     * compare loosely, so `'1'` passes for `[1]`, and they aren't narrowed.
     */
    public static function contains(Invocation $invocation): ?Assertion
    {
        $values = self::values($invocation);
        if ($values === null || $values === []) {
            return null;
        }

        $union = count($values) === 1
            ? $values[0]
            : Type::union($values[0], $values[1], ...array_slice($values, offset: 2));

        return new TypeAssertion(TypeAssertionKind::InArray, $union);
    }

    /**
     * Returns one not-identical assertion for each value that the haystack of `assertNotContains()` is sure to hold.
     *
     * Mago doesn't narrow a value with a not-in-array assertion, so each
     * value is removed on its own. Only a known entry that isn't optional and
     * has one literal type can be removed: a list typed `list<'a'>` may be
     * empty, and `array{0: 'a', 1?: 'b'}` may not have `'b'`. For a union of
     * array types, the value must be in each of them.
     *
     * @return list<Assertion>
     */
    public static function notContains(Invocation $invocation): array
    {
        $haystack = $invocation->getArgument(1, 'haystack')?->type;
        $present = null;
        foreach ($haystack->atomicTypes ?? [] as $atomic) {
            $entries = match (true) {
                $atomic instanceof ListType => $atomic->knownElements ?? [],
                $atomic instanceof KeyedArrayType => $atomic->knownItems ?? [],
                default => null,
            };
            if ($entries === null) {
                return [];
            }

            $values = [];
            foreach ($entries as $entry) {
                $key = $entry->optional ? null : self::literalKey($entry->type);
                if ($key === null) {
                    continue;
                }

                $values[$key] = $entry->type;
            }

            $present = $present === null ? $values : array_intersect_key($present, $values);
        }

        $assertions = [];
        foreach ($present ?? [] as $value) {
            $assertions[] = new TypeAssertion(TypeAssertionKind::IsNotIdentical, $value);
        }

        return $assertions;
    }

    /**
     * The types of the values in the haystack, or null when it may be something other than an array.
     *
     * A `Traversable` haystack doesn't have its values in its type here. An
     * element type of `never`, as in a list written out in full, is left out.
     *
     * @return list<Type>|null
     */
    private static function values(Invocation $invocation): ?array
    {
        $haystack = $invocation->getArgument(1, 'haystack')?->type;
        if ($haystack === null) {
            return null;
        }

        $values = [];
        foreach ($haystack->atomicTypes as $atomic) {
            if ($atomic instanceof ListType) {
                $values[] = $atomic->elementType;
                foreach ($atomic->knownElements ?? [] as $element) {
                    $values[] = $element->type;
                }

                continue;
            }

            if (!$atomic instanceof KeyedArrayType) {
                return null;
            }

            if ($atomic->valueType !== null) {
                $values[] = $atomic->valueType;
            }

            foreach ($atomic->knownItems ?? [] as $item) {
                $values[] = $item->type;
            }
        }

        $kept = [];
        foreach ($values as $value) {
            if (Types::never($value)) {
                continue;
            }

            $kept[] = $value;
        }

        return $kept;
    }

    /**
     * A key that is different for each literal value, or null when the type isn't one literal.
     */
    private static function literalKey(Type $type): ?string
    {
        if (count($type->atomicTypes) !== 1) {
            return null;
        }

        // The type's description can't be used. It is `string` for every literal string.
        $atomic = $type->atomicTypes[0];
        $bool = $type->getLiteralBool();
        $int = $type->getLiteralInt();
        $string = $type->getLiteralClassString() === null ? $type->getLiteralString() : null;

        return match (true) {
            $atomic instanceof SimpleAtomicType && $atomic->kind === SimpleAtomicTypeKind::Null => 'null',
            $atomic instanceof EnumType && $atomic->case !== null => "enum:{$atomic->name}::{$atomic->case}",
            $bool !== null => $bool ? 'true' : 'false',
            $int !== null => "int:{$int}",
            $string !== null => "string:{$string}",
            default => null,
        };
    }
}
