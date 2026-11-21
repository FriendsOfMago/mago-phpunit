<?php

/**
 * Checks that every corpus expectation holds on the line it is written above.
 *
 * Usage: php tests/Tools/CorpusPins.php <mago binary>
 */

declare(strict_types=1);

namespace MagoPHPUnit\Tests\Tools;

use FilesystemIterator;
use PhpToken;
use PHPUnit\Runner\Version;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

use function array_filter;
use function copy;
use function count;
use function dirname;
use function explode;
use function file;
use function file_get_contents;
use function file_put_contents;
use function fwrite;
use function getmypid;
use function implode;
use function in_array;
use function is_dir;
use function json_decode;
use function mkdir;
use function preg_grep;
use function preg_match;
use function preg_replace;
use function proc_close;
use function proc_open;
use function rmdir;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strrpos;
use function substr;
use function symlink;
use function sys_get_temp_dir;
use function trim;
use function unlink;
use function usort;

use const FILE_IGNORE_NEW_LINES;
use const JSON_THROW_ON_ERROR;
use const STDERR;
use const STDOUT;
use const T_ABSTRACT;
use const T_ATTRIBUTE;
use const T_CLASS;
use const T_COMMENT;
use const T_CURLY_OPEN;
use const T_DOLLAR_OPEN_CURLY_BRACES;
use const T_ENUM;
use const T_FINAL;
use const T_INTERFACE;
use const T_NAMESPACE;
use const T_READONLY;
use const T_TRAIT;

/**
 * Runs the corpus and matches each issue to the `@mago-expect` pragma above it.
 *
 * @phpstan-type Pragma array{file: string, line: int, end: int, category: string, code: string, count: int}
 * @phpstan-type Found array{file: string, line: int, category: string, code: string}
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final class CorpusPins
{
    /**
     * An own-line pragma with one code and an optional count.
     */
    private const PRAGMA = '/^\s*\/\/\s*@mago-expect\s+(analysis|lint):([\w\/-]+)(?:\((\d+)\))?\s*$/';

    /**
     * Mago arguments that switch the report to JSON on stdout.
     */
    private const JSON = ['--reporting-format', 'json'];

    /**
     * The log line written by Mago for a worker request failure or rejection.
     */
    private const WORKER_FAILURE = '/External analyzer .* failed|extension worker .* rejected request/';

    private function __construct() {}

    /**
     * Runs the check and returns the process exit code.
     *
     * Mago matches an own-line `@mago-expect` inside a function body to any
     * later issue with the same code in the file. When a fixture's issue
     * disappears, its pragma can match an issue a few lines further down, and
     * the corpus run stays green. So the corpus runs on a copy
     * with every `@mago-expect` renamed. Mago ignores the renamed pragmas,
     * and this script matches them with a stricter rule.
     *
     * The analyzer pragmas are under `tests/corpus/src`. The linter pragmas
     * are under `tests/corpus/lint`, in one folder per rule, named after the
     * rule's code without `phpunit/`. Cases written for another PHPUnit major
     * than the installed one are left out. A `// Corpus: PHPUnit N and later.`,
     * `N and earlier.` or `N only.` line above the `namespace` statement gates
     * the whole file. The same line below it gates the next class-like
     * declaration, with its comments and attributes.
     *
     * @param list<string> $arguments
     */
    public static function main(array $arguments): int
    {
        $mago = $arguments[1] ?? null;
        if ($mago === null) {
            fwrite(STDERR, data: "Usage: php tests/Tools/CorpusPins.php <mago binary>\n");

            return 2;
        }

        // The copy is next to the corpus, so the relative paths in its
        // mago.toml still point to the worker and the vendor directory.
        $corpus = dirname(__DIR__) . '/corpus';
        $copy = dirname(__DIR__) . '/.corpus-pins-' . (string) getmypid();
        try {
            self::copyTree($corpus, $copy);
            self::dropOtherVersions($copy . '/src', (int) Version::series());
            $pragmas = [
                ...self::neutralize($copy, relativeDirectory: 'src'),
                ...self::neutralize($copy, relativeDirectory: 'lint'),
            ];
            $found = self::issues([$mago, '--workspace', $copy, 'analyze', ...self::JSON], category: 'analysis');

            // The style rules contradict each other, so each rule runs alone
            // on the folder named after it.
            foreach (self::lintRules($copy . '/lint') as $rule) {
                $command = [
                    $mago,
                    '--workspace',
                    $copy,
                    'lint',
                    '--only',
                    "phpunit/{$rule}",
                    ...self::JSON,
                    "lint/{$rule}",
                ];
                $found = [...$found, ...self::issues($command, category: 'lint')];
            }
        } finally {
            self::removeTree($copy);
        }

        return self::report($pragmas, $found);
    }

    /**
     * Returns the names of the folders under the lint corpus, one per rule.
     *
     * @return list<string>
     */
    private static function lintRules(string $directory): array
    {
        $rules = [];
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry instanceof SplFileInfo || !$entry->isDir()) {
                continue;
            }

            $rules[] = $entry->getFilename();
        }

        sort($rules);

        return $rules;
    }

    /**
     * Matches issues to pragmas and prints what is left on either side.
     *
     * @param list<Pragma> $pragmas
     * @param list<Found> $found
     */
    private static function report(array $pragmas, array $found): int
    {
        [$stray, $left] = self::assign($pragmas, $found);
        foreach ($stray as $issue) {
            fwrite(
                STDERR,
                "{$issue['file']}:{$issue['line']}: no pragma above for {$issue['category']}:{$issue['code']}\n",
            );
        }

        $missing = false;
        foreach ($pragmas as $index => $pragma) {
            if ($left[$index] === 0) {
                continue;
            }

            $missing = true;
            $lines = (string) $pragma['line'] . '-' . (string) $pragma['end'];
            fwrite(
                STDERR,
                "{$pragma['file']}:{$pragma['line']}: {$pragma['category']}:{$pragma['code']} is missing {$left[$index]} issue(s) on lines {$lines}\n",
            );
        }

        if ($stray !== [] || $missing) {
            return 1;
        }

        fwrite(STDOUT, count($pragmas) . " expectations pinned to their lines.\n");

        return 0;
    }

    /**
     * Assigns each issue to the closest pragma above it that covers it and
     * has room left.
     *
     * Every issue needs a pragma of its code covering the line it starts on.
     * Every pragma needs exactly one issue, or the count given in `(N)`.
     * Returns the issues without a pragma, and the number of issues each
     * pragma is still missing.
     *
     * @param list<Pragma> $pragmas
     * @param list<Found> $found
     * @return array{list<Found>, array<int, int>}
     */
    private static function assign(array $pragmas, array $found): array
    {
        usort($found, static fn(array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);
        $left = [];
        foreach ($pragmas as $index => $pragma) {
            $left[$index] = $pragma['count'];
        }

        $stray = [];
        foreach ($found as $issue) {
            $best = null;
            foreach ($pragmas as $index => $pragma) {
                if ($left[$index] === 0 || !self::covers($pragma, $issue)) {
                    continue;
                }

                // The closest pragma above the issue wins, the same as in Mago.
                if ($best === null || $pragma['line'] > $pragmas[$best]['line']) {
                    $best = $index;
                }
            }

            if ($best === null) {
                $stray[] = $issue;
                continue;
            }

            $left[$best]--;
        }

        return [$stray, $left];
    }

    /**
     * Whether the issue starts inside the pragma's lines and has its code.
     *
     * A pragma covers the lines from itself to the end of the statement or
     * declaration header that starts on the first line below it that isn't
     * blank, a comment or an attribute.
     *
     * @param Pragma $pragma
     * @param Found $issue
     */
    private static function covers(array $pragma, array $issue): bool
    {
        return (
            $pragma['file'] === $issue['file']
            && $pragma['category'] === $issue['category']
            && $pragma['code'] === $issue['code']
            && $pragma['line'] < $issue['line']
            && $issue['line'] <= $pragma['end']
        );
    }

    /**
     * Reads the pragmas under a directory of the workspace and renames them
     * in place, so Mago doesn't apply them.
     *
     * The new name still starts with `@mago-`. The comment rules treat that
     * prefix as a directive, so the renamed pragma doesn't add an issue.
     *
     * @return list<Pragma>
     */
    private static function neutralize(string $workspace, string $relativeDirectory): array
    {
        $directory = $workspace . '/' . $relativeDirectory;
        $pragmas = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $directory,
            FilesystemIterator::SKIP_DOTS,
        ));
        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $path = $file->getPathname();
            $source = file_get_contents($path);
            if ($source === false || !str_contains($source, '@mago-expect')) {
                continue;
            }

            $relative = $relativeDirectory . '/' . substr($path, offset: strlen($directory) + 1);
            $lines = explode("\n", $source);
            $tokens = PhpToken::tokenize($source);
            foreach ($lines as $index => $line) {
                if (!str_contains($line, '@mago-expect')) {
                    continue;
                }

                $matches = [];
                if (preg_match(self::PRAGMA, $line, $matches) !== 1) {
                    throw new RuntimeException(
                        "{$relative}:" . (string) ($index + 1) . ': only own-line pragmas with one code are supported.',
                    );
                }

                $target = self::target($lines, $index + 1);
                $pragmas[] = [
                    'file' => $relative,
                    'line' => $index + 1,
                    'end' => $target === null ? $index + 1 : self::statementEnd($tokens, $target),
                    'category' => $matches[1],
                    'code' => $matches[2],
                    'count' => ($matches[3] ?? '') === '' ? 1 : (int) $matches[3],
                ];
            }

            file_put_contents($path, str_replace('@mago-expect', replace: '@mago-pinned', subject: $source));
        }

        return $pragmas;
    }

    /**
     * The first line from `$from` (0-based) on that isn't blank, a comment or
     * an attribute, as a 1-based line number. A line with code after the end
     * of a block comment isn't a comment.
     *
     * @param list<string> $lines
     */
    private static function target(array $lines, int $from): ?int
    {
        for ($index = $from; $index < count($lines); $index++) {
            $line = trim($lines[$index]);
            $close = strrpos($line, needle: '*/');
            $comment =
                $line === ''
                || str_starts_with($line, '//')
                || str_starts_with($line, '#')
                || (str_starts_with($line, '/*') || str_starts_with($line, '*'))
                && ($close === false || trim(substr($line, $close + 2)) === '');
            if (!$comment) {
                return $index + 1;
            }
        }

        return null;
    }

    /**
     * The line of the `;` or `{` that ends the statement or declaration header
     * starting on the given line, outside any bracket, or of the `,` that
     * ends an array element, an argument or a match arm.
     *
     * @param list<PhpToken> $tokens
     */
    private static function statementEnd(array $tokens, int $start): int
    {
        $depth = 0;
        $end = $start;
        foreach ($tokens as $token) {
            if ($token->line < $start || $token->isIgnorable()) {
                continue;
            }

            // The block, array or argument list around the line closes before
            // any end token. The line is its last element, so the range ends
            // there.
            if ($depth === 0 && in_array($token->text, [')', ']', '}'], strict: true)) {
                return $end;
            }

            $end = $token->line;
            // `{$x}` and `${x}` open inside a string and close with a plain `}`.
            $interpolation = $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]);
            if ($depth === 0 && !$interpolation && in_array($token->text, [';', '{', ','], strict: true)) {
                return $end;
            }

            $depth += match (true) {
                $interpolation, in_array($token->text, ['(', '[', '{'], strict: true) => 1,
                in_array($token->text, [')', ']', '}'], strict: true) => -1,
                default => 0,
            };
        }

        return $end;
    }

    /**
     * Runs Mago and reads the issues it reports, with 1-based lines.
     *
     * @param list<string> $command
     * @return list<Found>
     */
    private static function issues(array $command, string $category): array
    {
        // Uses files instead of pipes, so a large report never blocks Mago.
        $output = sys_get_temp_dir() . '/mago-phpunit-pins-' . (string) getmypid() . '.json';
        $log = $output . '.log';
        $pipes = [];
        $process = proc_open($command, [1 => ['file', $output, 'w'], 2 => ['file', $log, 'w']], $pipes);
        if ($process === false) {
            throw new RuntimeException('Could not start ' . implode(' ', $command));
        }

        proc_close($process);
        $json = (string) file_get_contents($output);
        $lines = file($log, FILE_IGNORE_NEW_LINES);
        $failures = preg_grep(self::WORKER_FAILURE, $lines === false ? [] : $lines);
        unlink($output);
        unlink($log);
        // Mago falls back to its own analysis when the worker rejects a
        // request. Without this check, a provider refused by the SDK would go
        // unnoticed.
        if ($failures !== false && $failures !== []) {
            throw new RuntimeException("The worker failed a request:\n" . implode("\n", $failures));
        }

        $found = [];
        /** @var mixed $issue */
        foreach (Shape::arrayAt(
            json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR),
            ['issues'],
        ) as $issue) {
            $found[] = self::found($issue, $category);
        }

        return $found;
    }

    /**
     * The code of one issue in Mago's JSON report, and the start of its
     * primary annotation.
     *
     * @return Found
     */
    private static function found(mixed $issue, string $category): array
    {
        $annotations = Shape::arrayAt($issue, ['annotations']);
        $primaryKey = 0;
        /** @var mixed $annotation */
        foreach ($annotations as $key => $annotation) {
            if (Shape::stringAt($annotation, ['kind']) !== 'Primary') {
                continue;
            }

            $primaryKey = $key;
            break;
        }

        /** @var mixed $primary */
        $primary = $annotations[$primaryKey] ?? null;

        $code = Shape::stringAt($issue, ['code']);
        $file = Shape::stringAt($primary, ['span', 'file_id', 'name']);
        $line = Shape::int(Shape::arrayAt($primary, ['span', 'start'])['line'] ?? null);
        if ($code === null || $file === null || $line === null) {
            throw new RuntimeException('Unexpected issue shape in Mago output.');
        }

        return ['file' => $file, 'line' => $line, 'category' => $category, 'code' => $code];
    }

    /**
     * Removes the cases written for another PHPUnit major than the installed
     * one.
     *
     * A `// Corpus: PHPUnit N and later.`, `// Corpus: PHPUnit N and earlier.`
     * or `// Corpus: PHPUnit N only.` line above the `namespace` statement
     * gates the file. A dropped file is deleted. The same line below the
     * `namespace` statement gates the next class-like declaration. A dropped
     * class is blanked from the start of the marker line to its closing brace,
     * so the lines after it keep their numbers.
     */
    private static function dropOtherVersions(string $directory, int $installed): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $directory,
            FilesystemIterator::SKIP_DOTS,
        ));
        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $path = $file->getPathname();
            $source = (string) file_get_contents($path);
            if (!str_contains($source, '// Corpus: PHPUnit')) {
                continue;
            }

            $gates = self::gates($source, $path);
            $fileGates = array_filter($gates, static fn(array $gate): bool => $gate[2] === null);
            if (count($fileGates) > 1) {
                throw new RuntimeException("{$path}: more than one file marker.");
            }

            $blanked = $source;
            foreach ($gates as [$gate, $start, $end]) {
                if (!self::isDropped($gate, $installed)) {
                    continue;
                }

                if ($end === null) {
                    unlink($path);
                    continue 2;
                }

                $blanked = self::blank($blanked, $start, $end);
            }

            if ($blanked !== $source) {
                file_put_contents($path, $blanked);
            }
        }
    }

    /**
     * Whether a gate like `11 and later` leaves its case out on the installed
     * major.
     */
    private static function isDropped(string $gate, int $installed): bool
    {
        [$version, $form] = explode(' ', $gate, limit: 2);

        return match ($form) {
            'and later' => (int) $version > $installed,
            'and earlier' => (int) $version < $installed,
            default => (int) $version !== $installed,
        };
    }

    /**
     * Returns the version markers of a file, as the gate text, the byte
     * offset of the marker line and the end offset of the gated class.
     *
     * The end offset is null for a file marker. A class marker needs a blank
     * line above it, must be outside any class body, and must be followed by
     * a class-like declaration. Comments, attributes and modifiers may come
     * between the two.
     *
     * @return list<array{string, int, ?int}>
     */
    private static function gates(string $source, string $path): array
    {
        $tokens = PhpToken::tokenize($source);
        $gates = [];
        $depth = 0;
        $namespace = false;
        foreach ($tokens as $index => $token) {
            if ($token->is(T_NAMESPACE)) {
                $namespace = true;
            }

            $depth += self::depthChange($token);
            $matches = [];
            if (
                !$token->is(T_COMMENT)
                || preg_match(
                    '/^\/\/ Corpus: PHPUnit (\d+ (?:and later|and earlier|only))\.$/',
                    trim($token->text),
                    $matches,
                ) !== 1
            ) {
                continue;
            }

            $line = (string) $token->line;
            $newline = strrpos(substr($source, offset: 0, length: $token->pos), needle: "\n");
            $start = $newline === false ? 0 : $newline + 1;

            if (!$namespace) {
                $gates[] = [$matches[1], $start, null];
                continue;
            }

            if ($depth !== 0) {
                throw new RuntimeException("{$path}:{$line}: class marker inside a class body.");
            }

            $above = substr($source, offset: 0, length: $start);
            if (!str_ends_with($above, "\n\n") && !str_ends_with($above, "\n\r\n")) {
                throw new RuntimeException("{$path}:{$line}: class marker without a blank line above it.");
            }

            $gates[] = [$matches[1], $start, self::classEnd($tokens, $index + 1, "{$path}:{$line}")];
        }

        return $gates;
    }

    /**
     * Returns the offset after the closing brace of the class-like declaration
     * that starts at the given token, after any comments, attributes and
     * modifiers.
     *
     * @param list<PhpToken> $tokens
     */
    private static function classEnd(array $tokens, int $from, string $where): int
    {
        $index = $from;
        $count = count($tokens);
        while ($index < $count) {
            $token = $tokens[$index];
            if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
                break;
            }

            if ($token->is(T_ATTRIBUTE)) {
                $index = self::attributeEnd($tokens, $index);
                continue;
            }

            if (!$token->isIgnorable() && !$token->is([T_FINAL, T_ABSTRACT, T_READONLY])) {
                throw new RuntimeException("{$where}: class marker without a class-like declaration after it.");
            }

            $index++;
        }

        $depth = 0;
        for (; $index < $count; $index++) {
            $change = self::depthChange($tokens[$index]);
            $depth += $change;
            if ($change < 0 && $depth === 0) {
                return $tokens[$index]->pos + 1;
            }
        }

        throw new RuntimeException("{$where}: class marker without a class-like declaration after it.");
    }

    /**
     * Returns the index of the token after the `]` that closes the attribute
     * group opened at the given token.
     *
     * @param list<PhpToken> $tokens
     */
    private static function attributeEnd(array $tokens, int $open): int
    {
        $depth = 0;
        for ($index = $open; $index < count($tokens); $index++) {
            $text = $tokens[$index]->text;
            $depth += match (true) {
                $tokens[$index]->is(T_ATTRIBUTE), $text === '[' => 1,
                $text === ']' => -1,
                default => 0,
            };
            if ($depth === 0) {
                return $index + 1;
            }
        }

        return $index;
    }

    /**
     * Returns 1 for a token that opens a brace, -1 for `}`, and 0 otherwise.
     * `{$x}` and `${x}` open inside a string and close with a plain `}`.
     */
    private static function depthChange(PhpToken $token): int
    {
        if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
            return 1;
        }

        return $token->text === '}' ? -1 : 0;
    }

    /**
     * Replaces every byte from `$start` to `$end` with a space, except line
     * breaks.
     */
    private static function blank(string $source, int $start, int $end): string
    {
        $region = substr($source, $start, $end - $start);

        return (
            substr($source, offset: 0, length: $start)
            . (string) preg_replace('/[^\r\n]/', replacement: ' ', subject: $region)
            . substr($source, $end)
        );
    }

    /**
     * Copies a directory tree. A symlink is copied as the same link, so a
     * relative one points into the copy.
     */
    private static function copyTree(string $from, string $to): void
    {
        mkdir($to, recursive: true);
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $target = $to . substr($entry->getPathname(), offset: strlen($from));
            $link = $entry->isLink() ? $entry->getLinkTarget() : false;
            if ($link !== false) {
                symlink($link, $target);
                continue;
            }

            if ($entry->isDir()) {
                mkdir($target);
                continue;
            }

            copy($entry->getPathname(), $target);
        }
    }

    /**
     * Removes a directory tree.
     */
    private static function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
                continue;
            }

            unlink($entry->getPathname());
        }

        rmdir($directory);
    }
}

require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';

exit(CorpusPins::main($argv));
