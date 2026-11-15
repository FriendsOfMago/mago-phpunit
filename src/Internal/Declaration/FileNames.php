<?php

declare(strict_types=1);

namespace MagoPHPUnit\Internal\Declaration;

use Mago\Sdk\Span;
use Mago\Sdk\Syntax\ResolvedName;
use Mago\Sdk\Syntax\SourceFile;
use MagoPHPUnit\Internal\Cache\FileMemo;

use function array_key_exists;
use function count;
use function intdiv;
use function ltrim;
use function strtolower;
use function usort;

/**
 * The resolved names of one file, sorted by offset, with results cached per span.
 *
 * @internal
 */
final class FileNames
{
    /**
     * Cached within() results, keyed by `start:end` or the empty string for the whole file.
     *
     * @var array<string, list<non-empty-string>>
     */
    private array $answers = [];

    /**
     * @var FileMemo<self>|null
     */
    private static ?FileMemo $memo = null;

    /**
     * @param list<ResolvedName> $names Sorted by start offset.
     */
    private function __construct(
        private readonly array $names,
    ) {}

    /**
     * The names of the snapshot, fetched once and sorted by start offset.
     *
     * The SDK decodes every name in the file on each `getResolvedNames()`
     * call, and several hooks look up names for the same class node. So the
     * names are fetched once per snapshot, and within() finds a span's names
     * with a binary search.
     */
    public static function of(SourceFile $file): self
    {
        self::$memo ??= (new FileMemo())->keepingSnapshots();

        return self::$memo->get($file, static function () use ($file): self {
            $names = $file->getResolvedNames();
            usort($names, static fn(ResolvedName $a, ResolvedName $b): int => $a->span->start <=> $b->span->start);

            return new self($names);
        });
    }

    /**
     * The unimported names inside the span, in source order, as declaration candidates.
     *
     * @return list<non-empty-string>
     */
    public function within(?Span $span): array
    {
        $key = $span === null ? '' : $span->start . ':' . $span->end;

        return $this->answers[$key] ??= $this->collect($span);
    }

    /**
     * Walks the names inside the span and builds the result for within().
     *
     * @return list<non-empty-string>
     */
    private function collect(?Span $span): array
    {
        $seen = [];
        $candidates = [];
        $count = count($this->names);
        for ($i = $span === null ? 0 : $this->firstAt($span->start); $i < $count; $i++) {
            $name = $this->names[$i];
            if ($span !== null && $name->span->start >= $span->end) {
                break;
            }

            $resolved = ltrim($name->name, characters: '\\');
            if ($resolved === '' || $span !== null && !$span->contains($name->span)) {
                continue;
            }

            // A name counts once, so a candidate isn't added for a later
            // unimported use of a name seen before.
            $key = strtolower($resolved);
            if (!$name->imported && !array_key_exists($key, $seen)) {
                $candidates[] = $resolved;
            }

            $seen[$key] = true;
        }

        return $candidates;
    }

    /**
     * The index of the first name starting at or after the offset.
     */
    private function firstAt(int $offset): int
    {
        $low = 0;
        $high = count($this->names);
        while ($low < $high) {
            $middle = intdiv(num1: $low + $high, num2: 2);
            $before = $this->names[$middle]->span->start < $offset;
            $low = $before ? $middle + 1 : $low;
            $high = $before ? $high : $middle;
        }

        return $low;
    }
}
