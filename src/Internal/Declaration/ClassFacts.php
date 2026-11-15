<?php

declare(strict_types=1);

namespace MagoPHPUnit\Internal\Declaration;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MemberIdentifier;
use Mago\Sdk\Analyzer\Metadata\MethodFields;
use Mago\Sdk\Analyzer\Metadata\MethodMetadataProjection;
use Mago\Sdk\Analyzer\Metadata\PropertyMetadata;

use function strtolower;

/**
 * A class's metadata plus its own methods and properties, fetched on first use.
 *
 * @internal
 */
final class ClassFacts
{
    public const METHOD_FIELDS =
        MethodFields::NAMES
            | MethodFields::LOCATIONS
            | MethodFields::PARAMETERS
            | MethodFields::ATTRIBUTES
            | MethodFields::METHOD_DETAILS
            | MethodFields::FLAGS;

    /**
     * @var list<MethodMetadataProjection>|null
     */
    private ?array $methods = null;

    /**
     * @var list<PropertyMetadata>|null
     */
    private ?array $properties = null;

    public function __construct(
        public readonly ClassLikeMetadata $class,
        public readonly Codebase $codebase,
    ) {}

    /**
     * The class's own methods.
     *
     * Class metadata has member names only, so the methods are fetched
     * with one `findMethods()` call.
     *
     * @return list<MethodMetadataProjection>
     */
    public function methods(): array
    {
        return $this->methods ??= $this->codebase->findMethods(
            class: $this->class->name,
            fields: self::METHOD_FIELDS,
            declaredOnly: true,
        );
    }

    /**
     * The class's own method with the given name, matched without case.
     */
    public function method(string $name): ?MethodMetadataProjection
    {
        foreach ($this->methods() as $method) {
            if (strtolower($method->name ?? '') === strtolower($name)) {
                return $method;
            }
        }

        return null;
    }

    /**
     * The class's own properties, promoted ones included.
     *
     * Class metadata has member names only, so the properties are
     * fetched with one `getMultipleProperties()` call.
     *
     * @return list<PropertyMetadata>
     */
    public function properties(): array
    {
        if ($this->properties !== null) {
            return $this->properties;
        }

        $identifiers = [];
        foreach ($this->class->properties as $name) {
            $identifiers[] = new MemberIdentifier($this->class->name, $name);
        }

        $properties = [];
        foreach ($identifiers === [] ? [] : $this->codebase->getMultipleProperties($identifiers) as $property) {
            if ($property === null || !self::declares($this->class, $property)) {
                continue;
            }

            $properties[] = $property;
        }

        return $this->properties = $properties;
    }

    /**
     * Whether the property is declared in the class's own body, not by a
     * trait or a parent.
     *
     * That's the case when the property name is in the class's file, inside
     * the class span.
     */
    private static function declares(ClassLikeMetadata $class, PropertyMetadata $property): bool
    {
        $location = $property->nameLocation ?? $property->location;

        return (
            $location !== null
            && $location->file === $class->location->file
            && $class->location->span->contains($location->span)
        );
    }
}
