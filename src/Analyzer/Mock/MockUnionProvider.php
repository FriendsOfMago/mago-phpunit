<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use Mago\Sdk\Analyzer\CallableSignatureProvider;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableParameter;
use Mago\Sdk\Analyzer\UndeclaredReturnTypeProvider;
use MagoPHPUnit\Internal\Type\Types;

use function count;
use function preg_match;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * Types `expects()` and `method()` on the mocked half of an `X|MockObject` union.
 *
 * @internal
 */
final class MockUnionProvider implements
    MethodReturnTypeProvider,
    CallableSignatureProvider,
    UndeclaredReturnTypeProvider
{
    private const EXPECTS = 'expects';

    /**
     * The return type of `expects()` in PHPUnit 10 and 11. PHPUnit 12 and 13
     * return other types, so mocker() looks the type up on `MockObject` first.
     */
    private const INVOCATION_MOCKER = 'PHPUnit\Framework\MockObject\Builder\InvocationMocker';

    private const MOCK_OBJECT = 'PHPUnit\Framework\MockObject\MockObject';

    private const INVOCATION_ORDER = 'PHPUnit\Framework\MockObject\Rule\InvocationOrder';

    private const METHOD_NAME = '/^([\'"])([A-Za-z_][A-Za-z0-9_]*)\1$/';

    public const PHPUNIT = 'PHPUnit\\';

    /**
     * Targets `expects()` and `method()` on any class.
     */
    public function getTargets(): array
    {
        return [MethodTarget::anyClass(self::EXPECTS), MethodTarget::anyClass('method')];
    }

    /**
     * Gives a mock call one parameter, typed `InvocationOrder` for `expects()`
     * so that Mago checks its argument.
     */
    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        $invocation = $context->invocation;
        if (!self::isMockCall($invocation, $context->codebase)) {
            return null;
        }

        return new EffectiveCallableSignature([
            strtolower($invocation->name) === self::EXPECTS
                ? new CallableParameter('$invocationRule', Type::namedObject(self::INVOCATION_ORDER))
                : new CallableParameter('$constraint'),
        ]);
    }

    /**
     * Returns the type of `MockObject::expects()` for a mock call.
     *
     * Older tests document mocks as `@var X|MockObject`, a union from before
     * PHP had intersection types. Mago checks each half of a union on its own.
     * On the `X` half, `expects()` doesn't exist, so the call is `mixed` and
     * the rest of the chain loses its type. phpstan-phpunit treats the union
     * as `X&MockObject`.
     */
    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        if (!self::isMockCall($context->invocation, $context->codebase)) {
            return null;
        }

        return self::mocker($context->codebase);
    }

    /**
     * The return type of `MockObject::expects()` in the installed PHPUnit.
     * `method()` is called on it in an expectation chain.
     */
    public static function mocker(Codebase $codebase): Type
    {
        return (
            $codebase->getMethod(self::MOCK_OBJECT, self::EXPECTS)?->returnType->type ?? Type::namedObject(
                self::INVOCATION_MOCKER,
            )
        );
    }

    /**
     * Whether the call looks like a mock call on a type that isn't PHPUnit's.
     *
     * Mago calls this provider only when the type doesn't declare the method.
     * Only that one type is passed, not the whole union, so the call is
     * checked by its shape. `expects()` needs one argument. `method()` needs the
     * name of a method of the type. PHPUnit's own types are skipped, so
     * `expects()` on the `Stub` of an `X|Stub` union or on a `MockBuilder`
     * stays reported. Any other call stays a missing method. On a value typed
     * `X` alone, these calls are reported by PlainMockCallHook.
     */
    private static function isMockCall(Invocation $invocation, Codebase $codebase): bool
    {
        $argument = $invocation->getArgument(0);
        if ($argument === null || count($invocation->arguments) !== 1) {
            return false;
        }

        // Any argument to `expects()` is accepted here. Mago checks it against
        // the parameter type. `method()` must name a method of the mocked type.
        $method = null;
        if (strtolower($invocation->name) !== self::EXPECTS) {
            $matches = [];
            if (preg_match(self::METHOD_NAME, trim($argument->expression), $matches) !== 1) {
                return false;
            }

            $method = $matches[2];
        }

        foreach ($invocation->receiverType->atomicTypes ?? [] as $atomic) {
            // Skip a member that is one of PHPUnit's types or already a double,
            // such as `Stub&X`.
            $classes = Types::names(Type::fromAtomic($atomic));
            foreach ($classes as $class) {
                if (str_starts_with($class, self::PHPUNIT)) {
                    continue 2;
                }
            }

            foreach ($classes as $class) {
                if ($method === null || $codebase->getDeclaringMethod($class, $method) !== null) {
                    return true;
                }
            }
        }

        return false;
    }
}
