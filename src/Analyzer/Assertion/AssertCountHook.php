<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Assertion;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\CallExpression;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Internal\Type\Types;

use function array_intersect;
use function count;
use function ltrim;
use function stripos;
use function strtolower;

/**
 * Reports `assertSame($n, count($value))` and `assertSame($n, $value->count())`.
 *
 * @internal
 * @mago-expect lint:cyclomatic-complexity
 */
final class AssertCountHook implements MethodCallAnalysisHook
{
    public const CODE = 'assert-same-count';

    private const ASSERT = 'PHPUnit\Framework\Assert';

    /**
     * Targets `Assert::assertSame()`.
     */
    public function getTargets(): array
    {
        return [MethodTarget::exact(self::ASSERT, method: 'assertSame')];
    }

    /**
     * Asks for the call's subtree and the file's text.
     */
    public function getRequirements(): array
    {
        // Other hooks ask for the text of test files too. Mago sends it once
        // per file, so asking here doesn't cost more.
        return [FileAnalysisRequirement::TargetSubtree, FileAnalysisRequirement::SourceText];
    }

    /**
     * Reports a call with `count($value)` or `$value->count()` as its second argument.
     */
    public function analyze(NodeAnalysisContext $context): void
    {
        // Most `assertSame()` calls don't use `count`. Searching the call's
        // text for it is cheaper than parsing its syntax.
        $source = $context->source;
        if (stripos($source->getText($context->node), needle: 'count') === false) {
            return;
        }

        $call = CallExpression::fromNode($source, $context->node);
        $actual = $call->arguments[1] ?? null;
        if ($actual === null || $actual->name !== null || $actual->unpacked) {
            return;
        }

        $counted = CallExpression::fromExpression($source, $actual->value);
        if ($counted === null) {
            return;
        }

        // With `assertCount()`, the size check is clear from the name. PHPUnit shows the expected
        // size on failure. It shows the actual size too. This matches phpstan-phpunit's
        // `AssertSameWithCountRule`.
        // A `count()` call with a mode argument isn't reported. A mode argument isn't accepted by
        // `assertCount()`.
        $name = strtolower(ltrim($counted->getName($source) ?? '', characters: '\\'));
        $form = match (true) {
            $counted->isFunction() && $name === 'count' && count($counted->arguments) === 1 => 'count($value)',
            $counted->isMethod()
                && $name === 'count'
                && $counted->arguments === []
                && self::countable($context, $counted)
                => '$value->count()',
            default => null,
        };
        if ($form === null) {
            return;
        }

        Reporter::report(
            $context,
            Level::Warning,
            self::CODE,
            Issue::new(
                "Use `assertCount(\$expected, \$value)` instead of `assertSame(\$expected, {$form})`.",
                $context->node->span,
            )->withHelp(
                "With assertCount(), it's clear that the test checks a size. When it fails, PHPUnit shows both sizes.",
            ),
        );
    }

    /**
     * Whether the receiver of `->count()` is Countable.
     */
    private static function countable(NodeAnalysisContext $context, CallExpression $call): bool
    {
        $receiver = $call->receiver;
        $names = $receiver === null ? [] : Types::names($context->analysis->getExpressionType($receiver));
        if ($names === []) {
            return false;
        }

        foreach ($context->codebase->getMultipleClassLikes($names) as $class) {
            $lineage = $class === null ? [] : [$class->name, ...$class->parentInterfaces];
            if (array_intersect(['countable'], $lineage) === []) {
                return false;
            }
        }

        return true;
    }
}
