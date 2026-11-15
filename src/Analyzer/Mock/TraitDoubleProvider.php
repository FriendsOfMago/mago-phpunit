<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use MagoPHPUnit\Internal\Type\Types;

use function ltrim;
use function strtolower;

/**
 * Types the objects made by `getMockForTrait()` and `getObjectForTrait()` with the trait's methods.
 *
 * @internal
 */
final class TraitDoubleProvider implements MethodReturnTypeProvider
{
    private const TEST_CASE = 'PHPUnit\Framework\TestCase';

    private const MOCK_OBJECT = 'PHPUnit\Framework\MockObject\MockObject';

    /**
     * Targets the two trait methods of `TestCase` on PHPUnit 10 and 11.
     */
    public function getTargets(): array
    {
        return [
            MethodTarget::exact(self::TEST_CASE, method: 'getMockForTrait'),
            MethodTarget::exact(self::TEST_CASE, method: 'getObjectForTrait'),
        ];
    }

    /**
     * Returns `MockObject&T` for `getMockForTrait(T::class)` and `T` for `getObjectForTrait(T::class)`.
     *
     * PHPUnit declares them as returning a plain `MockObject` or `object`,
     * since a trait can't be a type in PHP. With that type, a method of the
     * trait is missing on the object. The object is of a class that uses the
     * trait, so it has the trait's methods.
     */
    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        // @mago-expect lint:literal-named-argument
        $type = $context->invocation->getArgument(0, 'traitName')?->type;
        $trait = ltrim(Types::literalName($type), characters: '\\');
        if ($trait === '') {
            return null;
        }

        $object = new NamedObjectType($trait, null, null, false, false, null, false);
        if (strtolower($context->invocation->name) === 'getobjectfortrait') {
            return Type::fromAtomic($object);
        }

        return Type::fromAtomic(new NamedObjectType(self::MOCK_OBJECT, null, null, false, false, [$object], false));
    }
}
