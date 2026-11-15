<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\DataSets;

use Mago\Sdk\Analyzer\Metadata\MethodMetadataProjection;
use Mago\Sdk\Span;
use MagoPHPUnit\Analyzer\TestClass\TestClass;

use function explode;
use function preg_match;
use function preg_match_all;
use function rtrim;
use function strlen;
use function strpos;
use function substr;
use function trim;

/**
 * Finds the data sets of a test's `@testWith` tag on PHPUnit 10 and 11.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final class TestWithTag
{
    private function __construct() {}

    /**
     * The data sets of the test's first `@testWith` tag.
     *
     * PHPUnit reads the tag from the doc comment itself, so PHPUnit
     * attributes on the method don't stop it. A `@dataProvider` tag read by
     * PHPUnit takes its place.
     *
     * @return list<TestWithSet>
     */
    public static function sets(TestClass $test, MethodMetadataProjection $method): array
    {
        if ($test->methodTags($method, ['dataProvider']) !== []) {
            return [];
        }

        $docblock = $test->methodDocblock($method);

        return $docblock === null ? [] : self::rows($test->contents, $docblock);
    }

    /**
     * Whether PHPUnit finds a `@testWith` tag in the docblock.
     */
    public static function found(string $contents, Span $docblock): bool
    {
        return self::tag(self::stripped(substr($contents, $docblock->start, $docblock->length()))[0]) !== null;
    }

    /**
     * The data set of each row after the first `@testWith` tag in a docblock.
     *
     * @return list<TestWithSet>
     */
    private static function rows(string $contents, Span $docblock): array
    {
        // PHPUnit reads one JSON array per line after the tag. PHPUnit stops at an empty line or
        // one without an initial `[`. PHPUnit errors out before the test on invalid JSON. Only that
        // row is returned with its problem. A tag without rows is returned as one problem on the
        // tag.
        [$text, $offsets] = self::stripped(substr($contents, $docblock->start, $docblock->length()));
        $tag = self::tag($text);
        if ($tag === null) {
            return [];
        }

        [$at, $position] = $tag;
        $after = substr($text, $position);
        $rows = [];
        foreach (explode("\n", $after) as $line) {
            $row = trim($line);
            if ($row === '' || $row[0] !== '[') {
                break;
            }

            $start = $position + (int) strpos($line, $row);
            $position += strlen($line) + 1;
            $end = $offsets[$start + strlen($row) - 1] ?? 0;
            $span = new Span($docblock->start + ($offsets[$start] ?? 0), $docblock->start + $end + 1);
            $error = JsonDataSets::error($row);
            if ($error !== null) {
                return [new TestWithSet($span, type: null, problem: [
                    "This @testWith row isn't valid JSON: {$error}.",
                    'PHPUnit errors out on the test. Fix the JSON.',
                ])];
            }

            $rows[] = new TestWithSet($span, JsonDataSets::type($row), problem: null);
        }

        if ($rows !== []) {
            return $rows;
        }

        $start = $docblock->start + ($offsets[$at] ?? 0);

        return [new TestWithSet(
            new Span($start, $start + strlen('@testWith')),
            type: null,
            problem: [
                "This @testWith tag doesn't have any data sets.",
                'PHPUnit errors out on the test. Write one JSON array per line after the tag.',
            ],
        )];
    }

    /**
     * The offsets of the first `@testWith` tag and its rows in the stripped docblock, or null.
     *
     * @return array{int, int}|null
     */
    private static function tag(string $text): ?array
    {
        // PHPUnit drops the last byte, the slash at the end of the comment. PHPUnit trims the line
        // breaks before it. A bare tag on the last line isn't found.
        $text = rtrim(substr($text, offset: 0, length: -1), characters: "\n");
        $matches = [];
        if (preg_match('/@testWith\s+/', $text, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $at = (int) $matches[0][1];

        return [$at, $at + strlen($matches[0][0])];
    }

    /**
     * The docblock text as PHPUnit reads it, with the offset in the docblock of each byte.
     *
     * PHPUnit replaces each line break with the whitespace and `*` after it,
     * and one space after that, by a line break.
     *
     * @return array{string, list<int>}
     */
    private static function stripped(string $docblock): array
    {
        $matches = [];
        preg_match_all('/\n\s*\*\s?/', $docblock, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $cuts = [];
        foreach ($matches as $match) {
            $cuts[] = [(int) $match[0][1], strlen($match[0][0])];
        }

        $cuts[] = [strlen($docblock), 0];
        $text = '';
        $offsets = [];
        $from = 0;
        foreach ($cuts as [$at, $length]) {
            for ($i = $from; $i < $at; $i++) {
                $text .= $docblock[$i];
                $offsets[] = $i;
            }

            if ($length > 0) {
                $text .= "\n";
                $offsets[] = $at;
            }

            $from = $at + $length;
        }

        return [$text, $offsets];
    }
}
