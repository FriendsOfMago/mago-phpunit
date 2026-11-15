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

use function ctype_upper;
use function lcfirst;
use function str_starts_with;
use function strrpos;
use function strspn;
use function strtolower;
use function substr;

/**
 * Reports a public method named `test*` instead of having the `#[Test]` attribute.
 *
 * @internal
 */
final class PreferTestAttributeRule implements Rule
{
    public const CODE = 'phpunit/prefer-test-attribute';

    /**
     * Returns the rule's code, name, level and targets.
     */
    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            self::CODE,
            'Prefer Test Attribute',
            'Reports a test method named with a `test` prefix instead of having the `#[Test]` attribute, '
            . 'supported by PHPUnit 10 and later.',
            Level::Warning,
            defaultEnabled: false,
            targets: [NodeKind::Method],
        );
    }

    /**
     * Reports the method when its name starts with `test` and it doesn't have the attribute.
     */
    public function lint(LintContext $context): void
    {
        $file = $context->file;
        $nameNode = TestMethods::nameNode($file, $context->node);
        if ($nameNode === null) {
            return;
        }

        $name = $file->getText($nameNode);
        // PHPUnit only accepts a lowercase `test` prefix. `TestFoo()` isn't a test.
        // Mago's built-in rule also reports `TestFoo()`.
        if (!str_starts_with($name, 'test')) {
            return;
        }

        $rest = substr($name, offset: 4);
        if ($rest !== '' && $rest[0] !== '_' && !ctype_upper($rest[0])) {
            return;
        }

        // PHPUnit only runs public methods as tests, even with `#[Test]`.
        // Visibility isn't checked in Mago's built-in rule.
        if (
            TestMethods::attributes($file, $context->node, TestMethods::TEST_ATTRIBUTE) !== []
            || !self::isPublic($file, $context->node)
        ) {
            return;
        }

        $newName = self::newName($name);
        $method = $context->node->span;
        $lineStart = strrpos(substr($file->contents, offset: 0, length: $method->start), needle: "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $indent = substr($file->contents, $lineStart, strspn($file->contents, characters: " \t", offset: $lineStart));

        LintReporter::report(
            $context,
            Issue::new(
                "Use `#[Test]` attribute instead of `test` prefix on method `{$name}`.",
                $nameNode->span,
                "Method `{$name}` uses the `test` prefix.",
            )
                ->withHelp("Rename to `{$newName}` and add `#[Test]` attribute.")
                ->withEdit(TextEdit::replace($nameNode->span, $newName)->withSafety(Safety::PotentiallyUnsafe))
                ->withEdit(TextEdit::insert(
                    $method->start,
                    "#[\\" . TestMethods::TEST_ATTRIBUTE . "]\n{$indent}",
                )->withSafety(Safety::PotentiallyUnsafe)),
        );
    }

    /**
     * Whether the method doesn't have a `private` or `protected` modifier.
     */
    private static function isPublic(SourceFile $file, Node $method): bool
    {
        foreach ($file->getChildren($method) as $modifier) {
            if ($modifier->kind !== NodeKind::Modifier) {
                continue;
            }

            $visibility = strtolower($file->getText($modifier));
            if ($visibility === 'private' || $visibility === 'protected') {
                return false;
            }
        }

        return true;
    }

    /**
     * The method name after removing a nonempty `test` prefix or `test_` prefix.
     */
    private static function newName(string $name): string
    {
        // For `testFoo` and `test_foo`, the result is `foo`. The names `test` and `test_` are kept
        // as written.
        $rest = substr($name, offset: 4);
        if ($rest === '' || $rest === '_') {
            return $name;
        }

        if ($rest[0] === '_') {
            return substr($rest, offset: 1);
        }

        return lcfirst($rest);
    }
}
