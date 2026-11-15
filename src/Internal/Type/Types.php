<?php

declare(strict_types=1);

namespace MagoPHPUnit\Internal\Type;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AnyObjectType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;

use function strlen;
use function strpos;
use function substr;

/**
 * Helpers for inspecting a declared type.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class Types
{
    private function __construct() {}

    /**
     * The literal class string or literal string of the type, or an empty string when it isn't one.
     */
    public static function literalName(?Type $type): string
    {
        return $type?->getLiteralClassString() ?? $type?->getLiteralString() ?? '';
    }

    /**
     * Splits a type printed by Mago at each `|` outside the given brackets.
     *
     * @param string $brackets The opening and closing brackets in pairs, such as `<>{}()`.
     * @return list<string>
     */
    public static function unionMembers(string $type, string $brackets): array
    {
        $members = [];
        $depth = 0;
        $start = 0;
        $length = strlen($type);
        for ($i = 0; $i < $length; $i++) {
            $bracket = strpos($brackets, $type[$i]);
            $depth += match (true) {
                $bracket === false => 0,
                ($bracket % 2) === 0 => 1,
                default => -1,
            };
            if ($depth === 0 && $type[$i] === '|') {
                $members[] = substr($type, $start, $i - $start);
                $start = $i + 1;
            }
        }

        $members[] = substr($type, $start);

        return $members;
    }

    /**
     * Every class-like name in the type, intersection members included.
     *
     * @return list<non-empty-string>
     */
    public static function names(?Type $type): array
    {
        $names = [];
        foreach ($type === null ? [] : $type->atomicTypes as $atomic) {
            if (!$atomic instanceof NamedObjectType) {
                continue;
            }

            $names = [...$names, ...self::namesOf($atomic)];
        }

        return $names;
    }

    /**
     * Whether every member of the type is an object, so it can't be null,
     * mixed or a scalar.
     */
    public static function objectsOnly(?Type $type): bool
    {
        if ($type === null) {
            return false;
        }

        foreach ($type->atomicTypes as $atomic) {
            if (!$atomic instanceof NamedObjectType && !$atomic instanceof AnyObjectType) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether every part of the type is `never`.
     */
    public static function never(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            if (!$atomic instanceof SimpleAtomicType || $atomic->kind !== SimpleAtomicTypeKind::Never) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the type is an array that is always empty, such as the type of `[]`.
     */
    public static function emptyArray(?Type $type): bool
    {
        if ($type === null) {
            return false;
        }

        foreach ($type->atomicTypes as $atomic) {
            $empty = match (true) {
                $atomic instanceof ListType => !$atomic->nonEmpty
                    && ($atomic->knownElements ?? []) === []
                    && self::never($atomic->elementType),
                $atomic instanceof KeyedArrayType => !$atomic->nonEmpty
                    && ($atomic->knownItems ?? []) === []
                    && ($atomic->valueType === null || self::never($atomic->valueType)),
                default => false,
            };
            if (!$empty) {
                return false;
            }
        }

        return true;
    }

    /**
     * The values of a literal string or a union of literal strings, or null
     * when any member of the type is something else.
     *
     * @return non-empty-list<string>|null
     */
    public static function literalStrings(?Type $type): ?array
    {
        $values = [];
        foreach ($type === null ? [] : $type->atomicTypes as $atomic) {
            $value = Type::fromAtomic($atomic)->getLiteralString();
            if ($value === null) {
                return null;
            }

            $values[] = $value;
        }

        return $values === [] ? null : $values;
    }

    /**
     * The name of the object type and the names of its intersection members.
     *
     * @return list<non-empty-string>
     */
    private static function namesOf(NamedObjectType $atomic): array
    {
        $names = $atomic->name === '' ? [] : [$atomic->name];
        foreach ($atomic->intersections ?? [] as $member) {
            if (!$member instanceof NamedObjectType) {
                continue;
            }

            $names = [...$names, ...self::namesOf($member)];
        }

        return $names;
    }
}
