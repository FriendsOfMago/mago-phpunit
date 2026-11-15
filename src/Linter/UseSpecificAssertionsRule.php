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
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

use function in_array;
use function str_contains;
use function strtolower;

/**
 * Reports `assertSame()` and `assertEquals()` against `null`, `true` or `false`, where a dedicated assertion fits.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class UseSpecificAssertionsRule implements Rule
{
    public const CODE = 'phpunit/use-specific-assertions';

    /**
     * The comparing assertions, matched with their case as written, the same as in Mago's built-in rule.
     */
    private const COMPARING = ['assertEquals', 'assertNotEquals', 'assertSame', 'assertNotSame'];

    /**
     * The loose ones among them.
     */
    private const LOOSE = ['assertEquals', 'assertNotEquals'];

    /**
     * Returns the rule's code, name, level and targets.
     */
    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            self::CODE,
            'Use Specific Assertions',
            'Reports assertSame(), assertEquals() and their negations against null, true or false in a test method. '
            . 'With assertNull(), assertTrue() and assertFalse(), the check is clear from the name, and a failure '
            . 'gets a clearer message.',
            Level::Warning,
            defaultEnabled: true,
            targets: [NodeKind::Method],
        );
    }

    /**
     * Reports each comparing assertion in a test method that has a dedicated replacement.
     */
    public function lint(LintContext $context): void
    {
        $file = $context->file;
        if (!TestMethods::isTest($file, $context->node)) {
            return;
        }

        foreach (TestMethods::calls($file, $context->node, [AssertionNames::ASSERTIONS]) as $call) {
            [$first, $second] = [$call->arguments[0] ?? null, $call->arguments[1] ?? null];
            $list = $call->argumentList?->span;
            if (!in_array($call->name, self::COMPARING, strict: true) || $list === null) {
                continue;
            }

            if ($first === null || $second === null) {
                continue;
            }

            $replacement = self::replacement($file, $call->name, $first, $second);
            if ($replacement === null) {
                continue;
            }

            // The literal and its adjacent comma are removed. The other arguments are
            // kept as written. Calls with a named or unpacked argument aren't fixed.
            // A name may not match any parameter of the replacement assertion.
            // The `...` of an unpacked argument would be lost.
            $drop = match (true) {
                !$call->plain => null,
                $replacement[1] => TextEdit::replace(new Span($list->start, $second->span->start), text: '('),
                default => TextEdit::delete(new Span($first->span->end, $second->span->end)),
            };
            self::report($context, $call, $replacement[0], $drop);
        }
    }

    /**
     * Returns the dedicated assertion, and whether the literal is the first argument, or null.
     *
     * The first argument is checked before the second. `true` and `false`
     * only have a replacement for the positive assertions.
     *
     * @return array{string, bool}|null
     */
    private static function replacement(SourceFile $file, string $name, Node $first, Node $second): ?array
    {
        $negated = str_contains($name, 'Not');
        foreach ([[$first, true], [$second, false]] as [$argument, $isFirst]) {
            $replacement = match (self::literal($file, $argument)) {
                'null' => $negated ? 'assertNotNull' : 'assertNull',
                'true' => $negated ? null : 'assertTrue',
                'false' => $negated ? null : 'assertFalse',
                default => null,
            };
            if ($replacement !== null) {
                return [$replacement, $isFirst];
            }
        }

        return null;
    }

    /**
     * Reports the call, with the edit that renames it and the edit that drops the literal argument, when there is one.
     */
    private static function report(
        LintContext $context,
        AssertionCall $call,
        string $replacement,
        ?TextEdit $drop,
    ): void {
        $loose = in_array($call->name, self::LOOSE, strict: true);
        $safety = $loose ? Safety::PotentiallyUnsafe : Safety::Safe;

        $issue = Issue::new(
            "Use `{$replacement}` instead of `{$call->name}` for clearer test assertions.",
            $call->node->span,
            "This can be simplified to `{$replacement}`.",
        )->withHelp("Replace `{$call->name}(...)` with `{$replacement}(...)` for a more specific assertion.");
        if ($drop !== null) {
            $issue = $issue
                ->withEdit(TextEdit::replace($call->selector->span, $replacement)->withSafety($safety))
                ->withEdit($drop->withSafety($safety));
        }

        if ($loose) {
            $issue = $issue
                ->withNote("`{$call->name}` compares loosely, and `{$replacement}` compares strictly.")
                ->withNote('Check that the test still passes after the change.');
        }

        LintReporter::report($context, $issue);
    }

    /**
     * Returns `null`, `true` or `false` for those literals, `other` for any other literal, or null.
     *
     * @return 'null'|'true'|'false'|'other'|null
     */
    private static function literal(SourceFile $file, Node $expression): ?string
    {
        $inner = $file->getChildren($expression)[0] ?? null;
        if ($inner?->kind !== NodeKind::Literal) {
            return null;
        }

        $value = $file->getChildren($inner)[0] ?? null;
        if ($value?->kind !== NodeKind::Keyword) {
            return 'other';
        }

        return match (strtolower($file->getText($value))) {
            'null' => 'null',
            'true' => 'true',
            'false' => 'false',
            default => 'other',
        };
    }
}
