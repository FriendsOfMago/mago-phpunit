<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Prophecy;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\UndeclaredReturnTypeProvider;
use MagoPHPUnit\Internal\Type\Types;

use function array_map;
use function in_array;
use function strtolower;

/**
 * Types the calls that set up a prophecy.
 *
 * @internal
 */
final class ProphecyCallProvider implements MethodReturnTypeProvider, UndeclaredReturnTypeProvider
{
    private const OBJECT_PROPHECY = 'Prophecy\Prophecy\ObjectProphecy';

    private const METHOD_PROPHECY = 'Prophecy\Prophecy\MethodProphecy';

    /**
     * The methods `TraversablePatch` adds, by lowercased name.
     */
    private const ITERATOR_METHODS = ['current', 'key', 'next', 'rewind', 'valid'];

    /**
     * Targets every method of `ObjectProphecy`.
     */
    public function getTargets(): array
    {
        return [MethodTarget::allMethods(self::OBJECT_PROPHECY)];
    }

    /**
     * Returns `MethodProphecy` for a call on `ObjectProphecy<Foo>` to a method
     * of `Foo`.
     *
     * `$prophecy->getFoo()` goes through `__call()`, which returns a
     * `MethodProphecy`. `ObjectProphecy` doesn't have a `@method` tag for it,
     * so Mago reports every such call as undocumented. A method missing from
     * `Foo` stays reported, since Prophecy throws for it.
     *
     * A prophecy documented as `@var Foo|ObjectProphecy` is typed half by
     * half. The bare `ObjectProphecy` doesn't have a class parameter, so its
     * half gets a `MethodProphecy`. The `Foo` half gets the real method's
     * result. A prophecy is never a `Foo`, so the docblock needs fixing to
     * `ObjectProphecy<Foo>`. It's reported by ProphecyUnionHook.
     */
    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        return self::prophesied($context->invocation, $context->codebase)
            ? Type::namedObject(self::METHOD_PROPHECY)
            : null;
    }

    /**
     * Whether the method exists on the prophesized class, or the class isn't known.
     */
    private static function prophesied(Invocation $invocation, Codebase $codebase): bool
    {
        $classes = [];
        foreach ($invocation->receiverType->atomicTypes ?? [] as $atomic) {
            if (!$atomic instanceof NamedObjectType) {
                continue;
            }

            foreach ($atomic->parameters ?? [] as $parameter) {
                $classes = [...$classes, ...Types::names($parameter)];
            }
        }

        foreach ($classes as $class) {
            if (strtolower($class) !== 'object' && $codebase->getDeclaringMethod($class, $invocation->name) !== null) {
                return true;
            }
        }

        // A double of an interface that is `Traversable` but neither an
        // `Iterator` nor an `IteratorAggregate` also has the `Iterator`
        // methods. They're added by Prophecy's `TraversablePatch`.
        if (in_array($invocation->name, self::ITERATOR_METHODS, strict: true)) {
            foreach ($classes as $class) {
                if (self::patchedTraversable($codebase, $class)) {
                    return true;
                }
            }
        }

        return $classes === [];
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
