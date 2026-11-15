<?php

declare(strict_types=1);

namespace MagoPHPUnit\Tests\Tools;

use function array_key_exists;
use function array_slice;
use function is_array;
use function is_int;
use function is_string;

/**
 * Narrows a `mixed` value, such as decoded JSON, to the expected type.
 *
 * @internal
 */
final class Shape
{
    private function __construct() {}

    /**
     * The value if it's a string, or null.
     */
    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * The value if it's an int, or null.
     */
    public static function int(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /**
     * The value if it's an array, or null.
     *
     * @return array<array-key, mixed>|null
     */
    private static function array(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    /**
     * The string at the path of keys through nested arrays, or null.
     *
     * @param non-empty-list<string> $path
     */
    public static function stringAt(mixed $value, array $path): ?string
    {
        return self::string(self::at($value, $path));
    }

    /**
     * The array at the path of keys through nested arrays, or an empty array.
     *
     * @param non-empty-list<string> $path
     *
     * @return array<array-key, mixed>
     */
    public static function arrayAt(mixed $value, array $path): array
    {
        return self::array(self::at($value, $path)) ?? [];
    }

    /**
     * The value at the path of keys through nested arrays, or null when a key is missing.
     *
     * @param list<string> $path
     */
    private static function at(mixed $value, array $path): mixed
    {
        if ($path === []) {
            return $value;
        }

        if (!is_array($value) || !array_key_exists($path[0], $value)) {
            return null;
        }

        return self::at($value[$path[0]], array_slice($path, offset: 1));
    }
}
