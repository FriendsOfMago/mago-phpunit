<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AtomicType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use MagoPHPUnit\Internal\Type\Types;

use function count;
use function ltrim;
use function strtolower;

/**
 * Types the doubles made by `createMockForIntersectionOfInterfaces()` and
 * `createStubForIntersectionOfInterfaces()` as intersections.
 *
 * @internal
 */
final class IntersectionMockProvider implements MethodReturnTypeProvider
{
    private const TEST_CASE = 'PHPUnit\Framework\TestCase';

    /**
     * The type of the double for each method, by lowercase name.
     */
    private const DOUBLES = [
        'createmockforintersectionofinterfaces' => 'PHPUnit\Framework\MockObject\MockObject',
        'createstubforintersectionofinterfaces' => 'PHPUnit\Framework\MockObject\Stub',
    ];

    /**
     * Targets the two intersection double methods of `TestCase`.
     */
    public function getTargets(): array
    {
        $targets = [];
        foreach (self::DOUBLES as $method => $_) {
            $targets[] = MethodTarget::exact(self::TEST_CASE, $method);
        }

        return $targets;
    }

    /**
     * Returns `MockObject&A&B` or `Stub&A&B` when the argument is a list of
     * `::class` constants or class name strings.
     *
     * PHPUnit declares these methods as returning a plain `MockObject` or
     * `Stub`, since a docblock type can't be built from a list of class names.
     * With that type, a method of one of the interfaces is missing on the
     * double. phpstan-phpunit types the result as `MockObject&A&B` too.
     */
    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $double = self::DOUBLES[strtolower($context->invocation->name)] ?? null;
        // @mago-expect lint:literal-named-argument
        $interfaces = self::interfaces($context->invocation->getArgument(0, 'interfaces')?->type);
        if ($double === null || $interfaces === null) {
            return null;
        }

        $members = [];
        foreach ($interfaces as $interface) {
            $members[] = new NamedObjectType($interface, null, null, false, false, null, false);
        }

        return Type::fromAtomic(new NamedObjectType($double, null, null, false, false, $members, false));
    }

    /**
     * The class names in a list written out in full, or null when an element
     * isn't a class name string.
     *
     * @return non-empty-list<string>|null
     */
    private static function interfaces(?Type $type): ?array
    {
        if ($type === null || count($type->atomicTypes) !== 1) {
            return null;
        }

        $names = [];
        foreach (self::elements($type->atomicTypes[0]) as $element) {
            $name = ltrim(Types::literalName($element), characters: '\\');
            if ($name === '') {
                return null;
            }

            $names[] = $name;
        }

        return $names === [] ? null : $names;
    }

    /**
     * The element types of a list or array written out in full, or none when
     * an entry is optional.
     *
     * @return list<Type>
     */
    private static function elements(AtomicType $atomic): array
    {
        $elements = [];
        $entries = match (true) {
            $atomic instanceof ListType => $atomic->knownElements ?? [],
            $atomic instanceof KeyedArrayType && $atomic->keyType === null => $atomic->knownItems ?? [],
            default => [],
        };
        foreach ($entries as $entry) {
            if ($entry->optional) {
                return [];
            }

            $elements[] = $entry->type;
        }

        return $elements;
    }
}
