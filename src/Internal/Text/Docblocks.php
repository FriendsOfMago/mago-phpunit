<?php

declare(strict_types=1);

namespace MagoPHPUnit\Internal\Text;

use Mago\Sdk\Span;
use PhpToken;

use function count;
use function in_array;
use function preg_match_all;
use function rtrim;
use function str_starts_with;
use function strlen;
use function substr;

use const PREG_OFFSET_CAPTURE;
use const PREG_SET_ORDER;
use const T_ATTRIBUTE;
use const T_COMMENT;
use const T_DOC_COMMENT;
use const T_WHITESPACE;

/**
 * Finds the docblock of a declaration and the tags in it.
 *
 * @internal
 */
final class Docblocks
{
    /**
     * A tag name as parsed by PHPUnit.
     */
    public const NAME = '[a-zA-Z_-]+';

    /**
     * A tag name as parsed by phpstan-phpunit. It stops before a `-` or `_`,
     * so `@covers-nothing` is `@covers` followed by `-nothing`.
     */
    public const LETTERS = '[a-zA-Z]+';

    private function __construct() {}

    /**
     * The docblock span above the declaration at the offset, or null when there isn't one.
     */
    public static function before(string $contents, int $offset): ?Span
    {
        return self::above(PhpTokens::of($contents), $offset);
    }

    /**
     * The same as before(), for one declaration near the top of a file.
     *
     * Only the text before the offset is tokenized, unless the file's tokens
     * are already cached. Use before() for several declarations of a file.
     */
    public static function beforeFirst(string $contents, int $offset): ?Span
    {
        return self::above(PhpTokens::before($contents, $offset), $offset);
    }

    /**
     * The docblock span above the declaration at the offset, found in the tokens before it.
     *
     * @param list<PhpToken> $tokens
     */
    private static function above(array $tokens, int $offset): ?Span
    {
        // The file is read as tokens, so a `/*` in a string or a comment
        // isn't taken as the start of a docblock.
        $index = (PhpTokens::at($tokens, $offset) ?? count($tokens)) - 1;
        while ($index >= 0) {
            $token = $tokens[$index];
            if ($token->id === T_DOC_COMMENT) {
                return new Span($token->pos, $token->pos + strlen($token->text));
            }

            if ($token->id === T_WHITESPACE || $token->id === T_COMMENT && !str_starts_with($token->text, '/*')) {
                $index--;
                continue;
            }

            // Whitespace, attribute groups and `//` or `#` comments are skipped between the declaration
            // and docblock. A declaration's span may start at its modifiers. Attribute groups must be
            // skipped in that case.
            $open = $token->text === ']' ? self::attributeStart($tokens, $index) : null;
            if ($open === null) {
                return null;
            }

            $index = $open - 1;
        }

        return null;
    }

    /**
     * Each `@name value` tag of a docblock whose name is one of the given
     * ones, with the text right after the name and the span of the tag.
     *
     * Names are matched with the NAME pattern used by PHPUnit, unless
     * another pattern is given.
     *
     * @param list<string> $names
     * @return list<array{name: string, value: string, gap: string, span: Span}>
     */
    public static function tags(string $contents, Span $docblock, array $names, string $name = self::NAME): array
    {
        $text = substr($contents, $docblock->start, $docblock->length());
        $matches = [];
        preg_match_all(
            '/@(' . $name . ')([ \t]*)([^\r\n*]*(?:\*(?!\/)[^\r\n*]*)*)/',
            $text,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        $tags = [];
        foreach ($matches as $match) {
            $name = $match[1][0];
            if (!in_array($name, $names, strict: true)) {
                continue;
            }

            $value = rtrim($match[3][0]);
            $start = $docblock->start + (int) $match[0][1];
            $tags[] = [
                'name' => $name,
                'value' => $value,
                'gap' => $match[2][0],
                'span' => new Span($start, $start + strlen($match[0][0]) - (strlen($match[3][0]) - strlen($value))),
            ];
        }

        return $tags;
    }

    /**
     * The opening `#[` token index for the closing `]` token, or null for unmatched brackets.
     *
     * @param list<PhpToken> $tokens
     */
    private static function attributeStart(array $tokens, int $close): ?int
    {
        $depth = 0;
        for ($index = $close; $index >= 0; $index--) {
            $token = $tokens[$index];
            $depth += match (true) {
                $token->text === ']' => 1,
                $token->text === '[' || $token->id === T_ATTRIBUTE => -1,
                default => 0,
            };
            if ($depth === 0) {
                return $token->id === T_ATTRIBUTE ? $index : null;
            }
        }

        return null;
    }
}
