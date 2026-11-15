<?php

declare(strict_types=1);

namespace MagoPHPUnit\Linter;

use Mago\Sdk\Span;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;
use MagoPHPUnit\Internal\Text\CodeMask;
use MagoPHPUnit\Internal\Text\Docblocks;

use function array_key_exists;
use function array_pop;
use function count;
use function ctype_upper;
use function in_array;
use function str_starts_with;
use function strcasecmp;
use function strlen;
use function strpos;
use function strtolower;
use function substr;

/**
 * Finds test methods and the assertion calls in their bodies.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class TestMethods
{
    public const TEST_ATTRIBUTE = 'PHPUnit\Framework\Attributes\Test';

    /**
     * The nodes whose bodies don't belong to the method.
     */
    private const SKIPPED = [
        NodeKind::Closure,
        NodeKind::ArrowFunction,
        NodeKind::CompositeString,
        NodeKind::Function,
        NodeKind::Class_,
        NodeKind::Interface,
        NodeKind::Trait,
        NodeKind::Enum,
    ];

    private function __construct() {}

    /**
     * Returns the method's name node.
     */
    public static function nameNode(SourceFile $file, Node $method): ?Node
    {
        foreach ($file->getChildren($method) as $child) {
            if ($child->kind === NodeKind::LocalIdentifier) {
                return $child;
            }
        }

        return null;
    }

    /**
     * Whether the method is a test: it has a test name, the `#[Test]` attribute or a `@test` tag.
     *
     * The `@test` tag counts on every PHPUnit version, since the linter
     * doesn't know which one is installed. PHPUnit 12 ignores the tag.
     */
    public static function isTest(SourceFile $file, Node $method): bool
    {
        $name = self::nameNode($file, $method);
        if ($name === null) {
            return false;
        }

        if (self::isTestName($file->getText($name)) || self::attributes($file, $method, self::TEST_ATTRIBUTE) !== []) {
            return true;
        }

        $docblock = Docblocks::before($file->contents, $method->span->start);

        return $docblock !== null && Docblocks::tags($file->contents, $docblock, ['test']) !== [];
    }

    /**
     * Whether the name is a test name: `test` followed by `_` or an uppercase letter.
     *
     * A method named `test` alone, or `testing`, doesn't count, the same as
     * in Mago's built-in PHPUnit rules.
     */
    private static function isTestName(string $name): bool
    {
        if (!str_starts_with($name, 'test') || strlen($name) < 5) {
            return false;
        }

        return $name[4] === '_' || ctype_upper($name[4]);
    }

    /**
     * The attributes of the method that resolve to the given class.
     *
     * @return list<Node>
     */
    public static function attributes(SourceFile $file, Node $method, string $class): array
    {
        $found = [];
        foreach ($file->getChildren($method) as $list) {
            if ($list->kind !== NodeKind::AttributeList) {
                continue;
            }

            foreach ($file->getChildren($list) as $attribute) {
                $resolved = $file->getResolvedName($attribute)?->name;
                if ($resolved === null || strcasecmp($resolved, $class) !== 0) {
                    continue;
                }

                $found[] = $attribute;
            }
        }

        return $found;
    }

    /**
     * Returns the calls in the method body to a method in one of the name lists.
     *
     * Closures, arrow functions and anonymous class bodies are skipped, since
     * `$this` there isn't the test case. The constructor arguments of an
     * anonymous class are still searched. Interpolated strings are skipped
     * too, the same as in Mago's built-in rules, since `static::` can't be
     * written inside `{...}`.
     *
     * @param list<array<string, true>> $names Lowercased method names.
     *
     * @return list<AssertionCall>
     */
    public static function calls(SourceFile $file, Node $method, array $names): array
    {
        $body = $file->getFirstDescendant($method, NodeKind::MethodBody);
        if ($body === null) {
            return [];
        }

        $calls = [];
        $stack = [$body];
        while (($node = array_pop($stack)) !== null) {
            if (in_array($node->kind, self::SKIPPED, strict: true)) {
                continue;
            }

            $children = $file->getChildren($node);
            if ($node->kind === NodeKind::AnonymousClass) {
                $children = [];
                foreach ($file->getChildren($node) as $child) {
                    if ($child->kind !== NodeKind::ArgumentList && $child->kind !== NodeKind::PartialArgumentList) {
                        continue;
                    }

                    $children[] = $child;
                }
            }

            for ($index = count($children) - 1; $index >= 0; --$index) {
                $stack[] = $children[$index];
            }

            // PHP 8.1 doesn't allow `->value` on an enum case in a constant, so the kinds are matched here.
            $operator = match ($node->kind) {
                NodeKind::MethodCall, NodeKind::MethodPartialApplication => '->',
                NodeKind::StaticMethodCall, NodeKind::StaticMethodPartialApplication => '::',
                default => null,
            };
            if ($operator === null) {
                continue;
            }

            $call = self::call($file, $node, $operator, $names);
            if ($call !== null) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    /**
     * Returns the call when its receiver and name match, or null.
     *
     * @param '->'|'::' $operator
     * @param list<array<string, true>> $names
     */
    private static function call(SourceFile $file, Node $node, string $operator, array $names): ?AssertionCall
    {
        $children = $file->getChildren($node);
        [$receiverNode, $selector, $argumentList] = [$children[0] ?? null, $children[1] ?? null, $children[2] ?? null];
        if ($receiverNode === null || $selector === null) {
            return null;
        }

        $receiver = self::receiver($file, $receiverNode);
        $identifier = $file->getChildren($selector)[0] ?? null;
        if ($receiver === null || $identifier === null || $identifier->kind !== NodeKind::LocalIdentifier) {
            return null;
        }

        $name = $file->getText($identifier);
        $lowercase = strtolower($name);
        $known = false;
        foreach ($names as $list) {
            $known = $known || array_key_exists($lowercase, $list);
        }

        if (!$known) {
            return null;
        }

        // The operator isn't a node, so it's found in the text between the
        // receiver and the method name. Comments there are masked, since
        // one can hold `->` or `::` too.
        $gap = $receiverNode->span->end;
        $at = strpos(CodeMask::fragment(substr($file->contents, $gap, $selector->span->start - $gap)), $operator);
        if ($at === false) {
            return null;
        }

        $at += $gap;
        $list = $argumentList?->kind === NodeKind::ArgumentList ? $argumentList : null;

        return new AssertionCall(
            $node,
            $name,
            $selector,
            $receiver,
            new Span($receiverNode->span->start, $at + strlen($operator)),
            $list,
            $list === null ? [] : self::arguments($file, $list),
            $list !== null && self::plain($file, $list),
        );
    }

    /**
     * Returns `this`, `static`, `self` or `parent` for those receivers, or null.
     *
     * @return 'this'|'static'|'self'|'parent'|null
     */
    private static function receiver(SourceFile $file, Node $expression): ?string
    {
        $inner = $file->getChildren($expression)[0] ?? null;
        if ($inner?->kind === NodeKind::Keyword) {
            return match (strtolower($file->getText($inner))) {
                'static' => 'static',
                'self' => 'self',
                'parent' => 'parent',
                default => null,
            };
        }

        if ($inner?->kind !== NodeKind::Variable) {
            return null;
        }

        $variable = $file->getChildren($inner)[0] ?? null;

        return $variable?->kind === NodeKind::DirectVariable && $file->getText($variable) === '$this' ? 'this' : null;
    }

    /**
     * Whether every argument in the list is positional and not unpacked.
     *
     * An unpacked argument has `...` before its value, so the argument
     * starts before the value.
     */
    private static function plain(SourceFile $file, Node $argumentList): bool
    {
        foreach ($file->getChildren($argumentList) as $argument) {
            $inner = $file->getChildren($argument)[0] ?? null;
            $value = $inner === null ? null : $file->getFirstDescendant($inner, NodeKind::Expression);
            if ($inner?->kind !== NodeKind::PositionalArgument || $value?->span->start !== $inner->span->start) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns the value expression of each argument, in order.
     *
     * @return list<Node>
     */
    private static function arguments(SourceFile $file, Node $argumentList): array
    {
        $values = [];
        foreach ($file->getChildren($argumentList) as $argument) {
            $inner = $file->getChildren($argument)[0] ?? null;
            if ($inner === null) {
                continue;
            }

            $value = null;
            foreach ($file->getChildren($inner) as $part) {
                if ($part->kind !== NodeKind::Expression) {
                    continue;
                }

                $value = $part;
            }

            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
