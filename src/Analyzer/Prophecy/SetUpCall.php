<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Prophecy;

use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Syntax\CallExpression;
use MagoPHPUnit\Internal\Type\Bottom;
use MagoPHPUnit\Internal\Type\Types;

use function in_array;

/**
 * Finds the method set up by the call that a `MethodProphecy` call is chained to.
 *
 * @internal
 */
final class SetUpCall
{
    /**
     * How many `MethodProphecy` calls, such as `shouldBeCalled()`, are
     * followed back to the call that sets up the method.
     */
    private const MAXIMUM_CHAIN = 8;

    private function __construct() {}

    /**
     * The method set up by the chained call and its class, when a value set
     * up for it is checked, or null.
     *
     * A call with a named or an unpacked argument isn't checked. A setup
     * that fails on its own is reported by ProphecySetUpHook, and a value
     * set up for a `void` or a `never` method by ProphecyReturnHook.
     *
     * @return array{FunctionLikeMetadata, string, list<non-empty-string>}|null
     */
    public static function returning(NodeAnalysisContext $context, CallExpression $call): ?array
    {
        foreach ($call->arguments as $argument) {
            if ($argument->name !== null || $argument->unpacked) {
                return null;
            }
        }

        $found = self::method($context, $call);
        if (
            $found === null
            || ProphecySetUpHook::fails($context->codebase, $found[0], $found[2])
            || Bottom::of($found[0]->declaredReturnType?->type) !== null
        ) {
            return null;
        }

        return $found;
    }

    /**
     * The prophesized method and the class shown for it, or null when the
     * call isn't on a method set up on a prophecy.
     *
     * `$prophecy->save()->shouldBeCalled()->willReturn(true)` is followed
     * back through the `MethodProphecy` calls to `save()`. The classes of the
     * prophecy come last.
     *
     * @return array{FunctionLikeMetadata, string, list<non-empty-string>}|null
     */
    public static function method(NodeAnalysisContext $context, CallExpression $call): ?array
    {
        $expression = $call->receiver;
        for ($step = 0; $step < self::MAXIMUM_CHAIN && $expression !== null; $step++) {
            $setUp = CallExpression::fromExpression($context->source, $expression);
            if ($setUp === null || !$setUp->isMethod() || $setUp->receiver === null) {
                return null;
            }

            $receiver = $context->analysis->getExpressionType($setUp->receiver);
            if (in_array(ProphesizedMethod::METHOD_PROPHECY, Types::names($receiver), strict: true)) {
                $expression = $setUp->receiver;
                continue;
            }

            return self::setUpMethod($context, $setUp, $receiver);
        }

        return null;
    }

    /**
     * The method set up by the call on the prophecy, its class, and the
     * classes of the prophecy.
     *
     * @return array{FunctionLikeMetadata, string, list<non-empty-string>}|null
     */
    private static function setUpMethod(NodeAnalysisContext $context, CallExpression $setUp, ?Type $receiver): ?array
    {
        $name = $setUp->getName($context->source);
        $method = $name === null ? null : CheckedMethod::find($context->codebase, $receiver, $name);
        $class = $method?->identifier->class;

        return $method === null || $class === null ? null : [$method, $class, ProphesizedMethod::classes($receiver)];
    }
}
