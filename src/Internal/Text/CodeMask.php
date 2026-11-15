<?php

declare(strict_types=1);

namespace MagoPHPUnit\Internal\Text;

use MagoPHPUnit\Internal\Cache\FileMemo;
use PhpToken;

use function array_map;
use function chr;
use function implode;
use function in_array;
use function range;
use function str_repeat;
use function str_replace;
use function strcspn;
use function strlen;
use function strpbrk;
use function strtr;
use function substr;

use const T_COMMENT;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_DOC_COMMENT;
use const T_ENCAPSED_AND_WHITESPACE;
use const T_INLINE_HTML;

/**
 * PHP source with everything except code turned into spaces, for regex matching.
 *
 * @internal
 */
final class CodeMask
{
    /**
     * The tokens turned into spaces: comments, text outside the PHP tags, and
     * the text of string literals and of heredoc and nowdoc bodies.
     *
     * Quotes and heredoc labels stay. A variable inside a double-quoted
     * string or a heredoc is a separate token, so it stays as code.
     */
    private const LITERALS = [
        T_COMMENT,
        T_DOC_COMMENT,
        T_INLINE_HTML,
        T_ENCAPSED_AND_WHITESPACE,
        T_CONSTANT_ENCAPSED_STRING,
    ];

    /**
     * The open tag put before a fragment, so the fragment is tokenized as code.
     */
    private const OPEN_TAG = '<?php ';

    /**
     * @var FileMemo<string>|null
     */
    private static ?FileMemo $memo = null;

    /**
     * Every byte but `\r` and `\n`, for blank().
     */
    private static ?string $bytes = null;

    private function __construct() {}

    /**
     * The file with its comments and the text of its literals blanked, cached
     * for the last file.
     *
     * The mask has the same length and line breaks as the source, so an
     * offset in one is the same offset in the other.
     */
    public static function code(string $contents): string
    {
        self::$memo ??= new FileMemo();
        $memo = self::$memo;

        return $memo->has($contents) ? $memo->value() : $memo->keep($contents, self::mask(PhpTokens::of($contents)));
    }

    /**
     * The code mask of a piece of PHP code cut out of a file, such as a
     * method. The piece is tokenized as if it came right after `<?php`.
     */
    public static function fragment(string $code): string
    {
        return substr(self::mask(PhpToken::tokenize(self::OPEN_TAG . $code)), offset: strlen(self::OPEN_TAG));
    }

    /**
     * Joins the tokens into text with the LITERALS tokens blanked.
     *
     * @param list<PhpToken> $tokens
     */
    private static function mask(array $tokens): string
    {
        /*
         * PHP's token rules apply to a `?>` inside a `//` comment. They also apply to `#[`
         * attributes, heredocs and strings with text resembling code.
         */
        $mask = '';
        foreach ($tokens as $token) {
            $text = $token->text;
            if (!in_array($token->id, self::LITERALS, strict: true)) {
                $mask .= $text;
                continue;
            }

            $mask .= $token->id === T_CONSTANT_ENCAPSED_STRING ? self::blankQuoted($text) : self::blank($text);
        }

        return $mask;
    }

    /**
     * Blanks a quoted string but keeps its quotes and any `b` prefix.
     */
    private static function blankQuoted(string $text): string
    {
        $open = strcspn($text, characters: '\'"') + 1;

        return (
            substr($text, offset: 0, length: $open)
            . self::blank(substr($text, $open, strlen($text) - $open - 1))
            . substr($text, offset: -1)
        );
    }

    /**
     * The text with every byte but a line break turned into a space.
     */
    private static function blank(string $text): string
    {
        if (strpbrk($text, characters: "\r\n") === false) {
            return str_repeat(' ', strlen($text));
        }

        // A byte-for-byte strtr() is several times faster than a regex.
        self::$bytes ??= str_replace(
            search: ["\r", "\n"],
            replace: '',
            subject: implode(array_map(chr(...), range(start: 0, end: 255))),
        );

        return strtr($text, self::$bytes, str_repeat(' ', strlen(self::$bytes)));
    }
}
