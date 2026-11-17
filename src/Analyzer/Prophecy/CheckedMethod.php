<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Prophecy;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use MagoPHPUnit\Internal\Type\Types;

use function strtolower;

/**
 * Finds the method set up by a prophecy call when it's the same for every
 * prophecy that the receiver may be.
 *
 * @internal
 */
final class CheckedMethod
{
    private function __construct() {}

    /**
     * The method set up by a call on the receiver, or null when it isn't
     * known for sure.
     *
     * A receiver such as `ObjectProphecy<A>|ObjectProphecy<B>` may be a
     * prophecy of either class. The method is only known when each of them
     * has the same one. Otherwise the call isn't checked.
     */
    public static function find(Codebase $codebase, ?Type $receiver, string $name): ?FunctionLikeMetadata
    {
        $found = null;
        foreach (self::alternatives($receiver) as $classes) {
            $method = ProphesizedMethod::find($codebase, $classes, $name);
            if ($method === null || $found !== null && self::key($method) !== self::key($found)) {
                return null;
            }

            $found = $method;
        }

        return $found;
    }

    /**
     * The declaring class and the name of the method, lowercased.
     */
    private static function key(FunctionLikeMetadata $method): string
    {
        return strtolower("{$method->identifier->class}::{$method->identifier->name}");
    }

    /**
     * The classes of each prophecy that the receiver may be, such as
     * `[['A'], ['B']]` for `ObjectProphecy<A>|ObjectProphecy<B>` and
     * `[['A', 'B']]` for `ObjectProphecy<A&B>`.
     *
     * @return list<list<non-empty-string>>
     */
    private static function alternatives(?Type $receiver): array
    {
        $alternatives = [];
        foreach ($receiver->atomicTypes ?? [] as $atomic) {
            if (!$atomic instanceof NamedObjectType || $atomic->name !== ProphesizedMethod::OBJECT_PROPHECY) {
                continue;
            }

            foreach ($atomic->parameters ?? [] as $parameter) {
                foreach ($parameter->atomicTypes as $member) {
                    $alternatives[] = Types::names(Type::fromAtomic($member));
                }
            }
        }

        return $alternatives;
    }
}
