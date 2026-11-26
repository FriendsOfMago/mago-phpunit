<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\CallArgument;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

use function count;
use function in_array;
use function str_starts_with;
use function strtolower;

/**
 * Reads the values given to a PHPUnit stub from the syntax.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class StubSyntax
{
    /**
     * The lowercased `TestCase` methods that create a stub. PHPUnit 10 and 11 have them.
     */
    private const HELPERS = [
        'returnvalue',
        'returnvaluemap',
        'returnargument',
        'returncallback',
        'returnself',
        'throwexception',
        'onconsecutivecalls',
    ];

    private const RECEIVERS = ['$this', 'self', 'static', 'parent'];

    private const TEST_CASE = 'PHPUnit\\Framework\\TestCase';

    private function __construct() {}

    /**
     * The values given to a stub, or null when they aren't known.
     *
     * The stub is created with `new`, such as `new ReturnStub(5)`, or by a
     * `TestCase` method, such as `$this->returnValue(5)`. These methods are
     * final, and are only read as PHPUnit's when the installed `TestCase`
     * declares them.
     *
     * @return list<Node>|null
     */
    public static function arguments(NodeAnalysisContext $context, SourceFile $source, Node $node): ?array
    {
        $node = self::unwrap($source, $node);
        if ($node->kind === NodeKind::Instantiation) {
            foreach ($source->getChildren($node) as $child) {
                if ($child->kind === NodeKind::ArgumentList) {
                    return self::values($source, self::listArguments($source, $child));
                }
            }

            return [];
        }

        $call = CallExpression::fromExpression($source, $node);
        $receiver = $call?->receiver === null ? '' : strtolower($source->getText($call->receiver));
        $name = strtolower($call?->getName($source) ?? '');
        if (
            $call === null
            || $call->isFunction()
            || !in_array($receiver, self::RECEIVERS, strict: true)
            || !in_array($name, self::HELPERS, strict: true)
            || $context->codebase->getMethod(self::TEST_CASE, $name) === null
        ) {
            return null;
        }

        return self::values($source, $call->arguments);
    }

    /**
     * Whether the node creates an object with `new`.
     */
    public static function isInstantiation(SourceFile $source, Node $node): bool
    {
        return self::unwrap($source, $node)->kind === NodeKind::Instantiation;
    }

    /**
     * The values of the arguments, or null when one of them isn't known.
     *
     * An unpacked array that's written out, such as `...[1, 2]`, gives its
     * elements. Any other unpacked or named argument isn't known.
     *
     * @param list<CallArgument> $arguments
     *
     * @return list<Node>|null
     */
    public static function values(SourceFile $source, array $arguments): ?array
    {
        $values = [];
        foreach ($arguments as $argument) {
            if ($argument->name !== null) {
                return null;
            }

            $elements = $argument->unpacked ? self::elements($source, $argument->value) : [$argument->value];
            if ($elements === null) {
                return null;
            }

            $values = [...$values, ...$elements];
        }

        return $values;
    }

    /**
     * The elements of an array that's written out, such as `[1, $two]`, or
     * null when the array has keys, unpacked parts or gaps, or isn't
     * written out.
     *
     * @return list<Node>|null
     */
    public static function elements(SourceFile $source, Node $node): ?array
    {
        $node = self::unwrap($source, $node);
        if ($node->kind !== NodeKind::Array && $node->kind !== NodeKind::LegacyArray) {
            return null;
        }

        $values = [];
        foreach ($source->getChildren($node) as $element) {
            $variant = $source->getChildren($element)[0] ?? null;
            $value = $variant?->kind === NodeKind::ValueArrayElement ? $source->getChildren($variant)[0] ?? null : null;
            if ($value === null) {
                return null;
            }

            $values[] = self::unwrap($source, $value);
        }

        return $values;
    }

    /**
     * The arguments of an argument list, in the layout read by `CallExpression`.
     *
     * @return list<CallArgument>
     */
    private static function listArguments(SourceFile $source, Node $list): array
    {
        $arguments = [];
        foreach ($source->getChildren($list) as $argument) {
            $variant = $source->getChildren($argument)[0] ?? null;
            $named = $variant?->kind === NodeKind::NamedArgument;
            $parts = $variant === null ? [] : $source->getChildren($variant);
            $value = $parts[$named ? 1 : 0] ?? null;
            if ($variant === null || $value === null) {
                continue;
            }

            $arguments[] = new CallArgument(
                index: count($arguments),
                node: $argument,
                value: self::unwrap($source, $value),
                name: $named ? $source->getText($parts[0]) : null,
                unpacked: str_starts_with($source->getText($variant), '...'),
            );
        }

        return $arguments;
    }

    /**
     * The node inside any `Expression` wrappers.
     */
    private static function unwrap(SourceFile $source, Node $node): Node
    {
        while ($node->kind === NodeKind::Expression) {
            $inner = $source->getChildren($node)[0] ?? null;
            if ($inner === null) {
                return $node;
            }

            $node = $inner;
        }

        return $node;
    }
}
