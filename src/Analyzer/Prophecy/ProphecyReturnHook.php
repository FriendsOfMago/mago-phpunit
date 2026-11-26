<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Prophecy;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\CallExpression;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Internal\Type\Bottom;
use MagoPHPUnit\Internal\Type\TypeText;

use function array_map;
use function strtolower;

/**
 * Reports a `willReturn()` value that the prophesized method can't return,
 * and a value set up for a method that returns `void` or `never`.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final class ProphecyReturnHook implements MethodCallAnalysisHook
{
    public const CODE = 'prophecy-invalid-return';

    /**
     * The `MethodProphecy` methods that set up a returned value.
     */
    private const PROMISES = ['willReturn', 'willReturnArgument', 'willYield'];

    /**
     * Targets the `MethodProphecy` methods that set up a returned value.
     */
    public function getTargets(): array
    {
        return array_map(static fn(string $promise): MethodTarget => MethodTarget::exact(
            ProphesizedMethod::METHOD_PROPHECY,
            method: $promise,
        ), self::PROMISES);
    }

    /**
     * Asks for the argument types, the call's subtree and the file's text.
     */
    public function getRequirements(): array
    {
        return [
            FileAnalysisRequirement::ArgumentTypes,
            FileAnalysisRequirement::TargetSubtree,
            FileAnalysisRequirement::SourceText,
        ];
    }

    /**
     * Reports each `willReturn()` value that isn't of the method's return
     * type, and any value set up for a method that can't return one.
     *
     * The double returns the values in turn, one per call, after Prophecy
     * reveals the prophecies in them. The double's code isn't in strict mode,
     * so PHP converts a scalar to the native return type when it can, such as
     * `'5'` for an `int`. A value that can't be converted fails with a
     * `TypeError`. Otherwise the code under test gets a value that a real
     * object doesn't return.
     */
    public function analyze(NodeAnalysisContext $context): void
    {
        $call = CallExpression::fromNode($context->source, $context->node);
        $found = SetUpCall::method($context, $call);
        if ($found === null) {
            return;
        }

        // Such a setup fails on its own. ProphecySetUpHook reports it.
        [$method, $class] = $found;
        if (ProphecySetUpHook::fails($context->codebase, $method, $found[2])) {
            return;
        }

        $name = "{$class}::{$method->originalName}()";
        $promise = self::promise($call->getName($context->source) ?? '');
        $bottom = Bottom::of($method->declaredReturnType?->type);
        if ($bottom !== null) {
            self::reportBottom($context, $name, $promise, $bottom);

            return;
        }

        if ($promise !== 'willReturn') {
            return;
        }

        $types = ReturnCheck::types($method, $class);
        if ($types === null) {
            return;
        }

        $returned = $types[1];

        // Without a value, the double returns null.
        $values = $call->arguments === [] ? [Type::null()] : [];
        foreach ($call->arguments as $index => $argument) {
            if ($argument->name !== null || $argument->unpacked) {
                return;
            }

            $values[] = $context->argumentTypes[$index] ?? null;
        }

        $returnedText = TypeText::of($returned);
        foreach ($values as $index => $value) {
            $level = $value === null ? null : ReturnCheck::level($context, $value, $types);
            if ($value === null || $level === null) {
                continue;
            }

            $span = $call->arguments[$index]->node->span ?? $context->node->span;
            $message = $call->arguments === []
                ? "`willReturn()` without a value returns null, but `{$name}` returns `{$returnedText}`."
                : '`willReturn()` gets `' . TypeText::of($value) . "`, but `{$name}` returns `{$returnedText}`.";
            Reporter::report(
                $context,
                $level,
                self::CODE,
                Reporter::issue(
                    $message,
                    $span,
                    help: "Return a value of the method's return type, or a prophecy of it.",
                ),
            );
        }
    }

    /**
     * Reports a value set up for a method that returns `void` or `never`.
     *
     * Prophecy throws for a value set up on a `void` method. A double of a
     * `never` method calls the promise and then ends without a return, so
     * PHP throws a `TypeError` unless the promise throws.
     *
     * @param 'void'|'never' $bottom
     */
    private static function reportBottom(
        NodeAnalysisContext $context,
        string $name,
        string $promise,
        string $bottom,
    ): void {
        $issue = $bottom === 'void'
            ? Reporter::issue(
                "`{$name}` returns `void`, so Prophecy throws for `{$promise}()`.",
                $context->node->span,
                help: "Remove the `{$promise}()` call. A double returns null from a void method by default.",
            )
            : Reporter::issue(
                "`{$name}` returns `never`, so the double fails with a `TypeError` after `{$promise}()`.",
                $context->node->span,
                help: 'Use `willThrow()` instead. A method that returns `never` must throw.',
            );
        Reporter::report($context, Level::Error, self::CODE, $issue);
    }

    /**
     * The name of the promise method as declared, for a name in any case.
     */
    private static function promise(string $name): string
    {
        foreach (self::PROMISES as $promise) {
            if (strtolower($promise) === strtolower($name)) {
                return $promise;
            }
        }

        return $name;
    }
}
