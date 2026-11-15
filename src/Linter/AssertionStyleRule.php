<?php

declare(strict_types=1);

namespace MagoPHPUnit\Linter;

use Mago\Sdk\Linter\LintContext;
use Mago\Sdk\Linter\Rule;
use Mago\Sdk\Linter\RuleDefinition;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Reporting\Safety;
use Mago\Sdk\Reporting\TextEdit;
use Mago\Sdk\Syntax\NodeKind;

/**
 * Reports PHPUnit assertions in a test method that aren't called in one style: `static::`, `self::` or `$this->`.
 *
 * Mago's extension rules don't take options, so each style is its own rule.
 * Only the `static` one is on by default, matching the default of Mago's
 * built-in rule.
 *
 * @internal
 */
final class AssertionStyleRule implements Rule
{
    /**
     * The call syntax of each style.
     */
    private const SYNTAX = ['static' => 'static::', 'self' => 'self::', 'this' => '$this->'];

    /**
     * @param 'static'|'self'|'this' $style
     */
    public function __construct(
        private readonly string $style,
    ) {}

    /**
     * Returns the rule's code, name, level and targets.
     */
    public function getDefinition(): RuleDefinition
    {
        $syntax = self::SYNTAX[$this->style];

        return new RuleDefinition(
            "phpunit/assertion-style-{$this->style}",
            "Assertion Style ({$syntax})",
            "Reports PHPUnit assertions in a test method that aren't called with `{$syntax}`.",
            Level::Warning,
            defaultEnabled: $this->style === 'static',
            targets: [NodeKind::Method],
        );
    }

    /**
     * Reports each assertion or helper call on `$this`, `static` or `self` in another style.
     */
    public function lint(LintContext $context): void
    {
        $file = $context->file;
        if (!TestMethods::isTest($file, $context->node)) {
            return;
        }

        $desired = self::SYNTAX[$this->style];
        $names = [AssertionNames::ASSERTIONS, AssertionNames::HELPERS];
        foreach (TestMethods::calls($file, $context->node, $names) as $call) {
            // A `parent::` call reaches the parent's method on purpose.
            if ($call->receiver === 'parent' || $call->receiver === $this->style) {
                continue;
            }

            $current = self::SYNTAX[$call->receiver];
            LintReporter::report(
                $context,
                Issue::new(
                    'Inconsistent assertions style.',
                    $call->node->span,
                    "This assertion uses the `{$current}` style.",
                )->withHelp(
                    "Use `{$desired}` instead of `{$current}` to conform to the `{$this->style}` style.",
                )->withEdit(TextEdit::replace($call->receiverSpan, $desired)->withSafety(Safety::PotentiallyUnsafe)),
            );
        }
    }
}
