<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\NodeKind;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Internal\Type\Types;
use Throwable;

use function implode;
use function str_starts_with;

/**
 * Reports `expects()` or `method()` on a value that isn't typed as a PHPUnit
 * double.
 *
 * @internal
 */
final class PlainMockCallHook implements MethodCallAnalysisHook
{
    public const CODE = 'mock-call-on-plain-type';

    /**
     * @param 'expects'|'method' $method
     */
    public function __construct(
        private readonly string $method,
    ) {}

    /**
     * Targets the method checked by this instance, on any class.
     */
    public function getTargets(): array
    {
        return [MethodTarget::anyClass($this->method)];
    }

    /**
     * Asks for the receiver type, the call's type, its subtree and the file's
     * text.
     */
    public function getRequirements(): array
    {
        // The subtree and the text of the same calls are also asked for by
        // MockMethodHook. Mago sends them once for both hooks.
        return [
            FileAnalysisRequirement::ReceiverType,
            FileAnalysisRequirement::TargetExpressionTypes,
            FileAnalysisRequirement::TargetSubtree,
            FileAnalysisRequirement::SourceText,
        ];
    }

    /**
     * Reports a call typed by MockUnionProvider when a PHPUnit type isn't part of the receiver's type.
     */
    public function analyze(NodeAnalysisContext $context): void
    {
        // Any other type means the call wasn't typed by the provider, and
        // Mago already reported it.
        $target = Types::names($context->targetType);
        if ($target !== Types::names(MockUnionProvider::mocker($context->codebase))) {
            return;
        }

        // MockUnionProvider is called with one member of a union at a time. A call on `X` alone is
        // typed the same as on the `X` part of `X|MockObject`. The whole receiver type is available
        // here. The value may be a mock declared without `MockObject` in its type. Otherwise the
        // value isn't a mock. In that case the call fails at runtime. The two cases can't be told
        // apart from the type. The call is an error in either case. PHPStan reports an undefined
        // method. Without the provider, Mago would report this call. Each later call in the chain
        // would also be reported as a call on `mixed`.
        $classes = Types::names($context->receiverType);
        if ($classes === []) {
            return;
        }

        foreach ($classes as $class) {
            if (
                str_starts_with($class, MockUnionProvider::PHPUNIT)
                || $context->codebase->getDeclaringMethod($class, $this->method) !== null
            ) {
                return;
            }
        }

        $type = implode('|', $classes);
        Reporter::report(
            $context,
            Level::Error,
            self::CODE,
            Issue::new("`{$this->method}()` isn't declared by `{$type}`.", $this->nameSpan($context))->withHelp(
                "If the value is a mock, type it as `{$type}&\\PHPUnit\\Framework\\MockObject\\MockObject` where it is declared, such as the property's type or the helper's return type. Otherwise the call fails when the test runs.",
            ),
        );
    }

    /**
     * The span of the method name, where Mago reports its own method issues,
     * or of the whole call when the name isn't written out.
     */
    private function nameSpan(NodeAnalysisContext $context): Span
    {
        try {
            $call = CallExpression::fromNode($context->source, $context->node);
        } catch (Throwable) {
            return $context->node->span;
        }

        $selector = $call->member === null ? null : $context->source->getChildren($call->member)[0] ?? null;

        return $selector?->kind === NodeKind::LocalIdentifier ? $selector->span : $context->node->span;
    }
}
