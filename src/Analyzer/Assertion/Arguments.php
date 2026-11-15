<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Assertion;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\CallArgument;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\NodeKind;
use Throwable;

use function in_array;
use function rtrim;
use function trim;

/**
 * Parses a call and finds the argument that fills a parameter.
 *
 * @internal
 */
final class Arguments
{
    /**
     * The node kinds for calls to a named function or method.
     */
    private const CALL_KINDS = [
        NodeKind::FunctionCall,
        NodeKind::MethodCall,
        NodeKind::NullSafeMethodCall,
        NodeKind::StaticMethodCall,
    ];

    private function __construct() {}

    /**
     * The call at the context's node, or null when the node isn't a call to a named function or method.
     */
    public static function call(NodeAnalysisContext $context): ?CallExpression
    {
        // A first-class callable, such as `$this->assertTrue(...)`, has a partial application node kind.
        if (!in_array($context->node->kind, self::CALL_KINDS, strict: true)) {
            return null;
        }

        try {
            return CallExpression::fromNode($context->source, $context->node);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The argument filling a parameter, with its node, or null when the call
     * doesn't pass it.
     *
     * The arguments are in source order. That's the parameter order only
     * while every argument is positional. For example,
     * `getHandler(handler_type: 'storage', entity_type_id: 'node')` puts the
     * handler type first, so reading position 0 would check the wrong string.
     *
     * Returns null after an argument unpacked from an array, since every
     * later position is unknown.
     *
     * @param int $position The parameter's position, counting from zero.
     * @param string $name The parameter's name, without the dollar sign.
     */
    public static function argument(CallExpression $call, int $position, string $name): ?CallArgument
    {
        $positional = 0;
        foreach ($call->arguments as $argument) {
            if ($argument->name !== null) {
                if (rtrim(trim($argument->name), characters: ' :') === $name) {
                    return $argument;
                }

                continue;
            }

            if ($argument->unpacked) {
                return null;
            }

            if ($positional === $position) {
                return $argument;
            }

            ++$positional;
        }

        return null;
    }
}
