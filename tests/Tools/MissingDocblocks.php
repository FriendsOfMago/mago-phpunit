<?php

/**
 * Checks that every class-like, function and method has a docblock with a description.
 *
 * Constructors, anonymous classes and test classes don't need one. A test
 * class already has a `#[CoversClass]` attribute with the class under test.
 * A docblock that has only tags, or only `{@inheritdoc}`, doesn't count.
 *
 * Usage: php tests/Tools/MissingDocblocks.php <path>...
 */

declare(strict_types=1);

namespace MagoPHPUnit\Tests\Tools;

use FilesystemIterator;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_slice;
use function count;
use function dirname;
use function explode;
use function file_get_contents;
use function fwrite;
use function in_array;
use function is_dir;
use function ltrim;
use function sort;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function trim;

use const STDERR;
use const STDOUT;
use const T_ABSTRACT;
use const T_ATTRIBUTE;
use const T_CLASS;
use const T_COMMENT;
use const T_DOC_COMMENT;
use const T_ENUM;
use const T_FINAL;
use const T_FUNCTION;
use const T_INTERFACE;
use const T_PRIVATE;
use const T_PROTECTED;
use const T_PUBLIC;
use const T_READONLY;
use const T_STATIC;
use const T_STRING;
use const T_TRAIT;
use const T_WHITESPACE;

/**
 * Runs the check; see the file docblock.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class MissingDocblocks
{
    /**
     * The tokens allowed between a docblock and the declaration keyword.
     */
    private const MODIFIERS = [T_ABSTRACT, T_FINAL, T_PRIVATE, T_PROTECTED, T_PUBLIC, T_READONLY, T_STATIC];

    /**
     * The keywords that declare a class-like.
     */
    private const CLASS_LIKES = [T_CLASS, T_ENUM, T_INTERFACE, T_TRAIT];

    /**
     * Returns the process exit code.
     *
     * @param list<string> $argv
     */
    public static function main(array $argv): int
    {
        $paths = array_slice($argv, offset: 1);
        if ($paths === []) {
            fwrite(STDERR, data: "Usage: php tests/Tools/MissingDocblocks.php <path>...\n");

            return 2;
        }

        $missing = [];
        foreach (self::files($paths) as $file) {
            foreach (self::undocumented($file) as $found) {
                $missing[] = $found;
            }
        }

        if ($missing === []) {
            fwrite(STDOUT, data: "Every class-like, function and method has a docblock.\n");

            return 0;
        }

        foreach ($missing as $found) {
            fwrite(STDERR, "{$found}: no docblock description.\n");
        }

        fwrite(STDERR, count($missing) . " declarations need a docblock.\n");

        return 1;
    }

    /**
     * Lists the PHP files under the given paths, sorted.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private static function files(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (!is_dir($path)) {
                $files[] = $path;
                continue;
            }

            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $path,
                FilesystemIterator::SKIP_DOTS,
            ));
            /** @var SplFileInfo $entry */
            foreach ($entries as $entry) {
                if (!$entry->isFile() || !str_ends_with($entry->getFilename(), '.php')) {
                    continue;
                }

                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Returns `file:line: name` for each declaration in the file without a description.
     *
     * @return list<string>
     */
    private static function undocumented(string $file): array
    {
        $tokens = PhpToken::tokenize((string) file_get_contents($file));
        $missing = [];
        foreach ($tokens as $index => $token) {
            $name = self::declared($tokens, $index);
            if ($name === null) {
                continue;
            }

            $docblock = self::docblockBefore($tokens, $index);
            if ($docblock === null || !self::describes($docblock)) {
                $missing[] = "{$file}:{$token->line}: {$name}";
            }
        }

        return $missing;
    }

    /**
     * Returns the name declared at the index, or NULL when the token there doesn't need a docblock.
     *
     * A function or method name ends with `()`. Returns NULL for a constructor.
     *
     * @param list<PhpToken> $tokens
     */
    private static function declared(array $tokens, int $index): ?string
    {
        $keyword = $tokens[$index]->id;
        if (in_array($keyword, self::CLASS_LIKES, strict: true)) {
            // `new class` and `Foo::class` don't have a name after the keyword.
            $name = $tokens[self::skip($tokens, $index + 1)] ?? null;
            if ($name?->id !== T_STRING || str_ends_with($name->text, 'Test')) {
                return null;
            }

            return $name->text;
        }

        if ($keyword !== T_FUNCTION) {
            return null;
        }

        // Closures and arrow functions don't have a name after `function`.
        $name = self::functionName($tokens, $index);

        return $name === null || strtolower($name) === '__construct' ? null : "{$name}()";
    }

    /**
     * Returns the name declared by the `function` keyword at the index, or NULL for a closure.
     *
     * @param list<PhpToken> $tokens
     */
    private static function functionName(array $tokens, int $index): ?string
    {
        $next = self::skip($tokens, $index + 1);
        // A function that returns by reference has `&` before its name.
        if (($tokens[$next] ?? null)?->text === '&') {
            $next = self::skip($tokens, $next + 1);
        }

        $name = $tokens[$next] ?? null;
        if ($name === null || $name->id !== T_STRING) {
            return null;
        }

        // `use function foo;` imports a function and doesn't declare one.
        $paren = self::skip($tokens, $next + 1);

        return ($tokens[$paren] ?? null)?->text === '(' ? $name->text : null;
    }

    /**
     * Returns the docblock above the declaration that starts at the index.
     *
     * Modifiers, attributes and plain comments can sit between the docblock
     * and the declaration keyword.
     *
     * @param list<PhpToken> $tokens
     */
    private static function docblockBefore(array $tokens, int $index): ?string
    {
        $current = $index - 1;
        while ($current >= 0) {
            $token = $tokens[$current];
            if ($token->id === T_DOC_COMMENT) {
                return $token->text;
            }

            if (in_array($token->id, [T_WHITESPACE, T_COMMENT, ...self::MODIFIERS], strict: true)) {
                $current--;
                continue;
            }

            if ($token->text !== ']') {
                return null;
            }

            $current = self::attributeStart($tokens, $current) - 1;
        }

        return null;
    }

    /**
     * Returns the index of the `#[` that opens the attribute closed at the index.
     *
     * @param list<PhpToken> $tokens
     */
    private static function attributeStart(array $tokens, int $close): int
    {
        $depth = 0;
        for ($current = $close; $current >= 0; $current--) {
            $token = $tokens[$current];
            if ($token->text === ']') {
                $depth++;
                continue;
            }

            if ($token->text !== '[' && $token->id !== T_ATTRIBUTE) {
                continue;
            }

            $depth--;
            if ($depth === 0) {
                return $current;
            }
        }

        return 0;
    }

    /**
     * Whether the docblock has a line of text that isn't a tag.
     */
    private static function describes(string $docblock): bool
    {
        foreach (explode("\n", $docblock) as $line) {
            $text = trim(ltrim(trim($line), characters: '/*'));
            if ($text !== '' && !str_starts_with($text, '@') && strtolower($text) !== '{@inheritdoc}') {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the first index from the given one that isn't whitespace or a comment.
     *
     * @param list<PhpToken> $tokens
     */
    private static function skip(array $tokens, int $index): int
    {
        while (($tokens[$index] ?? null)?->isIgnorable() ?? false) {
            $index++;
        }

        return $index;
    }
}

require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';

exit(MissingDocblocks::main($argv));
