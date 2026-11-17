<?php

declare(strict_types=1);

namespace MagoPHPUnit\Internal\Type;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\IterableType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

use function count;
use function in_array;
use function strtolower;

/**
 * Reads the type of the values yielded by an iterable type, such as
 * `Foo` in `Generator<int, Foo>` or `iterable<Foo>`.
 *
 * @internal
 */
final class YieldedType
{
    /**
     * The classes whose type parameters end with the type of the values they
     * yield, lowercased.
     */
    private const YIELDING = ['generator', 'traversable', 'iterator', 'iteratoraggregate'];

    private function __construct() {}

    /**
     * The type of the values yielded by a method of the return type, or null
     * when the type doesn't name it.
     */
    public static function of(Type $returned): ?Type
    {
        $values = [];
        foreach ($returned->atomicTypes as $atomic) {
            $parameters = $atomic instanceof NamedObjectType
            && in_array(strtolower($atomic->name), self::YIELDING, strict: true)
                ? $atomic->parameters ?? []
                : [];
            $value = match (true) {
                $atomic instanceof IterableType => $atomic->valueType,
                $parameters !== [] => $parameters[count($parameters) === 1 ? 0 : 1],
                default => null,
            };
            if ($value !== null) {
                $values = [...$values, ...$value->atomicTypes];
            }
        }

        return $values === [] ? null : Type::fromAtomics(...$values);
    }
}
