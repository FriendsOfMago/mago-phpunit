<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Prophecy;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\CallExpression;
use MagoPHPUnit\Analyzer\Support\Reporter;

/**
 * Reports a method set up on a prophecy when Prophecy throws for it.
 *
 * @internal
 */
final class ProphecySetUpHook implements MethodCallAnalysisHook
{
    public const CODE = 'prophecy-invalid-setup';

    /**
     * Targets every call on a prophecy.
     */
    public function getTargets(): array
    {
        return [MethodTarget::allMethods(ProphesizedMethod::OBJECT_PROPHECY)];
    }

    /**
     * Asks for the receiver type, the call's subtree and the file's text.
     */
    public function getRequirements(): array
    {
        return [
            FileAnalysisRequirement::ReceiverType,
            FileAnalysisRequirement::TargetSubtree,
            FileAnalysisRequirement::SourceText,
        ];
    }

    /**
     * Reports a method that Prophecy can't set up.
     *
     * Prophecy throws when a final method, or a method with an intersection
     * in its native return type, is set up. A stub, a mock and a spy of it
     * all fail. A private method, a protected one that isn't abstract, and a
     * class's method whose name starts with `_` aren't replaced in the
     * double, so their setup never takes effect. A double's static method
     * fails when it's called.
     */
    public function analyze(NodeAnalysisContext $context): void
    {
        $call = CallExpression::fromNode($context->source, $context->node);
        $name = $call->getName($context->source);
        if (
            $name === null
            || $context->codebase->getDeclaringMethod(ProphesizedMethod::OBJECT_PROPHECY, $name) !== null
        ) {
            return;
        }

        $method = CheckedMethod::find($context->codebase, $context->receiverType, $name);
        $problem = $method === null
            ? null
            : SetUpProblem::of($context->codebase, $method, ProphesizedMethod::classes($context->receiverType));
        if ($problem !== null) {
            Reporter::report(
                $context,
                Level::Error,
                self::CODE,
                Reporter::issue($problem[0], $context->node->span, help: $problem[1]),
            );
        }
    }

    /**
     * Whether Prophecy can't set up the method, so a check of the values set
     * up for it would only repeat this hook's report.
     *
     * @param list<non-empty-string> $classes The classes of the prophecy.
     */
    public static function fails(Codebase $codebase, FunctionLikeMetadata $method, array $classes): bool
    {
        return SetUpProblem::of($codebase, $method, $classes) !== null;
    }
}
