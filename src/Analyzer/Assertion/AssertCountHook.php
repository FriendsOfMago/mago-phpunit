<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Assertion;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\EnumType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\CallExpression;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Internal\Type\Types;
use Throwable;

use function array_merge;
use function count;
use function in_array;
use function ltrim;
use function str_ends_with;
use function stripos;
use function strtolower;

/**
 * Reports `assertSame($n, count($value))` and `assertSame($n, $value->count())`.
 *
 * The `assertSame()` function is sent here by AssertionFunctionHook.
 *
 * @internal
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
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
        // `assertCount()`. An unpacked argument may hold a mode.
        $name = strtolower(ltrim($counted->getName($source) ?? '', characters: '\\'));
        $argument = $counted->arguments[0] ?? null;
        $form = match (true) {
            $counted->isFunction()
                && count($counted->arguments) === 1
                && $argument?->unpacked === false
                && self::isGlobalCount($context, $counted)
                => 'count($value)',
            $counted->isMethod()
                && $name === 'count'
                && $counted->arguments === []
                && self::countable($context, $counted)
                => '$value->count()',
            default => null,
        };
        if ($form === null || !self::expectsInt($context, $call)) {
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
     * Whether the expected value is an int, as `assertCount()` takes.
     * `assertSame('2', count($value))` or a float fails either way.
     */
    private static function expectsInt(NodeAnalysisContext $context, CallExpression $call): bool
    {
        $expected = $call->arguments[0] ?? null;
        if ($expected === null || $expected->name !== null || $expected->unpacked) {
            return false;
        }

        // The SDK throws for a type without any member, as in AssertionFunctionHook.
        try {
            $type = $context->analysis->getExpressionType($expected->value);
        } catch (Throwable) {
            return false;
        }

        foreach ($type->atomicTypes ?? [] as $atomic) {
            if (!$atomic instanceof ScalarType || $atomic->kind !== ScalarTypeKind::Integer) {
                return false;
            }
        }

        return $type !== null;
    }

    /**
     * Whether the function called is PHP's `count()`.
     *
     * An unqualified `count()` in a namespace is PHP's, unless the namespace
     * has its own `count()` function. A `count()` imported with
     * `use function` from another namespace isn't PHP's.
     */
    private static function isGlobalCount(NodeAnalysisContext $context, CallExpression $call): bool
    {
        $source = $context->source;
        $written = strtolower($call->getName($source) ?? '');
        $name = $source->getResolvedName($call->node);
        $resolved = strtolower(ltrim($name->name ?? '', characters: '\\'));
        if ($name === null || $resolved === 'count') {
            return $resolved === 'count';
        }

        return (
            $written === 'count'
            && !$name->imported
            && str_ends_with($resolved, '\\count')
            && !$context->codebase->functionExists($resolved)
        );
    }

    /**
     * Whether the receiver of `->count()` is Countable.
     *
     * Each part of a union must be, so a nullable value isn't reported. An
     * intersection is when one of its classes is, such as `Countable` in
     * `MockObject&Countable&Clock`. An enum can be Countable too.
     */
    private static function countable(NodeAnalysisContext $context, CallExpression $call): bool
    {
        $receiver = $call->receiver;

        // The SDK throws for a type without any member, as in AssertionFunctionHook.
        try {
            $type = $receiver === null ? null : $context->analysis->getExpressionType($receiver);
        } catch (Throwable) {
            return false;
        }

        $parts = [];
        foreach ($type->atomicTypes ?? [] as $atomic) {
            $names = $atomic instanceof EnumType ? [$atomic->name] : Types::names(Type::fromAtomic($atomic));
            if ($names === []) {
                return false;
            }

            $parts[] = $names;
        }

        if ($parts === []) {
            return false;
        }

        $countable = [];
        $names = array_merge(...$parts);
        foreach ($context->codebase->getMultipleClassLikes($names) as $position => $class) {
            $lineage = $class === null ? [] : [$class->name, ...$class->parentInterfaces];
            $countable[strtolower($names[$position])] = in_array('countable', $lineage, strict: true);
        }

        foreach ($parts as $part) {
            $found = false;
            foreach ($part as $name) {
                $found = $found || ($countable[strtolower($name)] ?? false);
            }

            if (!$found) {
                return false;
            }
        }

        return true;
    }
}
