<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\DataSets;

use JsonException;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\ArrayKey;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListElement;
use Mago\Sdk\Analyzer\Type\ListType;

use function array_is_list;
use function count;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;

/**
 * Builds the type of a data set written as JSON, in `#[TestWithJson]` or `@testWith`.
 *
 * @internal
 */
final class JsonDataSets
{
    private function __construct() {}

    /**
     * The error given by `json_decode()` for the JSON, or null when it's valid.
     */
    public static function error(string $json): ?string
    {
        try {
            json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    /**
     * The type of a data set written as JSON, or null when it isn't valid JSON for an array.
     */
    public static function type(string $json): ?Type
    {
        try {
            /** @var mixed $value */
            $value = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($value) ? self::row($value) : null;
    }

    /**
     * The type of a data set, with a known type for each of its values.
     *
     * @param array<array-key, mixed> $row
     */
    private static function row(array $row): Type
    {
        $values = [];
        /** @var mixed $value */
        foreach ($row as $key => $value) {
            $values[$key] = self::value($value);
        }

        if (array_is_list($values)) {
            $elements = [];
            foreach ($values as $index => $type) {
                $elements[] = new ListElement($index, optional: false, type: $type);
            }

            return Type::fromAtomic(new ListType(Type::never(), $elements, count($elements), $elements !== []));
        }

        $items = [];
        foreach ($values as $key => $type) {
            $arrayKey = is_int($key)
                ? new ArrayKey(ArrayKeyKind::Integer, $key)
                : new ArrayKey(ArrayKeyKind::String, $key);
            $items[] = new ArrayItem($arrayKey, optional: false, type: $type);
        }

        return Type::fromAtomic(new KeyedArrayType($items, keyType: null, valueType: null, nonEmpty: true));
    }

    /**
     * The type of one decoded JSON value.
     *
     * A nested array is typed as any array, which fits every array parameter.
     */
    private static function value(mixed $value): Type
    {
        return match (true) {
            $value === null => Type::null(),
            $value === true => Type::true(),
            $value === false => Type::false(),
            is_int($value) => Type::literalInt($value),
            is_float($value) => Type::float(),
            is_string($value) => Type::literalString($value),
            is_array($value) => Type::array(Type::union(Type::int(), Type::string()), Type::mixed()),
            default => Type::mixed(),
        };
    }
}
