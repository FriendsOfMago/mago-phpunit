<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\EnumType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\TypeComparison;
use MagoPHPUnit\Analyzer\Support\KnownHierarchy;
use MagoPHPUnit\Internal\Type\Types;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function in_array;
use function str_starts_with;
use function strtolower;

/**
 * Checks objects returned by a double against the classes of the method's
 * native return type.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class ObjectFit
{
    private function __construct() {}

    /**
     * Whether each object fits one of the classes, or null when one of them
     * isn't known.
     *
     * An object that isn't an instance of a class can still be one at
     * runtime, through a subclass or another interface. It fails for certain
     * in three cases. The first is a double created right there, since its
     * class is known. The second is an enum case. The third is an object of
     * a class when every returned type is a class too, and none of them
     * extends it.
     *
     * @param non-empty-list<NamedObjectType|EnumType> $objects
     * @param list<string> $classes
     *
     * @return list<ReturnFit::FITS|ReturnFit::FAILS>|null
     *
     * @mago-expect lint:no-boolean-flag-parameter
     */
    public static function of(NodeAnalysisContext $context, array $objects, array $classes, bool $created): ?array
    {
        $accepted = array_map(Type::namedObject(...), $classes);
        $acceptedType = count($accepted) === 1 ? $accepted[0] : Type::union(...$accepted);
        $comparisons = [];
        $names = [];
        foreach ($objects as $position => $object) {
            $comparisons[] = TypeComparison::containedBy(Type::fromAtomic($object), $acceptedType);
            $names[$position] = self::classNames($object);
            foreach ($names[$position] as $name) {
                foreach ($accepted as $class) {
                    $comparisons[] = TypeComparison::containedBy($class, Type::namedObject($name));
                }
            }
        }

        $answers = $context->types->compareMultiple($comparisons);
        $next = 0;
        $results = [];
        $unsure = [];
        $exact = [];
        foreach ($objects as $position => $object) {
            $count = count($names[$position]) * count($accepted);
            $fits = $answers[$next] ?? true;
            $widerAnswers = array_slice($answers, $next + 1, $count);
            $next += 1 + $count;
            if ($fits) {
                $results[] = ReturnFit::FITS;
                continue;
            }

            // A value of a wider type, such as an interface of the returned class, may be an instance of it.
            if ($names[$position] === [] || in_array(true, $widerAnswers, strict: true)) {
                return null;
            }

            $results[] = ReturnFit::FAILS;
            if (!$object instanceof EnumType && (!$created || !self::isDouble($object))) {
                $unsure = [...$unsure, ...$names[$position]];
                continue;
            }

            $exact = [...$exact, ...$names[$position]];
        }

        // The class of a double or an enum is known, but not its parents when they're outside the analyzed paths.
        if (!KnownHierarchy::areComplete($context->codebase, $exact)) {
            return null;
        }

        return $unsure === [] || self::allClasses($context, [...$unsure, ...$classes]) ? $results : null;
    }

    /**
     * Whether every name is a known class or enum, rather than an interface
     * or a trait.
     *
     * @param list<string> $names
     */
    private static function allClasses(NodeAnalysisContext $context, array $names): bool
    {
        foreach ($context->codebase->getMultipleClassLikes($names) as $metadata) {
            $isClass =
                $metadata !== null
                && !$metadata->hasIncompleteHierarchy()
                && $metadata->kind !== ClassLikeKind::Interface
                && $metadata->kind !== ClassLikeKind::Trait;
            if (!$isClass) {
                return false;
            }
        }

        return true;
    }

    /**
     * The class names of an object type, with PHPUnit's own left out.
     *
     * @return list<string>
     */
    private static function classNames(NamedObjectType|EnumType $object): array
    {
        if ($object instanceof EnumType) {
            return [$object->name];
        }

        return array_values(array_filter(
            Types::names(Type::fromAtomic($object)),
            static fn(string $name): bool => !str_starts_with(strtolower($name), 'phpunit\\'),
        ));
    }

    /**
     * Whether the object type is a PHPUnit double.
     */
    private static function isDouble(NamedObjectType $object): bool
    {
        return Doubles::classes(Type::fromAtomic($object)) !== [];
    }
}
