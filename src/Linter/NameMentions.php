<?php

declare(strict_types=1);

namespace MagoPHPUnit\Linter;

use Mago\Sdk\Span;

use function array_column;
use function array_keys;
use function count;
use function implode;
use function intdiv;
use function preg_match_all;
use function substr;

use const PREG_OFFSET_CAPTURE;

/**
 * The places in a file's text where an assertion or helper name is written.
 *
 * A call to one of these methods has the method's name in its text. A method
 * or a call without a mention is skipped before any node is read, which costs
 * far more than a text search.
 *
 * @internal
 */
final class NameMentions
{
    /**
     * The pattern for every assertion and helper name, built once per worker.
     *
     * @var non-empty-string|null
     */
    private static ?string $pattern = null;

    /**
     * The offset of each mention, in order.
     *
     * @var list<int>
     */
    private readonly array $offsets;

    /**
     * Finds the mentions in the text.
     *
     * A name counts wherever it's written as a whole word, such as in a
     * comment, a string or a variable name. So a span with a mention may
     * have a call, and a span without one never does.
     */
    public function __construct(string $contents)
    {
        self::$pattern ??=
            '/\b' . self::alternatives(array_keys(AssertionNames::ASSERTIONS + AssertionNames::HELPERS)) . '\b/i';
        $matches = [];
        preg_match_all(self::$pattern, $contents, $matches, PREG_OFFSET_CAPTURE);
        $this->offsets = array_column($matches[0], column_key: 1);
    }

    /**
     * The pattern part that matches any of the names, with shared starts
     * written once, such as `assert(?:count|same)`.
     *
     * A plain list of over 200 names is tried name by name at every word
     * of a file. Grouped by letter, a word is given up at its first letter
     * that doesn't fit, and the search takes half the time.
     *
     * @param list<string> $names Names of letters and digits only.
     */
    private static function alternatives(array $names): string
    {
        $rests = [];
        $ends = false;
        foreach ($names as $name) {
            if ($name === '') {
                $ends = true;

                continue;
            }

            $rests[$name[0]][] = substr($name, offset: 1);
        }

        $parts = [];
        foreach ($rests as $letter => $rest) {
            $parts[] = $letter . self::alternatives($rest);
        }

        if ($parts === []) {
            return '';
        }

        $group = count($parts) === 1 && !$ends ? $parts[0] : '(?:' . implode('|', $parts) . ')';

        return $ends ? $group . '?' : $group;
    }

    /**
     * Whether a name starts inside the span.
     */
    public function within(Span $span): bool
    {
        [$low, $high] = [0, count($this->offsets)];
        while ($low < $high) {
            $middle = intdiv($low + $high, num2: 2);
            if ($this->offsets[$middle] < $span->start) {
                $low = $middle + 1;

                continue;
            }

            $high = $middle;
        }

        return ($this->offsets[$low] ?? $span->end) < $span->end;
    }
}
