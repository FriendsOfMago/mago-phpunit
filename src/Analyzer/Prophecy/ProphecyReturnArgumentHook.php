<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Prophecy;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\CallExpression;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Internal\Type\SettledType;
use MagoPHPUnit\Internal\Type\TypeText;

use function count;

/**
 * Reports a `willReturnArgument()` call whose argument the prophesized method can't return.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final class ProphecyReturnArgumentHook implements MethodCallAnalysisHook
{
    /**
     * Targets `MethodProphecy::willReturnArgument()`.
     */
    public function getTargets(): array
    {
        return [MethodTarget::exact(ProphesizedMethod::METHOD_PROPHECY, method: 'willReturnArgument')];
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
     * Checks the returned argument against the method's return type.
     */
    public function analyze(NodeAnalysisContext $context): void
    {
        $call = CallExpression::fromNode($context->source, $context->node);
        $found = SetUpCall::returning($context, $call);
        if ($found !== null) {
            self::checkArgument($context, $call, $found[0], $found[1]);
        }
    }

    /**
     * Reports an index past the method's parameters, and a parameter whose
     * type the method can't return.
     *
     * The double returns null when the call doesn't have the argument.
     */
    private static function checkArgument(
        NodeAnalysisContext $context,
        CallExpression $call,
        FunctionLikeMetadata $method,
        string $class,
    ): void {
        $index = $call->arguments === [] ? 0 : $context->argumentTypes[0]?->getLiteralInt();
        if ($index !== null && $index < 0) {
            Reporter::report(
                $context,
                Level::Error,
                ProphecyReturnHook::CODE,
                Reporter::issue(
                    "`willReturnArgument({$index})` gets a negative index. Prophecy throws for it.",
                    $call->arguments[0]->node->span ?? $context->node->span,
                    help: 'Use the index of a parameter, counted from 0.',
                ),
            );

            return;
        }

        $types = ReturnCheck::types($method, $class);
        if ($index === null || $types === null) {
            return;
        }

        $parameters = $method->parameters;
        $last = $parameters[count($parameters) - 1] ?? null;
        $parameter = $parameters[$index] ?? ($last?->flags->contains(MetadataFlags::VARIADIC) === true ? $last : null);
        $value = $parameter === null ? Type::null() : $parameter->type?->type;
        if ($value === null || !SettledType::is($value)) {
            return;
        }

        $level = ReturnCheck::level($context, $value, $types);
        if ($level === null) {
            return;
        }

        // The parameter type only bounds the argument. An argument of a type
        // that the method can return may still be passed, such as a numeric
        // string for a `string` parameter of a method that returns `int`. A
        // call with an extra argument returns it past the parameters.
        if ($parameter === null || $level === Level::Error && ReturnCheck::overlaps($context, $value, $types[2])) {
            $level = Level::Warning;
        }

        $name = "{$class}::{$method->originalName}()";
        $returned = TypeText::of($types[1]);
        $issue = $parameter === null
            ? Reporter::issue(
                "`willReturnArgument({$index})` returns null unless the call passes an extra argument, since `{$name}` doesn't have a parameter at index {$index}. The method returns `{$returned}`.",
                $call->arguments[0]->node->span ?? $context->node->span,
                help: 'Use the index of a parameter, counted from 0.',
            )
            : Reporter::issue(
                "`willReturnArgument({$index})` returns `{$parameter->name}` of type `"
                . TypeText::of($value)
                . "`, but `{$name}` returns `{$returned}`.",
                $call->arguments[0]->node->span ?? $context->node->span,
                help: "Return an argument of the method's return type.",
            );
        Reporter::report($context, $level, ProphecyReturnHook::CODE, $issue);
    }
}
