<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Support;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\EnumType;
use MagoPHPUnit\Internal\Type\Types;

/**
 * Finds whether Mago knows the classes in a type with all their parents.
 *
 * A class whose parent or interface lives outside the analyzed paths may
 * still be an instance of another class, so a check that says it can't be
 * isn't certain.
 *
 * @internal
 */
final class KnownHierarchy
{
    private function __construct() {}

    /**
     * Whether every class, interface and enum named in the types is known,
     * with all its parents and interfaces.
     */
    public static function isComplete(Codebase $codebase, ?Type ...$types): bool
    {
        $names = [];
        foreach ($types as $type) {
            $names = [...$names, ...Types::names($type)];
            foreach ($type->atomicTypes ?? [] as $atomic) {
                $names = $atomic instanceof EnumType ? [...$names, $atomic->name] : $names;
            }
        }

        return self::areComplete($codebase, $names);
    }

    /**
     * Whether every named class, interface and enum is known, with all its
     * parents and interfaces.
     *
     * @param list<string> $names
     */
    public static function areComplete(Codebase $codebase, array $names): bool
    {
        foreach ($names === [] ? [] : $codebase->getMultipleClassLikes($names) as $metadata) {
            if ($metadata === null || $metadata->hasIncompleteHierarchy()) {
                return false;
            }
        }

        return true;
    }
}
