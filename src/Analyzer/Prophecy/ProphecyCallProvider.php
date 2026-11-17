<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Prophecy;

use Mago\Sdk\Analyzer\CallableSignatureProvider;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\UndeclaredReturnTypeProvider;

/**
 * Types the calls that set up a prophecy and checks their arguments.
 *
 * @internal
 */
final class ProphecyCallProvider implements
    MethodReturnTypeProvider,
    UndeclaredReturnTypeProvider,
    CallableSignatureProvider
{
    /**
     * The interface of the argument matchers, such as `Argument::any()`.
     */
    public const ARGUMENT_MATCHER = 'Prophecy\Argument\Token\TokenInterface';

    /**
     * Targets every method of `ObjectProphecy`.
     */
    public function getTargets(): array
    {
        return [MethodTarget::allMethods(ProphesizedMethod::OBJECT_PROPHECY)];
    }

    /**
     * Returns `MethodProphecy` for a call on `ObjectProphecy<Foo>` to a method
     * of `Foo`.
     *
     * `$prophecy->getFoo()` goes through `__call()`, which returns a
     * `MethodProphecy`. `ObjectProphecy` doesn't have a `@method` tag for it,
     * so Mago reports every such call as undocumented. A method missing from
     * `Foo` stays reported, since Prophecy throws for it.
     *
     * A prophecy documented as `@var Foo|ObjectProphecy` is typed half by
     * half. The bare `ObjectProphecy` doesn't have a class parameter, so its
     * half gets a `MethodProphecy`. The `Foo` half gets the real method's
     * result. A prophecy is never a `Foo`, so the docblock needs fixing to
     * `ObjectProphecy<Foo>`. It's reported by ProphecyUnionHook.
     */
    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $invocation = $context->invocation;
        $classes = ProphesizedMethod::classes($invocation->receiverType);
        if ($classes !== [] && ProphesizedMethod::find($context->codebase, $classes, $invocation->name) === null) {
            return null;
        }

        return Type::namedObject(ProphesizedMethod::METHOD_PROPHECY);
    }

    /**
     * Returns the parameters of the prophesized method.
     *
     * Mago checks the arguments of the call against them.
     * Each parameter also takes an argument token, such as `Argument::any()`,
     * and a prophecy of an object it takes. Prophecy reveals a prophecy
     * passed as an argument.
     */
    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        $invocation = $context->invocation;
        $method = CheckedMethod::find($context->codebase, $invocation->receiverType, $invocation->name);
        $class = $method?->identifier->class;
        if ($method === null || $class === null) {
            return null;
        }

        return ProphecySignature::of($context->codebase, $method, $class, $invocation->arguments);
    }
}
