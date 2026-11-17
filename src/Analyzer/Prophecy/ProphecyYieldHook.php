<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Prophecy;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\CallExpression;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Internal\Type\SettledType;
use MagoPHPUnit\Internal\Type\Types;
use MagoPHPUnit\Internal\Type\TypeText;
use MagoPHPUnit\Internal\Type\YieldedType;

/**
 * Reports a `willYield()` call that doesn't fit the prophesized method.
 *
 * @internal
 */
final class ProphecyYieldHook implements MethodCallAnalysisHook
{
    /**
     * Targets `MethodProphecy::willYield()`.
     */
    public function getTargets(): array
    {
        return [MethodTarget::exact(ProphesizedMethod::METHOD_PROPHECY, method: 'willYield')];
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
     * Checks the generator and its items against the method's return type.
     */
    public function analyze(NodeAnalysisContext $context): void
    {
        $call = CallExpression::fromNode($context->source, $context->node);
        $found = SetUpCall::returning($context, $call);
        if ($found !== null) {
            self::checkYield($context, $call, $found[0], $found[1]);
        }
    }

    /**
     * Reports a method whose native return type can't hold the generator,
     * and items of another type than the values the method yields.
     *
     * The generator is returned as it is. Prophecy doesn't reveal the
     * prophecies in its items.
     */
    private static function checkYield(
        NodeAnalysisContext $context,
        CallExpression $call,
        FunctionLikeMetadata $method,
        string $class,
    ): void {
        $native = $method->declaredReturnType?->type;
        $returned = $method->returnType->type ?? $native;
        if ($returned === null || !SettledType::is($returned)) {
            return;
        }

        // A native type doesn't have type parameters, so a plain `Generator`
        // is compared with it.
        $name = "{$class}::{$method->originalName}()";
        if ($native !== null && !$context->types->isContainedBy(Type::namedObject('Generator'), $native)) {
            Reporter::report(
                $context,
                Level::Error,
                ProphecyReturnHook::CODE,
                Reporter::issue(
                    "`willYield()` returns a `Generator`, but `{$name}` returns `"
                    . TypeText::of($native)
                    . '`, so the double fails with a `TypeError`.',
                    $context->node->span,
                    help: "Use `willReturn()` for a value that isn't a generator.",
                ),
            );

            return;
        }

        self::checkItems($context, $call, $name, $returned);
    }

    /**
     * Reports items of another type than the values yielded by the method.
     */
    private static function checkItems(
        NodeAnalysisContext $context,
        CallExpression $call,
        string $name,
        Type $returned,
    ): void {
        $items = $context->argumentTypes[0] ?? null;
        $yielded = YieldedType::of($returned);
        if ($items === null || $yielded === null || UncheckedValue::in($items) || UncheckedValue::in($yielded)) {
            return;
        }

        // Mago reports items that aren't an array as an argument of the
        // wrong type.
        if (!Types::arraysOnly($items)) {
            return;
        }

        $expected = Type::fromAtomic(
            new KeyedArrayType(null, Type::fromAtomic(new ScalarType(ScalarTypeKind::ArrayKey)), $yielded, false),
        );
        if ($context->types->isContainedBy($items, $expected)) {
            return;
        }

        Reporter::report(
            $context,
            Level::Warning,
            ProphecyReturnHook::CODE,
            Reporter::issue(
                '`willYield()` gets `'
                . TypeText::of($items)
                . "`, but `{$name}` yields `"
                . TypeText::of($yielded)
                . '`.',
                $call->arguments[0]->node->span ?? $context->node->span,
                help: 'Yield values of the type the method yields. Prophecy passes a prophecy in them as it is, without revealing it.',
            ),
        );
    }
}
