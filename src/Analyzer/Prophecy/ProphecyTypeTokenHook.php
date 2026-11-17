<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Prophecy;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\CallExpression;
use MagoPHPUnit\Analyzer\Support\Reporter;

use function ltrim;

/**
 * Reports a name given to `Argument::type()` that isn't a type or a class.
 *
 * @internal
 */
final class ProphecyTypeTokenHook implements MethodCallAnalysisHook
{
    public const CODE = 'prophecy-unknown-type';

    /**
     * Targets `Argument::type()`.
     */
    public function getTargets(): array
    {
        return [MethodTarget::exact('Prophecy\Argument', method: 'type')];
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
     * Reports a literal name that's neither a type with an `is_*()` function
     * nor a class, an interface or an enum.
     *
     * Prophecy's `TypeToken` throws for it. A `Foo::class` name isn't
     * checked here. Mago reports it when the class doesn't exist.
     */
    public function analyze(NodeAnalysisContext $context): void
    {
        $type = $context->argumentTypes[0] ?? null;
        $name = $type?->getLiteralString();
        if ($name === null || $type->getLiteralClassString() !== null) {
            return;
        }

        $codebase = $context->codebase;
        $class = ltrim($name, characters: '\\');
        if (
            $codebase->functionExists("is_{$name}")
            || $class !== '' && $codebase->classLikeExists($class) && !$codebase->traitExists($class)
        ) {
            return;
        }

        $call = CallExpression::fromNode($context->source, $context->node);
        Reporter::report(
            $context,
            Level::Error,
            self::CODE,
            Reporter::issue(
                '`Argument::type()` gets '
                . ($name === '' ? 'an empty string' : "`{$name}`")
                . ", which isn't a type or a class. Prophecy throws for it.",
                $call->arguments[0]->node->span ?? $context->node->span,
                help: 'Use a class or an interface, or a type with an `is_*()` function, such as `int` or `iterable`.',
            ),
        );
    }
}
