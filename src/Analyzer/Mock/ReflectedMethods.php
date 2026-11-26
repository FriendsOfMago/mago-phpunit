<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MemberIdentifier;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use ReflectionClass;

use function array_filter;
use function class_exists;
use function in_array;
use function interface_exists;
use function strtolower;
use function trait_exists;

/**
 * Finds methods by the rules of `ReflectionClass::hasMethod()`.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class ReflectedMethods
{
    private function __construct() {}

    /**
     * The lowercased names that aren't found by reflection on the class, or
     * null when the class or its hierarchy isn't fully known.
     *
     * A name is looked up on the class first. A name that isn't found there,
     * or only as an `@method` tag, is looked up on the parents, traits and
     * interfaces, where a private method or the real method behind the tag
     * may be declared. Mago's definitions of PHP's own classes miss a few
     * methods, such as `RegexIterator::getMode()`, so a name that's still
     * missing is looked up with PHP's reflection on the class or its
     * ancestors that are PHP's own.
     *
     * The result is keyed by the names. PHP turns a numeric key such as
     * `'123'` into an integer, and a lookup with the string still finds it.
     *
     * @param list<string> $names
     *
     * @return array<array-key, string>|null
     */
    public static function missing(NodeAnalysisContext $context, string $class, array $names): ?array
    {
        $codebase = $context->codebase;
        $lookups = [];
        foreach ($names as $name) {
            $lookups = $name === '' ? $lookups : [...$lookups, new MemberIdentifier($class, $name)];
        }

        $missing = [];
        foreach ($lookups === [] ? [] : $codebase->getMultipleDeclaringMethods($lookups) as $index => $method) {
            if (self::isReal($method)) {
                continue;
            }

            $lower = strtolower($lookups[$index]->member);
            $missing[$lower] = $lower;
        }

        $hasEmpty = in_array('', $names, strict: true);
        if ($missing === [] && !$hasEmpty) {
            return [];
        }

        $metadata = $codebase->getClassLike($class);
        if ($metadata === null || $metadata->hasIncompleteHierarchy()) {
            return null;
        }

        $ancestors = [...$metadata->parentClasses, ...$metadata->usedTraits, ...$metadata->parentInterfaces];
        $lookups = [];
        foreach ($ancestors === [] ? [] : $missing as $name) {
            foreach ($ancestors as $ancestor) {
                $lookups[] = new MemberIdentifier($ancestor, $name);
            }
        }

        foreach ($lookups === [] ? [] : $codebase->getMultipleDeclaringMethods($lookups) as $index => $method) {
            if (!self::isReal($method)) {
                continue;
            }

            unset($missing[strtolower($lookups[$index]->member)]);
        }

        return $missing === [] ? [] : self::withoutBuiltin($context, [$class, ...$ancestors], $missing);
    }

    /**
     * The missing names without those that PHP's reflection finds on a
     * class of PHP itself among the classes, or null when such a class isn't
     * loaded in this PHP.
     *
     * @param list<string> $classes
     * @param array<array-key, string> $missing
     *
     * @return array<array-key, string>|null
     */
    private static function withoutBuiltin(NodeAnalysisContext $context, array $classes, array $missing): ?array
    {
        foreach ($context->codebase->getMultipleClassLikes($classes) as $metadata) {
            if ($metadata === null || !$metadata->flags->contains(MetadataFlags::BUILTIN)) {
                continue;
            }

            $name = $metadata->originalName;
            $exists =
                class_exists($name, autoload: false)
                || interface_exists($name, autoload: false)
                || trait_exists($name, autoload: false);
            if (!$exists) {
                return null;
            }

            $reflection = new ReflectionClass($name);
            $missing = array_filter($missing, static fn(string $method): bool => !$reflection->hasMethod($method));
        }

        return $missing;
    }

    /**
     * Whether the method is declared in code, rather than by an `@method` tag.
     */
    private static function isReal(?FunctionLikeMetadata $method): bool
    {
        return $method !== null && !$method->flags->contains(MetadataFlags::MAGIC_METHOD);
    }
}
