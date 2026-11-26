<?php

declare(strict_types=1);

namespace MagoPHPUnit\Internal\Type;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\AtomicType;
use Mago\Sdk\Analyzer\Type\ClassLikeStringKind;
use Mago\Sdk\Analyzer\Type\ClassLikeStringType;
use Mago\Sdk\Analyzer\Type\IntegerType;
use Mago\Sdk\Analyzer\Type\IterableType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListElement;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ObjectProperty;
use Mago\Sdk\Analyzer\Type\ObjectShapeType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\StringCasing;
use Mago\Sdk\Analyzer\Type\StringLiteralKind;
use Mago\Sdk\Analyzer\Type\StringType;

use function array_map;
use function array_unique;
use function count;
use function implode;
use function is_bool;
use function is_int;
use function strlen;
use function strpbrk;

/**
 * Prints a type for an issue message, with the parts of arrays and generics.
 *
 * The SDK prints an array or a list atomic type as `array` or `list` alone.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final class TypeText
{
    /**
     * The longest string literal printed as it is.
     */
    private const LITERAL_LENGTH = 40;

    private function __construct() {}

    /**
     * The type as written in a docblock, such as `array<string, Foo>`.
     */
    public static function of(Type $type): string
    {
        $union = count($type->atomicTypes) > 1;

        return implode(
            '|',
            array_unique(array_map(static fn(AtomicType $atomic): string => $union
                && $atomic instanceof NamedObjectType
                && ($atomic->intersections ?? []) !== []
                    ? '(' . self::atomic($atomic) . ')'
                    : self::atomic($atomic), $type->atomicTypes)),
        );
    }

    /**
     * The atomic type as written in a docblock.
     */
    private static function atomic(AtomicType $atomic): string
    {
        return match (true) {
            $atomic instanceof ListType => self::list($atomic),
            $atomic instanceof KeyedArrayType => self::keyedArray($atomic),
            $atomic instanceof IterableType => 'iterable<'
                . self::of($atomic->keyType)
                . ', '
                . self::of($atomic->valueType)
                . '>',
            $atomic instanceof NamedObjectType => self::object($atomic),
            $atomic instanceof ObjectShapeType => self::shape($atomic),
            Closures::isClosure($atomic) => 'Closure',
            $atomic instanceof ScalarType && $atomic->kind === ScalarTypeKind::Boolean && is_bool($atomic->refinement)
                => $atomic->refinement ? 'true' : 'false',
            $atomic instanceof ScalarType && $atomic->refinement instanceof IntegerType
                => self::integer($atomic->refinement),
            $atomic instanceof ScalarType
                && $atomic->refinement instanceof StringType
                && $atomic->refinement->literalKind === StringLiteralKind::Value
                && $atomic->refinement->literalValue !== null
                => self::literal($atomic->refinement->literalValue),
            $atomic instanceof ScalarType
                && $atomic->kind === ScalarTypeKind::String
                && $atomic->refinement instanceof StringType
                => self::string($atomic->refinement),
            $atomic instanceof ScalarType && $atomic->refinement instanceof ClassLikeStringType
                => self::classString($atomic->refinement),
            default => (string) $atomic,
        };
    }

    /**
     * A string type that isn't a literal, such as `non-empty-string` or
     * `lowercase-string`.
     */
    private static function string(StringType $refinement): string
    {
        if ($refinement->callable || $refinement->numeric) {
            return $refinement->callable ? 'callable-string' : 'numeric-string';
        }

        $empty = match (true) {
            $refinement->truthy => 'non-falsy-',
            $refinement->nonEmpty => 'non-empty-',
            default => '',
        };
        $casing = match ($refinement->casing) {
            StringCasing::Lowercase => 'lowercase-',
            StringCasing::Uppercase => 'uppercase-',
            StringCasing::Unspecified => '',
        };
        $literal = $refinement->literalKind === StringLiteralKind::Unspecified ? 'literal-' : '';

        return "{$empty}{$literal}{$casing}string";
    }

    /**
     * An object shape, such as `object{name: string, age?: int}`.
     */
    private static function shape(ObjectShapeType $atomic): string
    {
        $properties = array_map(
            static fn(ObjectProperty $property): string => (
                $property->name . ($property->optional ? '?' : '') . ': ' . self::of($property->type)
            ),
            $atomic->properties,
        );
        if (!$atomic->sealed) {
            $properties[] = '...';
        }

        return 'object{' . implode(', ', $properties) . '}';
    }

    /**
     * A class string, such as `class-string<Foo>` or `Foo::class`.
     */
    private static function classString(ClassLikeStringType $refinement): string
    {
        $kind = $refinement->kind ?? ClassLikeStringKind::Class_;

        return match (true) {
            $refinement->literal !== null => "{$refinement->literal}::class",
            $refinement->constraint !== null => "{$kind->value}<" . self::atomic($refinement->constraint) . '>',
            default => $kind->value,
        };
    }

    /**
     * A string literal in quotes, or `string` when it's long or has a
     * backtick, a quote or a line break, which would break the message.
     */
    private static function literal(string $value): string
    {
        return strlen($value) > self::LITERAL_LENGTH || strpbrk($value, characters: "`'\\\r\n") !== false
            ? 'string'
            : "'{$value}'";
    }

    /**
     * An integer type as written in a docblock, such as `5`,
     * `positive-int` or `int<1, 10>`.
     */
    private static function integer(IntegerType $refinement): string
    {
        $literal = $refinement->getLiteralValue();
        $minimum = $refinement->minimum;
        $maximum = $refinement->maximum;

        return match (true) {
            $literal !== null => (string) $literal,
            $minimum === null && $maximum === null => 'int',
            $minimum === 1 && $maximum === null => 'positive-int',
            $minimum === 0 && $maximum === null => 'non-negative-int',
            $minimum === null && $maximum === -1 => 'negative-int',
            $minimum === null && $maximum === 0 => 'non-positive-int',
            default => 'int<' . ($minimum ?? 'min') . ', ' . ($maximum ?? 'max') . '>',
        };
    }

    /**
     * A list as `list<T>` or as a shape such as `list{int, string}`.
     */
    private static function list(ListType $atomic): string
    {
        if ($atomic->knownElements !== null) {
            return (
                'list{'
                . implode(', ', array_map(static fn(ListElement $element): string => $element->optional
                    ? "{$element->index}?: " . self::of($element->type)
                    : self::of($element->type), $atomic->knownElements))
                . '}'
            );
        }

        return ($atomic->nonEmpty ? 'non-empty-list<' : 'list<') . self::of($atomic->elementType) . '>';
    }

    /**
     * An array as `array<K, V>` or as a shape such as `array{'id': int}`.
     */
    private static function keyedArray(KeyedArrayType $atomic): string
    {
        if ($atomic->knownItems !== null) {
            return (
                'array{'
                . implode(', ', array_map(
                    static fn(ArrayItem $item): string => (
                        self::key($item) . ($item->optional ? '?: ' : ': ') . self::of($item->type)
                    ),
                    $atomic->knownItems,
                ))
                . '}'
            );
        }

        if ($atomic->keyType === null || $atomic->valueType === null) {
            return 'array';
        }

        $name = $atomic->nonEmpty ? 'non-empty-array' : 'array';

        return "{$name}<" . self::of($atomic->keyType) . ', ' . self::of($atomic->valueType) . '>';
    }

    /**
     * The key of a shape item, quoted when it's a string.
     */
    private static function key(ArrayItem $item): string
    {
        $key = $item->key;

        return match (true) {
            is_int($key->value) => (string) $key->value,
            $key->kind === ArrayKeyKind::String => "'{$key->value}'",
            default => (string) ($key->constant ?? $key->value),
        };
    }

    /**
     * A class with its type parameters and the classes it's intersected with.
     */
    private static function object(NamedObjectType $atomic): string
    {
        $text = $atomic->name;
        if (($atomic->parameters ?? []) !== []) {
            $text .= '<' . implode(', ', array_map(self::of(...), $atomic->parameters ?? [])) . '>';
        }

        foreach ($atomic->intersections ?? [] as $member) {
            $text .= '&' . self::atomic($member);
        }

        return $text;
    }
}
