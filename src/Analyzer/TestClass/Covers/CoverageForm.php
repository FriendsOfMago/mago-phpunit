<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\Covers;

/**
 * The way a coverage target is written: a `@covers` or `@uses` tag, or a covers or uses attribute.
 *
 * Each attribute case stands for the `Covers*` attribute and the matching
 * `Uses*` attribute, which have the same rules. A tag case has the tag name
 * as its value.
 *
 * @internal
 */
enum CoverageForm: string
{
    /**
     * A `@covers` tag, mapped like `Class`, `Class::name` or `name`.
     */
    case Annotation = 'covers';

    /**
     * A `@uses` tag, mapped like a `@covers` tag. An interface is accepted.
     */
    case UsesAnnotation = 'uses';

    case ClassAttribute = 'class';

    case TraitAttribute = 'trait';

    case MethodAttribute = 'method';

    case FunctionAttribute = 'function';

    /**
     * A `CoversNamespace` or `UsesNamespace` attribute, on PHPUnit 12 and later.
     */
    case NamespaceAttribute = 'namespace';

    /**
     * A `CoversClassesThatExtendClass` or `UsesClassesThatExtendClass` attribute, on PHPUnit 12 and later.
     */
    case ExtendsAttribute = 'extends';

    /**
     * A `CoversClassesThatImplementInterface` or `UsesClassesThatImplementInterface` attribute, on PHPUnit 12
     * and later.
     */
    case ImplementsAttribute = 'implements';

    /**
     * Whether the target is written in a tag.
     */
    public function isAnnotation(): bool
    {
        return $this === self::Annotation || $this === self::UsesAnnotation;
    }
}
