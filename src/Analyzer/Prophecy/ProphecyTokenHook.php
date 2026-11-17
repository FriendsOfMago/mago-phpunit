<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Prophecy;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Metadata\ParameterMetadata;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Syntax\CallArgument;
use Mago\Sdk\Syntax\CallExpression;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Internal\Type\SettledType;
use MagoPHPUnit\Internal\Type\Types;
use MagoPHPUnit\Internal\Type\TypeText;

use function count;
use function in_array;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

/**
 * Reports an argument token that can't match the argument of the
 * prophesized method it stands for.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final class ProphecyTokenHook implements MethodCallAnalysisHook
{
    public const CODE = 'prophecy-token-mismatch';

    /**
     * The namespace of Prophecy's argument tokens.
     */
    private const TOKENS = 'Prophecy\Argument\Token\\';

    /**
     * The `Argument` methods that build each token, lowercased, by the token
     * class without its `Token` suffix.
     */
    private const FACTORIES = [
        'Type' => ['type'],
        'ExactValue' => ['exact'],
        'IdenticalValue' => ['is'],
        'Callback' => ['that'],
        'StringContains' => ['containingstring'],
        'ArrayCount' => ['size'],
        'ArrayEntry' => ['withentry', 'withkey', 'containing'],
        'ArrayEveryEntry' => ['witheveryentry'],
        'ApproximateValue' => ['approximate'],
    ];

    /**
     * Targets every call on a prophecy.
     */
    public function getTargets(): array
    {
        return [MethodTarget::allMethods(ProphesizedMethod::OBJECT_PROPHECY)];
    }

    /**
     * Asks for the receiver and argument types, the call's subtree and the
     * file's text.
     */
    public function getRequirements(): array
    {
        return [
            FileAnalysisRequirement::ReceiverType,
            FileAnalysisRequirement::ArgumentTypes,
            FileAnalysisRequirement::TargetSubtree,
            FileAnalysisRequirement::SourceText,
        ];
    }

    /**
     * Checks each token passed to a call that sets up a method, up to an
     * `Argument::cetera()` token.
     *
     * The double gets the arguments of the method's parameter types, so a
     * token for another type never matches. The test then fails with an
     * unexpected call, or the setup never takes effect.
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
        $class = $method?->identifier->class;
        if (
            $method === null
            || $class === null
            || SetUpProblem::of($context->codebase, $method, ProphesizedMethod::classes($context->receiverType))
                !== null
        ) {
            return;
        }

        foreach ($call->arguments as $index => $argument) {
            $matcher = self::matcher($context->argumentTypes[$index] ?? null);
            if ($argument->name !== null || $argument->unpacked || $matcher === 'AnyValues') {
                return;
            }

            $parameter = self::parameterAt($method, $index);
            if ($matcher !== null && $parameter !== null) {
                self::check($context, $argument, $matcher, $parameter, $method);
            }
        }
    }

    /**
     * Reports the token when it can't match the parameter's arguments.
     *
     * @param string $matcher The token class without its namespace and its
     *   `Token` suffix, such as `Type`.
     */
    private static function check(
        NodeAnalysisContext $context,
        CallArgument $argument,
        string $matcher,
        ParameterMetadata $parameter,
        FunctionLikeMetadata $method,
    ): void {
        $class = (string) $method->identifier->class;
        $declared = $parameter->type?->type;
        if ($declared === null || !SettledType::is($declared) || UncheckedValue::at($declared)) {
            return;
        }

        $native = $parameter->declaredType?->type;
        $inner = self::factory($context, $argument, $matcher);
        $innerArgument = $inner?->arguments[0] ?? null;
        $value = $innerArgument === null ? null : $context->analysis->getExpressionType($innerArgument->value);
        $shown = self::shown($context, $inner, $matcher, $value);
        $target = "the argument `{$parameter->name}` of `{$class}::{$method->originalName}()`";
        $takes = TypeText::of($declared);

        $issue = match ($matcher) {
            'IdenticalValue' => TokenCheck::value(
                $context,
                exact: null,
                value: $value,
                declared: $declared,
                native: $native,
            ),
            'ExactValue' => TokenCheck::value(
                $context,
                exact: $class,
                value: $value,
                declared: $declared,
                native: $native,
            ),
            'Callback' => TokenCheck::callback($context, $class, $value, $declared, $native),
            default => TokenCheck::need($context, $matcher, $value, $declared, $native),
        };
        if ($issue === null) {
            return;
        }

        [$level, $kind, $type] = $issue;
        $message = match ($kind) {
            'never' => "`{$shown}` never matches {$target}, which takes `{$takes}`.",
            'callback' => "The callback of `{$shown}` takes `"
                . TypeText::of($type)
                . "`, but {$target} takes `{$takes}`. The callback fails with a `TypeError` for every argument.",
        };
        $help = match ($kind) {
            'never' => "Use a token for the parameter's type.",
            'callback' => "Give the callback a parameter of the method's parameter type.",
        };
        Reporter::report($context, $level, self::CODE, Reporter::issue($message, $argument->node->span, help: $help));
    }

    /**
     * The static call that builds the token, such as `Argument::type('int')`,
     * or null when the token is built another way.
     *
     * Only the arguments of such a call are read. A helper that returns a
     * token may take other arguments.
     */
    private static function factory(
        NodeAnalysisContext $context,
        CallArgument $argument,
        string $matcher,
    ): ?CallExpression {
        $inner = CallExpression::fromExpression($context->source, $argument->value);
        $name = $inner?->isStaticMethod() === true ? $inner->getName($context->source) : null;

        return $name !== null && in_array(strtolower($name), self::FACTORIES[$matcher] ?? [], strict: true)
            ? $inner
            : null;
    }

    /**
     * The token as shown in the message, such as `Argument::type('int')`.
     */
    private static function shown(
        NodeAnalysisContext $context,
        ?CallExpression $inner,
        string $matcher,
        ?Type $value,
    ): string {
        $name = $inner?->getName($context->source);
        if ($name === null) {
            return "{$matcher}Token";
        }

        $literal = $matcher === 'Type' ? $value?->getLiteralString() : null;

        return $literal === null ? "Argument::{$name}()" : "Argument::{$name}('{$literal}')";
    }

    /**
     * The token class without its namespace and its `Token` suffix, such as
     * `Type` for `TypeToken`, or null when the value isn't only one of
     * Prophecy's tokens.
     */
    private static function matcher(?Type $type): ?string
    {
        $names = Types::names($type);
        if (count($names) !== 1 || !str_starts_with($names[0], self::TOKENS)) {
            return null;
        }

        $class = substr($names[0], offset: strlen(self::TOKENS));

        return str_ends_with($class, 'Token') ? substr($class, offset: 0, length: -5) : null;
    }

    /**
     * The parameter that takes the argument at the index, or null when the
     * method doesn't have one. A variadic parameter takes every argument
     * from its position on.
     */
    private static function parameterAt(FunctionLikeMetadata $method, int $index): ?ParameterMetadata
    {
        $parameters = $method->parameters;
        $last = $parameters[count($parameters) - 1] ?? null;

        return $parameters[$index] ?? ($last?->flags->contains(MetadataFlags::VARIADIC) === true ? $last : null);
    }
}
