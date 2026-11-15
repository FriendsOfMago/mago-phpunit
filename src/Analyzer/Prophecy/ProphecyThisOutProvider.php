<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Prophecy;

use Mago\Sdk\Analyzer\Assertion\TypeAssertion;
use Mago\Sdk\Analyzer\Assertion\TypeAssertionKind;
use Mago\Sdk\Analyzer\AssertionProviderContext;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\InvocationAssertions;
use Mago\Sdk\Analyzer\MethodAssertionProvider;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AnyObjectType;
use Mago\Sdk\Analyzer\Type\AtomicType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

use function array_shift;
use function count;
use function strtolower;

/**
 * Adds the type given to `willImplement()` or `willExtend()` to the
 * prophesized type.
 *
 * @todo Remove this provider once Mago applies a `@this-out` that narrows
 *   the receiver.
 *
 * @see https://github.com/carthage-software/mago/issues/2395
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final class ProphecyThisOutProvider implements MethodReturnTypeProvider, MethodAssertionProvider
{
    private const OBJECT_PROPHECY = 'Prophecy\Prophecy\ObjectProphecy';

    /**
     * Targets `ObjectProphecy::willImplement()` and `willExtend()`.
     */
    public function getTargets(): array
    {
        return [
            MethodTarget::exact(self::OBJECT_PROPHECY, method: 'willImplement'),
            MethodTarget::exact(self::OBJECT_PROPHECY, method: 'willExtend'),
        ];
    }

    /**
     * Types the call as `ObjectProphecy<T&U>`, for a chain.
     *
     * Prophecy declares `willImplement()` and `willExtend()` with
     * `@phpstan-this-out static<T&U>`. Mago doesn't apply it, so a method of
     * the added type isn't found on the prophecy afterwards.
     */
    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        return self::extended($context->invocation, $context->codebase);
    }

    /**
     * Narrows the receiver to `ObjectProphecy<T&U>`, for a call on a variable.
     */
    public function getAssertions(AssertionProviderContext $context): ?InvocationAssertions
    {
        // An assertion can only narrow the receiver's type. So after
        // `willExtend()` the variable keeps the old parent class next to the
        // new one, while a chain gets the new parent alone.
        $extended = self::extended($context->invocation, $context->codebase, keepClasses: true);
        if ($extended === null) {
            return null;
        }

        return new InvocationAssertions([
            InvocationAssertions::RECEIVER => [new TypeAssertion(TypeAssertionKind::IsType, $extended)],
        ]);
    }

    /**
     * `ObjectProphecy<T&U>` for a receiver `ObjectProphecy<T>` and an
     * argument `U::class`, or null when either is not known.
     *
     * `willExtend()` replaces the parent class of the double, so the classes
     * in `T` are dropped and only its interfaces are kept.
     */
    private static function extended(Invocation $invocation, Codebase $codebase, bool $keepClasses = false): ?Type
    {
        // @mago-expect lint:literal-named-argument(2)
        $added = $invocation->getArgument(0, 'interface', 'class')?->type?->getLiteralClassString();
        $prophecy = self::prophecy($invocation->receiverType);
        if ($added === null || $prophecy === null) {
            return null;
        }

        $addedType = new NamedObjectType($added, null, null, false, false, null, false);
        $combined = [];
        // A prophecy told to implement something on one branch only is a
        // union of prophesized types, and each gets the added type.
        $extends = !$keepClasses && strtolower($invocation->name) === 'willextend';
        foreach ($prophecy->parameters[0]->atomicTypes ?? [] as $current) {
            $combined[] = self::withAdded(
                $extends && $current instanceof NamedObjectType ? self::interfacesOf($current, $codebase) : $current,
                $addedType,
            );
        }

        return Type::namedObject(
            self::OBJECT_PROPHECY,
            $combined === [] ? Type::fromAtomic($addedType) : Type::fromAtomics(...$combined),
        );
    }

    /**
     * The prophesized type with the added one. An unknown or plain `object`
     * prophecy becomes a prophecy of the added type alone.
     */
    private static function withAdded(AtomicType $current, NamedObjectType $added): AtomicType
    {
        if (!$current instanceof NamedObjectType) {
            return $added;
        }

        foreach ([$current, ...($current->intersections ?? [])] as $member) {
            if ($member instanceof NamedObjectType && strtolower($member->name) === strtolower($added->name)) {
                return $current;
            }
        }

        return new NamedObjectType(
            $current->name,
            $current->parameters,
            $current->variances,
            false,
            false,
            [...($current->intersections ?? []), $added],
            $current->remappedParameters,
        );
    }

    /**
     * The interfaces of a type such as `A&I`, as one type, or `object` when
     * it doesn't have any.
     */
    private static function interfacesOf(NamedObjectType $type, Codebase $codebase): AtomicType
    {
        $interfaces = [];
        foreach ([$type, ...($type->intersections ?? [])] as $member) {
            if (!$member instanceof NamedObjectType || !$codebase->interfaceExists($member->name)) {
                continue;
            }

            $interfaces[] = new NamedObjectType(
                $member->name,
                $member->parameters,
                $member->variances,
                false,
                false,
                null,
                false,
            );
        }

        $first = array_shift($interfaces);
        if ($first === null) {
            return new AnyObjectType();
        }

        return new NamedObjectType(
            $first->name,
            $first->parameters,
            $first->variances,
            false,
            false,
            $interfaces === [] ? null : $interfaces,
            $type->remappedParameters,
        );
    }

    /**
     * The receiver when it is one `ObjectProphecy`, since an assertion
     * replaces the receiver with one type.
     */
    private static function prophecy(?Type $receiver): ?NamedObjectType
    {
        $atomic = $receiver !== null && count($receiver->atomicTypes) === 1 ? $receiver->atomicTypes[0] : null;
        if (!$atomic instanceof NamedObjectType || strtolower($atomic->name) !== strtolower(self::OBJECT_PROPHECY)) {
            return null;
        }

        return $atomic;
    }
}
