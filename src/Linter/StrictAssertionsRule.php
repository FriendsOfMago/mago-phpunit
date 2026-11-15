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
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

use function in_array;
use function preg_replace;

/**
 * Reports `assertEquals()` and `assertNotEquals()` with a scalar literal, where a strict assertion fits.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final class StrictAssertionsRule implements Rule
{
    public const CODE = 'phpunit/strict-assertions';

    /**
     * The loose assertions, matched with their case as written, the same as in Mago's built-in rule.
     */
    private const LOOSE = ['assertAttributeEquals', 'assertAttributeNotEquals', 'assertEquals', 'assertNotEquals'];

    /**
     * Returns the rule's code, name, level and targets.
     */
    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            self::CODE,
            'Strict Assertions',
            'Reports assertEquals() and assertNotEquals() with an int, float or string literal in a test method. '
            . 'assertSame() and assertNotSame() compare the type too.',
            Level::Warning,
            defaultEnabled: true,
            targets: [NodeKind::Method],
        );
    }

    /**
     * Reports each loose assertion in a test method that compares with a scalar literal.
     */
    public function lint(LintContext $context): void
    {
        $file = $context->file;
        if (!TestMethods::isTest($file, $context->node)) {
            return;
        }

        foreach (TestMethods::calls($file, $context->node, [AssertionNames::ASSERTIONS]) as $call) {
            if (!in_array($call->name, self::LOOSE, strict: true) || $call->argumentList === null) {
                continue;
            }

            // `assertAttributeEquals()` takes the expected value second.
            $scalar =
                $call->name === 'assertAttributeEquals' || $call->name === 'assertAttributeNotEquals'
                    ? self::isScalarLiteral($file, $call->arguments[1] ?? null)
                    : self::isScalarLiteral($file, $call->arguments[0] ?? null)
                    || self::isScalarLiteral($file, $call->arguments[1] ?? null);
            if (!$scalar) {
                continue;
            }

            $strict = (string) preg_replace('/Equals/', replacement: 'Same', subject: $call->name, limit: 1);
            LintReporter::report(
                $context,
                Issue::new(
                    'Use strict assertions in PHPUnit tests.',
                    $call->node->span,
                    "Non-strict assertion `{$call->name}` is used here.",
                )->withHelp(
                    "Replace `{$call->name}` with `{$strict}` to compare the type too.",
                )->withEdit(TextEdit::replace($call->selector->span, $strict)->withSafety(Safety::PotentiallyUnsafe)),
            );
        }
    }

    /**
     * Whether the expression is an int, float or string literal, or an interpolated string.
     */
    private static function isScalarLiteral(SourceFile $file, ?Node $expression): bool
    {
        $inner = $expression === null ? null : $file->getChildren($expression)[0] ?? null;
        if ($inner?->kind === NodeKind::CompositeString) {
            return true;
        }

        $literal = $inner?->kind === NodeKind::Literal ? $file->getChildren($inner)[0] ?? null : null;

        return in_array(
            $literal?->kind,
            [NodeKind::LiteralInteger, NodeKind::LiteralFloat, NodeKind::LiteralString],
            strict: true,
        );
    }
}
