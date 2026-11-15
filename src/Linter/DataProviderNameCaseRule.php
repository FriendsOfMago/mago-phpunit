<?php

declare(strict_types=1);

namespace MagoPHPUnit\Linter;

use Mago\Sdk\Linter\LintContext;
use Mago\Sdk\Linter\Rule;
use Mago\Sdk\Linter\RuleDefinition;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Reporting\TextEdit;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;
use MagoPHPUnit\Internal\Text\Docblocks;

use function preg_match;
use function rtrim;
use function str_contains;
use function strlen;
use function strtolower;

/**
 * Reports a data provider named in another case than its declaration.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class DataProviderNameCaseRule implements Rule
{
    public const CODE = 'phpunit/data-provider-name-case';

    private const ATTRIBUTE = 'PHPUnit\Framework\Attributes\DataProvider';

    /**
     * Returns the rule's code, name, level and targets.
     */
    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            self::CODE,
            'Data Provider Name Case',
            'Reports a `#[DataProvider]` attribute or `@dataProvider` tag that names a method of the same '
            . 'class in another case than its declaration. PHP finds the method anyway.',
            Level::Warning,
            defaultEnabled: false,
            targets: [NodeKind::Class_, NodeKind::Trait],
        );
    }

    /**
     * Reports each provider name in the class whose case differs from the method's declaration.
     */
    public function lint(LintContext $context): void
    {
        $file = $context->file;
        $methods = self::ownMethods($file, $context->node);
        $declared = [];
        foreach ($methods as $method) {
            $name = TestMethods::nameNode($file, $method);
            if ($name !== null) {
                $declared[strtolower($file->getText($name))] = $file->getText($name);
            }
        }

        foreach ($methods as $method) {
            foreach ([...self::fromAttributes($file, $method), ...self::fromTags($file, $method)] as [
                $written,
                $span,
            ]) {
                $name = $declared[strtolower($written)] ?? null;
                if ($name === null || $name === $written) {
                    continue;
                }

                LintReporter::report(
                    $context,
                    Issue::new(
                        "The data provider `{$written}()` is declared as `{$name}()`.",
                        $span,
                        annotationMessage: 'This name has another case than the declaration.',
                    )->withHelp("Write `{$name}`.")->withEdit(TextEdit::replace($span, $name)),
                );
            }
        }
    }

    /**
     * The name and span of the method name in each `#[DataProvider]` attribute of the method.
     *
     * @return list<array{string, Span}>
     */
    private static function fromAttributes(SourceFile $file, Node $method): array
    {
        $found = [];
        foreach (TestMethods::attributes($file, $method, self::ATTRIBUTE) as $attribute) {
            $literal = self::methodName($file, $attribute);
            if ($literal === null) {
                continue;
            }

            // Only a plain string is read, without escapes or interpolation.
            $text = $file->getText($literal);
            $matches = [];
            if (preg_match('/^([\'"])([A-Za-z_][A-Za-z0-9_]*)\1$/', $text, $matches) === 1) {
                $span = $literal->span;
                $found[] = [$matches[2], new Span($span->start + 1, $span->end - 1)];
            }
        }

        return $found;
    }

    /**
     * Returns the methods declared in the class-like itself.
     *
     * @return list<Node>
     */
    private static function ownMethods(SourceFile $file, Node $classLike): array
    {
        // Only direct members are read. A method of an anonymous class in a
        // method body belongs to that class.
        $methods = [];
        foreach ($file->getChildren($classLike) as $member) {
            $method = $member->kind === NodeKind::ClassLikeMember ? $file->getChildren($member)[0] ?? null : null;
            if ($method?->kind === NodeKind::Method) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /**
     * Returns the string literal given as the attribute's method name, or null.
     */
    private static function methodName(SourceFile $file, Node $attribute): ?Node
    {
        // The method name is the first positional argument or the `methodName` named argument.
        $arguments = $file->getFirstDescendant($attribute, NodeKind::PartialArgumentList);
        foreach ($arguments === null ? [] : $file->getChildren($arguments) as $position => $argument) {
            $inner = $file->getChildren($argument)[0] ?? null;
            $parts = $inner === null ? [] : $file->getChildren($inner);
            $named = $inner?->kind === NodeKind::NamedArgument;
            $label = $named && $parts !== [] ? $file->getText($parts[0]) : null;
            if ($named && $label !== 'methodName') {
                continue;
            }

            if (!$named && ($position !== 0 || $inner?->kind !== NodeKind::PositionalArgument)) {
                return null;
            }

            // A concatenation such as 'Rows' . 'Cases' spans more than its first literal.
            // Null is returned unless the whole value is a string literal.
            $value = $parts[$named ? 1 : 0] ?? null;
            $literal = $value === null ? null : $file->getFirstDescendant($value, NodeKind::LiteralString);
            $whole = $literal?->span->start === $value?->span->start && $literal?->span->end === $value?->span->end;

            return $whole ? $literal : null;
        }

        return null;
    }

    /**
     * The name and span of each `@dataProvider` tag of the method that names a method of the same class.
     *
     * @return list<array{string, Span}>
     */
    private static function fromTags(SourceFile $file, Node $method): array
    {
        $docblock = Docblocks::before($file->contents, $method->span->start);
        $found = [];
        foreach ($docblock === null ? [] : Docblocks::tags($file->contents, $docblock, ['dataProvider']) as $tag) {
            // PHPUnit drops trailing parentheses and whitespace.
            $name = rtrim($tag['value'], characters: " ()\t");
            if ($tag['gap'] === '' || $name === '' || str_contains($name, '::')) {
                continue;
            }

            $start = $tag['span']->end - strlen($tag['value']);
            $found[] = [$name, new Span($start, $start + strlen($name))];
        }

        return $found;
    }
}
