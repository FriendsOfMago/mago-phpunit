<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Assertion;

use Mago\Sdk\Analyzer\Assertion\Assertion;
use Mago\Sdk\Analyzer\Assertion\TypeAssertion;
use Mago\Sdk\Analyzer\Assertion\TypeAssertionKind;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\IterableType;
use MagoPHPUnit\Internal\PHPUnitFeatures;
use MagoPHPUnit\Internal\Type\Types;

use function ltrim;

/**
 * Builds the narrowing for the assertions without a `@phpstan-assert` tag on PHPUnit 10.
 *
 * @internal
 */
final class UntaggedAssertions
{
    private function __construct() {}

    /**
     * Returns `iterable<Foo>` for `assertContainsOnlyInstancesOf(Foo::class, ...)`.
     *
     * PHPUnit 11.5 and later tag the method with `@phpstan-assert`, so the
     * assertion is only added where the tag is missing.
     */
    public static function onlyInstancesOf(Codebase $codebase, Invocation $invocation): ?Assertion
    {
        // @mago-expect lint:literal-named-argument
        $class = Types::literalName($invocation->getArgument(0, 'className')?->type);
        if ($class === '' || PHPUnitFeatures::tagsAssertion($codebase, method: 'assertContainsOnlyInstancesOf')) {
            return null;
        }

        return new TypeAssertion(
            TypeAssertionKind::IsType,
            Type::fromAtomic(new IterableType(Type::mixed(), Type::namedObject(ltrim($class, characters: '\\')), null)),
        );
    }

    /**
     * Returns `list<mixed>` for `assertIsList()`, where PHPUnit doesn't tag it.
     *
     * PHPUnit 11.5 and later tag the method with `@phpstan-assert`.
     */
    public static function isList(Codebase $codebase): ?Assertion
    {
        return PHPUnitFeatures::tagsAssertion($codebase, method: 'assertIsList')
            ? null
            : new TypeAssertion(TypeAssertionKind::IsType, Type::list(Type::mixed()));
    }
}
