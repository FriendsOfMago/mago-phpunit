<?php

declare(strict_types=1);

namespace MagoPHPUnit\Internal\Text;

use MagoPHPUnit\Internal\Cache\FileMemo;
use PhpToken;

use function count;
use function strlen;
use function substr;

/**
 * Helpers for walking the tokens of a PHP file.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final class PhpTokens
{
    /**
     * @var FileMemo<list<PhpToken>>|null
     */
    private static ?FileMemo $memo = null;

    private function __construct() {}

    /**
     * The tokens of a file, cached for the last file, so each file is
     * tokenized once for all hooks and filters.
     *
     * @return list<PhpToken>
     */
    public static function of(string $contents): array
    {
        self::$memo ??= new FileMemo();

        return self::$memo->get($contents, static fn(): array => PhpToken::tokenize($contents));
    }

    /**
     * The file's tokens up to the offset of a declaration, or all of them
     * when the file is already tokenized.
     *
     * Tokenizing a whole file costs more than the rest of a class hook for
     * most classes. A declaration starts at a token, so the tokens before it
     * are the same in the text cut at its offset. The cut text isn't cached.
     *
     * @return list<PhpToken>
     */
    public static function before(string $contents, int $offset): array
    {
        self::$memo ??= new FileMemo();

        return self::$memo->has($contents)
            ? self::$memo->value()
            : PhpToken::tokenize(substr($contents, offset: 0, length: $offset));
    }

    /**
     * The index of the token closing the delimiter opened at `$index`.
     *
     * The token at `$index` is the opener. It's counted by position, not
     * matched by text, because an attribute's opener is `#[`, not `[`.
     *
     * @param list<PhpToken> $tokens
     */
    public static function closer(array $tokens, int $index, string $open, string $close): int
    {
        $count = count($tokens);
        $depth = 1;
        for ($i = $index + 1; $i < $count; $i++) {
            $text = $tokens[$i]->text;
            // The `${` of a `"${name}"` interpolation is closed by a `}`.
            if ($text === $open || $open === '{' && $text === '${') {
                $depth++;
                continue;
            }

            if ($text !== $close) {
                continue;
            }

            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }

        return $count - 1;
    }

    /**
     * The offset just past a declaration's body, or past the `;` of one
     * without a body.
     *
     * @param list<PhpToken> $tokens
     */
    public static function bodyEnd(array $tokens, int $index): int
    {
        $count = count($tokens);
        $depth = 0;
        for ($i = $index; $i < $count; $i++) {
            $text = $tokens[$i]->text;
            if ($text === '(' || $text === '[') {
                $depth++;
                continue;
            }

            if ($text === ')' || $text === ']') {
                $depth--;
                continue;
            }

            if ($depth > 0) {
                continue;
            }

            if ($text === ';') {
                return $tokens[$i]->pos + 1;
            }

            if ($text === '{') {
                return $tokens[self::closer($tokens, index: $i, open: '{', close: '}')]->pos + 1;
            }
        }

        return $tokens[$count - 1]->pos;
    }

    /**
     * The index of the last token at or before `$from` that is not
     * whitespace or a comment.
     *
     * @param list<PhpToken> $tokens
     */
    public static function previous(array $tokens, int $from): ?int
    {
        for ($i = $from; $i >= 0; $i--) {
            if (!$tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }

    /**
     * The index of the token that holds the byte offset, or null past the
     * end.
     *
     * The binary search works because the tokens cover the file in order,
     * without gaps.
     *
     * @param list<PhpToken> $tokens
     */
    public static function at(array $tokens, int $offset): ?int
    {
        $low = 0;
        $high = count($tokens) - 1;
        while ($low <= $high) {
            $middle = ($low + $high) >> 1;
            $token = $tokens[$middle];
            if ($offset < $token->pos) {
                $high = $middle - 1;
                continue;
            }

            if ($offset >= ($token->pos + strlen($token->text))) {
                $low = $middle + 1;
                continue;
            }

            return $middle;
        }

        return null;
    }

    /**
     * The index of the next token that is not whitespace or a comment.
     *
     * @param list<PhpToken> $tokens
     */
    public static function next(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        for ($i = $from; $i < $count; $i++) {
            if (!$tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }
}
