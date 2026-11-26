<?php

declare(strict_types=1);

namespace MagoPHPUnit\Internal\Type;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AtomicType;
use Mago\Sdk\Analyzer\Type\CallableType;

/**
 * Reads closures as objects of the `Closure` class.
 *
 * Mago types a closure, such as `fn() => 1`, a first-class callable and the
 * native `Closure` type as callables. At runtime they're objects of the
 * `Closure` class.
 *
 * @internal
 */
final class Closures
{
    private function __construct() {}

    /**
     * The type with each closure read as an object of the `Closure` class.
     * Another callable type isn't changed.
     */
    public static function asObjects(Type $type): Type
    {
        $atomics = [];
        $changed = false;
        foreach ($type->atomicTypes as $atomic) {
            $closure = self::isClosure($atomic);
            $atomics = [...$atomics, ...($closure ? Type::namedObject('Closure')->atomicTypes : [$atomic])];
            $changed = $changed || $closure;
        }

        return $changed ? Type::fromAtomics(...$atomics) : $type;
    }

    /**
     * Whether the part of a type is a closure.
     */
    public static function isClosure(AtomicType $atomic): bool
    {
        return $atomic instanceof CallableType && $atomic->signature?->closure === true;
    }
}
