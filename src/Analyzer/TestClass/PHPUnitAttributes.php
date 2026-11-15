<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass;

use Mago\Sdk\Analyzer\Metadata\AttributeMetadata;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MethodMetadataProjection;
use MagoPHPUnit\Internal\Type\Types;

use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

/**
 * Finds PHPUnit's attributes on a class or method, and reads their string arguments.
 *
 * @internal
 */
final class PHPUnitAttributes
{
    /**
     * The namespace of PHPUnit's attributes.
     */
    public const NAMESPACE = 'PHPUnit\Framework\Attributes\\';

    private function __construct() {}

    /**
     * The PHPUnit attributes of a class or method with the given short name.
     *
     * The name is relative to `PHPUnit\Framework\Attributes`.
     *
     * @return list<AttributeMetadata>
     */
    public static function of(MethodMetadataProjection|ClassLikeMetadata $member, string $name): array
    {
        $wanted = strtolower($name);
        $found = [];
        foreach ($member->attributes ?? [] as $attribute) {
            if (self::shortName($attribute->name) !== $wanted) {
                continue;
            }

            $found[] = $attribute;
        }

        return $found;
    }

    /**
     * The lowercased name of a PHPUnit attribute without its namespace, or null for another attribute.
     */
    public static function shortName(string $name): ?string
    {
        $lowercased = strtolower($name);
        $namespace = strtolower(self::NAMESPACE);

        return str_starts_with($lowercased, $namespace) ? substr($lowercased, strlen($namespace)) : null;
    }

    /**
     * The literal string or class string of an attribute argument, or null for an empty string.
     */
    public static function string(AttributeMetadata $attribute, int $index, string $name): ?string
    {
        // The argument is found by parameter name first. Its position is used otherwise. PHPUnit
        // keeps a leading backslash in a data set name or a coverage target. The value is kept as
        // written here.
        $value = Types::literalName($attribute->getArgument($index, $name)?->valueType);

        return $value === '' ? null : $value;
    }
}
