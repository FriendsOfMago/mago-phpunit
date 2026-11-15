<?php

declare(strict_types=1);

namespace MagoPHPUnit\Linter;

use Mago\Sdk\Linter\LintContext;
use Mago\Sdk\Linter\Rule;
use Mago\Sdk\Linter\RuleDefinition;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\NodeKind;

use function in_array;
use function strtolower;

/**
 * Reports `assertEmpty()` and `assertNotEmpty()`, since PHP's `empty()` rules apply to them.
 *
 * @internal
 */
final class NoAssertEmptyRule implements Rule
{
    public const CODE = 'phpunit/no-assert-empty';

    /**
     * Returns the rule's code, name, level and targets.
     */
    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            self::CODE,
            'No Assert Empty',
            'Reports assertEmpty() and assertNotEmpty(). With them, '
            . "0, '0', '', [], null and false all count as empty, so a stricter assertion is clearer about the expected value.",
            Level::Warning,
            defaultEnabled: false,
            targets: [NodeKind::Method],
        );
    }

    /**
     * Reports each call to `assertEmpty()` or `assertNotEmpty()` in the method.
     */
    public function lint(LintContext $context): void
    {
        foreach (TestMethods::calls($context->file, $context->node, [AssertionNames::ASSERTIONS]) as $call) {
            $name = strtolower($call->name);
            if (!in_array($name, ['assertempty', 'assertnotempty'], strict: true)) {
                continue;
            }

            LintReporter::report(
                $context,
                Issue::new(
                    "With `{$call->name}()`, 0, '0', '', [], null and false all count as empty.",
                    $call->node->span,
                    "Loose assertion `{$call->name}()` is used here.",
                )->withHelp(
                    $name === 'assertempty'
                        ? "Use a stricter assertion, such as `assertSame('', ...)`, `assertCount(0, ...)`, `assertNull()` or `assertFalse()`."
                        : "Use a stricter assertion, such as `assertNotSame('', ...)`, `assertNotCount(0, ...)`, `assertNotNull()` or `assertTrue()`.",
                ),
            );
        }
    }
}
