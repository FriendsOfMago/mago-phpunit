<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Assertion;

use Mago\Sdk\Analyzer\Argument;
use Mago\Sdk\Analyzer\Assertion\Assertion;
use Mago\Sdk\Analyzer\Assertion\TypeAssertion;
use Mago\Sdk\Analyzer\Assertion\TypeAssertionKind;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AtomicType;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\Type\StringType;

/**
 * Narrows the strings checked by PHPUnit's string assertions.
 *
 * Without strict types, PHP converts an int, a float, `true` or a
 * `Stringable` object to a string for these assertions, and the value keeps
 * its own type. Such a value isn't narrowed. Only a value typed as a string,
 * `mixed`, `null` or `false` is narrowed, since `null` and `false` never pass.
 *
 * A `mixed` value is narrowed too, which is right in a file with strict
 * types, where PHP throws for a value that isn't a string. An assertion
 * provider doesn't know whether the calling file has strict types, so in a
 * file without them, a `mixed` value that holds an int is narrowed anyway.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final class StringAssertions
{
    private function __construct() {}

    /**
     * The non-empty string assertion for a haystack, when the needle is a non-empty string.
     *
     * A string that contains, starts with or ends with a non-empty string
     * isn't empty. An empty needle matches the empty string too.
     *
     * @param array{string, string} $names The names of the needle and haystack parameters.
     */
    public static function nonEmptyHaystack(Invocation $invocation, array $names): ?Assertion
    {
        [$needle, $haystack] = $names;
        $type = self::positional($invocation->getArgument(0, $needle))?->type;

        return $type !== null && self::isNonEmptyString($type)
            ? self::nonEmpty($invocation->getArgument(1, $haystack))
            : null;
    }

    /**
     * Returns the non-empty string assertion, or null when the value isn't narrowed.
     *
     * `assertJson()` fails for an empty string. A type that is only `null`
     * or `false` isn't narrowed. Mago doesn't follow changes made through a
     * reference, so the value may be a string at runtime.
     */
    public static function nonEmpty(?Argument $argument): ?Assertion
    {
        $type = self::positional($argument)?->type;
        if ($type === null) {
            return null;
        }

        $string = false;
        foreach ($type->atomicTypes as $atomic) {
            if (!self::isString($atomic) && !self::neverPasses($atomic)) {
                return null;
            }

            $string = $string || self::isString($atomic);
        }

        return $string ? new TypeAssertion(TypeAssertionKind::IsType, Type::nonEmptyString()) : null;
    }

    /**
     * The argument, or null when it's unpacked.
     */
    private static function positional(?Argument $argument): ?Argument
    {
        return $argument?->unpacked === false ? $argument : null;
    }

    /**
     * Whether a part of a type is a string kind or `mixed`.
     */
    private static function isString(AtomicType $atomic): bool
    {
        return (
            $atomic instanceof MixedType
            || $atomic instanceof ScalarType
            && ($atomic->kind === ScalarTypeKind::String || $atomic->kind === ScalarTypeKind::ClassLikeString)
        );
    }

    /**
     * Whether a part of a type is `null` or `false`, which never passes the assertion.
     */
    private static function neverPasses(AtomicType $atomic): bool
    {
        return (
            $atomic instanceof SimpleAtomicType
            && $atomic->kind === SimpleAtomicTypeKind::Null
            || $atomic instanceof ScalarType
            && $atomic->kind === ScalarTypeKind::Boolean
            && $atomic->refinement === false
        );
    }

    /**
     * Whether every part of the type is a non-empty string, such as a non-empty literal or `Foo::class`.
     */
    private static function isNonEmptyString(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            if (!$atomic instanceof ScalarType) {
                return false;
            }

            if ($atomic->kind === ScalarTypeKind::ClassLikeString) {
                continue;
            }

            $refinement = $atomic->kind === ScalarTypeKind::String ? $atomic->refinement : null;
            if (
                !$refinement instanceof StringType
                || !$refinement->nonEmpty && ($refinement->literalValue ?? '') === ''
            ) {
                return false;
            }
        }

        return true;
    }
}
