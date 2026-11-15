<?php

declare(strict_types=1);

namespace MagoPHPUnit\Internal\Declaration;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Span;
use MagoPHPUnit\Internal\Cache\FileMemo;
use MagoPHPUnit\Internal\Text\PhpTokens;
use PhpToken;

use function count;
use function preg_match;

/**
 * The byte range and full name of each class-like in one file.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final class ClassLikeRanges
{
    /**
     * A regex fragment for the whitespace and comments allowed by PHP
     * between two tokens, such as a callee and its parenthesis.
     *
     * A `//` or `#` comment ends at its line break or at a `?>`, which ends
     * PHP mode. `#[` opens an attribute, not a comment. `/` and `#` are
     * escaped, so the fragment works in a pattern with either delimiter. The
     * quantifiers are possessive, so a pattern that fails after the fragment
     * doesn't backtrack into it.
     */
    public const GAP = '(?:\s++|\/\*(?:[^*]++|\*(?!\/))*+\*\/|(?:\/\/|\#(?!\[))(?:[^\r\n?]++|\?(?!>))*+)*+';

    private const CLASS_LIKES = [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM];

    /**
     * A class-like declaration where a statement can start: at the start of
     * a line, or after a `;`, a brace, an attribute's `]` or the open tag.
     * Comments are allowed before it and between its tokens.
     */
    private const DECLARES =
        '/(?:^|[;{}\]]|<\?php)'
            . self::GAP
            . '(?:(?:abstract|final|readonly)(?![\w\x80-\xff])'
            . self::GAP
            . ')*+(?:class|interface|trait|enum)(?![\w\x80-\xff])'
            . self::GAP
            . '[A-Za-z_\x80-\xff]/mi';

    /**
     * `new class`, with any attributes or `readonly` before `class`.
     */
    private const ANONYMOUS = '/\bnew(?![\w\x80-\xff])' . self::GAP . '(?:#\[|(?:readonly|class)(?![\w\x80-\xff]))/i';

    /**
     * @var FileMemo<self>|null
     */
    private static ?FileMemo $memo = null;

    /**
     * @var FileMemo<bool>|null
     */
    private static ?FileMemo $declares = null;

    /**
     * @param list<array{int, int, ?string}> $ranges Start and end offsets and
     *   the full name, in source order. The name is null for an anonymous
     *   class, so a method inside one doesn't belong to a named class-like.
     */
    private function __construct(
        private readonly array $ranges,
    ) {}

    /**
     * The class-like declared around the span, found from the file's tokens.
     *
     * Returns null when there isn't one, or when it's not in the codebase.
     */
    public static function classAt(Codebase $codebase, string $contents, Span $span): ?ClassLikeMetadata
    {
        // Most procedural files don't declare anything. The regex check
        // skips tokenizing them.
        if (!self::mayDeclare($contents)) {
            return null;
        }

        $name = self::of($contents)->at($span->start);

        return $name === null ? null : $codebase->getClassLike($name);
    }

    /**
     * Whether the text can declare a named class-like.
     *
     * A comment or a string that looks like a declaration passes too. That
     * only costs a tokenizer run. A method-call hook calls this once per
     * call, and a procedural file can hold hundreds of calls, so the result
     * is cached for the last file.
     */
    public static function mayDeclare(string $contents): bool
    {
        self::$declares ??= new FileMemo();
        $memo = self::$declares;

        return $memo->has($contents)
            ? $memo->value()
            : $memo->keep($contents, preg_match(self::DECLARES, $contents) === 1);
    }

    /**
     * Whether the text can hold an anonymous class, checked before the file
     * is tokenized for one.
     */
    public static function mayHoldAnonymous(string $contents): bool
    {
        return preg_match(self::ANONYMOUS, $contents) === 1;
    }

    /**
     * Whether the innermost class-like around the span is an anonymous class.
     */
    public static function inAnonymous(string $contents, Span $span): bool
    {
        $innermost = self::of($contents)->innermost($span->start);

        return $innermost !== null && $innermost[2] === null;
    }

    /**
     * The ranges for the text, cached for the last text.
     */
    private static function of(string $contents): self
    {
        self::$memo ??= new FileMemo();

        return self::$memo->get($contents, static fn(): self => self::scan(PhpTokens::of($contents)));
    }

    /**
     * Finds the range and full name of every class-like in the tokens.
     *
     * @param list<PhpToken> $tokens
     */
    public static function scan(array $tokens): self
    {
        $ranges = [];
        $namespace = '';
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token->is(T_NAMESPACE)) {
                $namespace = self::namespaceAt($tokens, $i);
                continue;
            }

            // An attribute argument can be named `class:`, which doesn't
            // declare anything.
            if ($token->is(T_ATTRIBUTE)) {
                $i = PhpTokens::closer($tokens, index: $i, open: '[', close: ']');
                continue;
            }

            // `Foo::class` is a constant, not a declaration, and neither is a
            // `class:` named argument.
            $name = PhpTokens::next($tokens, $i + 1);
            if (
                !$token->is(self::CLASS_LIKES)
                || $tokens[PhpTokens::previous($tokens, $i - 1) ?? $i]->is(T_DOUBLE_COLON)
                || $name !== null && $tokens[$name]->text === ':'
            ) {
                continue;
            }

            $ranges[] = [
                $token->pos,
                PhpTokens::bodyEnd($tokens, $i),
                $name !== null && $tokens[$name]->is(T_STRING) ? $namespace . $tokens[$name]->text : null,
            ];
        }

        return new self($ranges);
    }

    /**
     * The full name of the innermost class-like around the offset, or null
     * when the offset is outside every named one.
     */
    public function at(int $offset): ?string
    {
        return $this->innermost($offset)[2] ?? null;
    }

    /**
     * The innermost range around the offset, or null outside every one.
     *
     * @return array{int, int, ?string}|null
     */
    private function innermost(int $offset): ?array
    {
        $found = null;
        foreach ($this->ranges as $range) {
            if ($offset < $range[0] || $offset >= $range[1]) {
                continue;
            }

            $found = $range;
        }

        return $found;
    }

    /**
     * The namespace declared by the `namespace` keyword at the index, with a
     * trailing backslash. Empty for the global namespace.
     *
     * @param list<PhpToken> $tokens
     */
    private static function namespaceAt(array $tokens, int $index): string
    {
        $name = PhpTokens::next($tokens, $index + 1);

        return $name !== null && $tokens[$name]->is([T_STRING, T_NAME_QUALIFIED]) ? $tokens[$name]->text . '\\' : '';
    }
}
