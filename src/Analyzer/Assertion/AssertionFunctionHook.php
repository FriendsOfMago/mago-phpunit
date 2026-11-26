<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Assertion;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\NodeKind;
use Throwable;

use function array_keys;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

/**
 * Checks calls to PHPUnit's global assertion functions.
 *
 * A call that always passes, such as `assertNotNull('a')`, goes to
 * RedundantAssertionHook. A call to `assertSame()`, also under another name
 * from `use function ... as`, goes to AssertCountHook.
 *
 * @internal
 */
final class AssertionFunctionHook implements NodeAnalysisHook
{
    private const NAMESPACE = 'phpunit\framework\\';

    /**
     * @var array<lowercase-string, RedundantAssertionHook>
     */
    private array $hooks = [];

    private AssertCountHook $count;

    public function __construct()
    {
        $this->count = new AssertCountHook();
        foreach (array_keys(RedundantAssertionHook::METHODS) as $method) {
            $this->hooks[strtolower($method)] = new RedundantAssertionHook($method);
        }
    }

    /**
     * Targets every function call.
     *
     * Mago doesn't match function calls by name for a node analysis hook, so
     * the calls to other functions are skipped in analyze().
     */
    public function getTargets(): array
    {
        return [NodeKind::FunctionCall];
    }

    /**
     * Asks for the call's subtree and the file's text.
     *
     * The argument types are fetched in analyze(), and only for calls to
     * PHPUnit's functions.
     */
    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::TargetSubtree, FileAnalysisRequirement::SourceText];
    }

    /**
     * Passes a call to a PHPUnit assertion function to the hook that checks it.
     */
    public function analyze(NodeAnalysisContext $context): void
    {
        // Resolved names are keyed by their start offset, which is also the
        // call's start. So the name is found from the call node, and most
        // calls are skipped before their syntax is parsed.
        $name = strtolower($context->source->getResolvedName($context->node)->name ?? '');
        if ($name === self::NAMESPACE . 'assertsame') {
            $this->count->analyze($context);

            return;
        }

        $hook = str_starts_with($name, self::NAMESPACE)
            ? $this->hooks[substr($name, strlen(self::NAMESPACE))] ?? null
            : null;
        if ($hook === null) {
            return;
        }

        try {
            $call = CallExpression::fromNode($context->source, $context->node);
        } catch (Throwable) {
            return;
        }

        if ($call->arguments === []) {
            return;
        }

        $values = [];
        foreach ($call->arguments as $argument) {
            $values[] = $argument->value;
        }

        // The SDK throws for an argument type without any member. Skip the
        // call then, so the rest of the file is still checked.
        try {
            $types = $context->analysis->getMultipleExpressionTypes($values);
        } catch (Throwable) {
            return;
        }

        $hook->check($context, $types, $call);
    }
}
