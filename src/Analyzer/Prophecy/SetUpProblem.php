<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Prophecy;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;
use MagoPHPUnit\Internal\Type\TypeText;

use function in_array;
use function str_starts_with;
use function strtolower;

/**
 * Tells why Prophecy can't set up a method of a double.
 *
 * @internal
 */
final class SetUpProblem
{
    /**
     * The methods starting with `_` that Prophecy replaces in a double of a
     * class, lowercased.
     */
    private const REPLACED_MAGIC = [
        '__construct',
        '__destruct',
        '__sleep',
        '__wakeup',
        '__tostring',
        '__call',
        '__invoke',
    ];

    private function __construct() {}

    /**
     * The message and the help for a method that Prophecy can't set up, or
     * null when it can.
     *
     * @param list<non-empty-string> $classes The classes of the prophecy.
     *
     * @return array{string, string}|null
     */
    public static function of(Codebase $codebase, FunctionLikeMetadata $method, array $classes): ?array
    {
        $display = "{$method->identifier->class}::{$method->originalName}()";
        $intersection = self::intersectionReturn($method);
        $visibility = $method->visibility;

        return match (true) {
            $method->final => [
                "Prophecy can't set up `{$display}`, since it's final.",
                "A double can't replace a final method. Use a real object, or remove `final`.",
            ],
            $intersection !== null => [
                "Prophecy can't set up `{$display}`, since it returns `" . TypeText::of($intersection) . '`.',
                'Prophecy throws for a method with an intersection in its native return type. Use a PHPUnit mock for it.',
            ],
            $visibility === Visibility::Private || $visibility === Visibility::Protected && !$method->abstract => [
                "Prophecy can't set up the "
                    . strtolower($visibility->name)
                    . " method `{$display}`. The double doesn't replace it, so the setup never takes effect.",
                'Set up the public methods that call it, or use a real object.',
            ],
            $method->static => [
                "Prophecy can't set up the static method `{$display}`. The double's method fails with an `Error` when it's called, since it uses `\$this`.",
                'Call the real static method, or wrap it in an instance method.',
            ],
            self::underscored($codebase, $method, $classes) => [
                "Prophecy can't set up `{$display}`, since the double doesn't replace a method whose name starts with `_`. The setup never takes effect.",
                'Rename the method, or use a real object.',
            ],
            default => null,
        };
    }

    /**
     * The native return type when it has an intersection, such as `A&B` or
     * `(A&B)|null`, or null.
     *
     * Prophecy throws when such a method is set up.
     */
    private static function intersectionReturn(FunctionLikeMetadata $method): ?Type
    {
        $type = $method->declaredReturnType?->type;
        foreach ($type->atomicTypes ?? [] as $atomic) {
            if ($atomic instanceof NamedObjectType && ($atomic->intersections ?? []) !== []) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Whether the method is a class's method whose name starts with `_` and
     * isn't one of the magic methods replaced by Prophecy.
     *
     * Prophecy replaces every method of an interface the double implements
     * through `prophesize()` or `willImplement()`, and every method added by
     * a `@method` tag.
     *
     * @param list<non-empty-string> $classes The classes of the prophecy.
     */
    private static function underscored(Codebase $codebase, FunctionLikeMetadata $method, array $classes): bool
    {
        $name = $method->originalName;
        if (
            !str_starts_with($name, '_')
            || in_array(strtolower($name), self::REPLACED_MAGIC, strict: true)
            || $method->flags->contains(MetadataFlags::MAGIC_METHOD)
        ) {
            return false;
        }

        foreach ($classes as $class) {
            if ($codebase->interfaceExists($class) && $codebase->getDeclaringMethod($class, $name) !== null) {
                return false;
            }
        }

        $declaring = $method->identifier->class;

        return $declaring !== null && $declaring !== '' && !$codebase->interfaceExists($declaring);
    }
}
