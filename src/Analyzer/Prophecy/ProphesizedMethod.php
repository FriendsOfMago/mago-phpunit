<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Prophecy;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use MagoPHPUnit\Internal\Type\Types;

use function array_filter;
use function array_map;
use function in_array;
use function strtolower;

/**
 * Finds the method of the prophesized class that a prophecy call sets up.
 *
 * @internal
 */
final class ProphesizedMethod
{
    public const OBJECT_PROPHECY = 'Prophecy\Prophecy\ObjectProphecy';

    public const METHOD_PROPHECY = 'Prophecy\Prophecy\MethodProphecy';

    /**
     * The methods `TraversablePatch` adds, by lowercased name.
     */
    private const ITERATOR_METHODS = ['current', 'key', 'next', 'rewind', 'valid'];

    private function __construct() {}

    /**
     * The classes in the type parameters of the receiver's `ObjectProphecy` members.
     *
     * @return list<non-empty-string>
     */
    public static function classes(?Type $receiver): array
    {
        $classes = [];
        foreach ($receiver->atomicTypes ?? [] as $atomic) {
            if (!$atomic instanceof NamedObjectType || $atomic->name !== self::OBJECT_PROPHECY) {
                continue;
            }

            foreach ($atomic->parameters ?? [] as $parameter) {
                $classes = [...$classes, ...Types::names($parameter)];
            }
        }

        return $classes;
    }

    /**
     * The method of one of the classes, or null when none of them has it.
     *
     * @param list<non-empty-string> $classes
     * @param string $name The method name, in any case.
     */
    public static function find(Codebase $codebase, array $classes, string $name): ?FunctionLikeMetadata
    {
        // The double extends the class and implements the interfaces. The
        // class's method is the one called, so it's looked up first, such as
        // after `willExtend()` on a prophecy of an interface.
        $classes = [
            ...array_filter($classes, static fn(string $class): bool => !$codebase->interfaceExists($class)),
            ...array_filter($classes, $codebase->interfaceExists(...)),
        ];
        foreach ($classes as $class) {
            if (strtolower($class) === 'object') {
                continue;
            }

            $method = $codebase->getDeclaringMethod($class, $name);
            if ($method !== null) {
                return $method;
            }
        }

        // A double of an interface that is `Traversable` but neither an
        // `Iterator` nor an `IteratorAggregate` also has the `Iterator`
        // methods. They're added by Prophecy's `TraversablePatch`.
        if (in_array(strtolower($name), self::ITERATOR_METHODS, strict: true)) {
            foreach ($classes as $class) {
                if (self::patchedTraversable($codebase, $class)) {
                    return $codebase->getDeclaringMethod('Iterator', $name);
                }
            }
        }

        return null;
    }

    /**
     * Whether the class is `Traversable` without being an `IteratorAggregate`.
     */
    private static function patchedTraversable(Codebase $codebase, string $class): bool
    {
        // A class must implement `Iterator` or `IteratorAggregate` to be Traversable. An interface
        // can extend `Traversable` directly. The `Iterator` methods are declared in `Iterator`
        // itself. They are found by the method lookup before this check.
        $ancestors = array_map(strtolower(...), [$class, ...$codebase->getClassAncestors($class)]);

        return (
            in_array('traversable', $ancestors, strict: true)
            && !in_array('iteratoraggregate', $ancestors, strict: true)
        );
    }
}
