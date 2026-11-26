<?php

declare(strict_types=1);

namespace MagoPHPUnit\Internal\Type;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;

use function count;

/**
 * Reads the `void` and `never` return types.
 *
 * @internal
 */
final class Bottom
{
    private function __construct() {}

    /**
     * `void` or `never` when the type is one of them, or null.
     *
     * @return 'void'|'never'|null
     */
    public static function of(?Type $type): ?string
    {
        $atomics = $type->atomicTypes ?? [];
        $atomic = count($atomics) === 1 ? $atomics[0] : null;
        if (!$atomic instanceof SimpleAtomicType) {
            return null;
        }

        return match ($atomic->kind) {
            SimpleAtomicTypeKind::Void => 'void',
            SimpleAtomicTypeKind::Never => 'never',
            default => null,
        };
    }
}
