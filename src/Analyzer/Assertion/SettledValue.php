<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Assertion;

use Closure;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;
use MagoPHPUnit\Internal\Declaration\FileMembers;
use MagoPHPUnit\Internal\Text\CodeMask;

use function count;
use function in_array;
use function preg_match;
use function preg_quote;
use function strlen;
use function substr;

/**
 * Checks whether Mago's type for an asserted value still holds at the assertion.
 *
 * @internal
 * @mago-expect lint:cyclomatic-complexity
 */
final class SettledValue
{
    /**
     * Nodes that only hold the expression below them.
     */
    private const WRAPPERS = [NodeKind::Expression, NodeKind::Access, NodeKind::Construct, NodeKind::Parenthesized];

    private function __construct() {}

    /**
     * Whether the value's type can be trusted at the assertion.
     *
     * @param Node $value The argument's value.
     * @param Closure(Type): bool $passes Whether a type passes the assertion.
     */
    public static function holds(NodeAnalysisContext $context, Node $value, Closure $passes): bool
    {
        $value = self::unwrap($context->source, $value);
        $node = self::withoutNegation($context->source, $value);

        return match ($node->kind) {
            // `isset()` calls `__isset()` or `ArrayAccess::offsetExists()`, and
            // neither is described by a type. An offset read isn't trusted for the same
            // reason. Mago's `ArrayAccess` stub types `offsetGet()` without
            // null, but an empty field item list returns null for `$items[0]`.
            NodeKind::IssetConstruct, NodeKind::StaticPropertyAccess, NodeKind::ArrayAccess => false,
            // Mago keeps a property's narrowed type across calls that may
            // change it. For example, a test sets `$this->fired = FALSE`, runs
            // code whose callback sets it, and asserts it. PHPStan drops the
            // narrowing after such a call. So a property counts only when its
            // declared type passes too. A negation can't be checked against a
            // declared type, so a negated property doesn't count.
            NodeKind::PropertyAccess, NodeKind::NullSafePropertyAccess => $node->id === $value->id
                && self::declared($context, $node, $passes),
            NodeKind::Variable => !self::shared($context->source, $node)
                && !self::referenced($context, $node)
                && !self::documented($context, $node),
            default => true,
        };
    }

    /**
     * Returns the operand of a `!`, so `!isset()` gets the same result as `isset()`.
     */
    private static function withoutNegation(SourceFile $source, Node $node): Node
    {
        $children = $source->getChildren($node);
        if ($node->kind !== NodeKind::UnaryPrefix || count($children) !== 2 || $source->getText($children[0]) !== '!') {
            return $node;
        }

        return self::unwrap($source, $children[1]);
    }

    /**
     * Whether the variable is declared by a `static` or `global` statement in the file.
     *
     * Such a variable keeps its value from an earlier call or changes
     * elsewhere, and Mago doesn't track that. Any such statement in the file
     * counts, not only one in the enclosing function. A match in the raw text
     * is checked again on the code from CodeMask, so `global $name` in a
     * comment or a string doesn't count.
     */
    private static function shared(SourceFile $source, Node $variable): bool
    {
        $name = preg_quote($source->getText($variable), delimiter: '/');
        $pattern = '/\b(?:static|global)\s+(?:\$\w+[^;,]*,\s*)*' . $name . '\b/';

        return (
            preg_match($pattern, $source->contents) === 1
            && preg_match($pattern, CodeMask::code($source->contents)) === 1
        );
    }

    /**
     * Whether the variable may be bound by reference in the enclosing method or file.
     */
    private static function referenced(NodeAnalysisContext $context, Node $variable): bool
    {
        // A reference can be written as `$alias = &$name`, `$name = &$base` or `use (&$name)`. Mago
        // keeps the variable's type after a change through an alias. Outside a method, the whole
        // file is searched.
        $source = $context->source;
        $span = self::method($context, $variable) ?? new Span(0, strlen($source->contents));
        $code = substr(CodeMask::code($source->contents), $span->start, $span->length());
        $name = preg_quote($source->getText($variable), delimiter: '/');

        // Comments and strings are blanked by CodeMask. A reference inside them doesn't count.
        // A bitwise `&` before the variable also matches. Only a report is skipped in that case.
        // A logical `&&` doesn't match.
        return preg_match('/(?<!&)&(?!&)\s*' . $name . '\b|' . $name . '\s*=\s*&(?!&)/', $code) === 1;
    }

    /**
     * Whether the property's declared native type passes.
     *
     * A `@var` tag isn't enforced, so it doesn't count. An untyped property is
     * null until it is assigned, so it doesn't count either. A magic property
     * doesn't have a declaration, so it never counts.
     *
     * Only `$this->name` is looked up, on the enclosing class. The hook gets
     * the types of the call's arguments, not of the expressions inside them,
     * so other receivers don't have a known class. In a trait, the enclosing
     * class is the trait, which rarely declares the property.
     *
     * @param Closure(Type): bool $passes
     */
    private static function declared(NodeAnalysisContext $context, Node $node, Closure $passes): bool
    {
        $source = $context->source;
        $children = $source->getChildren($node);
        $count = count($children);
        if ($count < 2 || $source->getText($children[0]) !== '$this') {
            return false;
        }

        // A dynamic name such as `$this->$name` is not a declared property.
        $name = $source->getText($children[$count - 1]);
        $class = FileMembers::of($source)->classAt($context->codebase, $source, $node->span);
        if ($class === null || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            return false;
        }

        // Mago names properties with their dollar sign.
        $declared = $context->codebase->getDeclaringProperty($class->name, '$' . $name)?->declaredType?->type;

        return $declared !== null && $passes($declared);
    }

    /**
     * Whether the variable is documented by an inline `@var` tag in the enclosing method or file.
     */
    private static function documented(NodeAnalysisContext $context, Node $variable): bool
    {
        // The tag is the test's own claim. The claim is often about the result of a `load()` call,
        // which may be NULL. At runtime, the claim is checked by the assertion. Variable names can
        // be the same in different methods. Only the enclosing method is searched. Outside a
        // method, the whole file is searched.
        $source = $context->source;
        $span = self::method($context, $variable);
        $text = $span === null ? $source->contents : $source->getText($span);
        $name = preg_quote($source->getText($variable), delimiter: '/');
        // The tag can have a `phpstan-` or `psalm-` prefix. Spaces are allowed in its type,
        // as in `array<string, mixed>`. An unnamed tag counts only right before an assignment
        // to the variable, such as `$thing = ...`.
        $named = '[^\n]*?' . $name . '\b';
        $unnamed = '(?:(?!\*\/)[^$])*\*\/\s*' . $name . '\s*=(?!=)';

        return preg_match('/@(?:phpstan-|psalm-)?var\s(?:' . $named . '|' . $unnamed . ')/', $text) === 1;
    }

    /**
     * The span of the method around the variable, or null outside a class or when the method's location isn't known.
     */
    private static function method(NodeAnalysisContext $context, Node $variable): ?Span
    {
        $source = $context->source;
        $members = FileMembers::of($source);
        $class = $members->classAt($context->codebase, $source, $variable->span);

        return $class === null
            ? null
            : $members->methodAt($context->codebase, $class, $variable->span)?->location?->span;
    }

    /**
     * Returns the expression inside wrapper nodes such as parentheses.
     */
    private static function unwrap(SourceFile $source, Node $node): Node
    {
        while (in_array($node->kind, self::WRAPPERS, strict: true)) {
            $children = $source->getChildren($node);
            if (count($children) !== 1) {
                break;
            }

            $node = $children[0];
        }

        return $node;
    }
}
