<?php

declare(strict_types=1);

namespace MagoPHPUnit\Internal\Cache;

use Closure;
use Mago\Sdk\Syntax\SourceFile;
use WeakMap;

/**
 * Caches values built for a file, so each piece of per-file work runs once.
 *
 * @internal
 *
 * @template T
 */
final class FileMemo
{
    /**
     * What the values are built from: the file's text, or its snapshot.
     *
     * Values built from the text alone use the text as the key. Comparing two
     * strings from the same request is a pointer check, and two files almost
     * always differ in length or in their first bytes. The path isn't a good
     * key for them, because an editor session lints the same path again after
     * every edit.
     *
     * Values built from nodes, resolved names or the target list use the
     * snapshot as the key. A snapshot holds only the subtrees needed by its
     * targets, so two snapshots of the same text can hold different nodes, and
     * an analyzer snapshot can come without its text. The SDK decodes a new
     * snapshot for every request and never changes one. The memo keeps a
     * reference to its last key, so PHP can't reuse that object's handle for
     * a new snapshot. That makes a snapshot's identity a safe key.
     */
    private string|SourceFile|null $key = null;

    /**
     * The value of the last key as a one-item list, or an empty list before it's built.
     *
     * Each caller has its own memo. Keeping only the last key is enough,
     * since the rules for one file run one after another. The list form lets
     * a null value count as built.
     *
     * @var list<T>
     */
    private array $value = [];

    /**
     * The values of the other snapshots that still exist, once
     * keepingSnapshots() is called.
     *
     * @var WeakMap<SourceFile, list<T>>|null
     */
    private ?WeakMap $snapshots = null;

    /**
     * Makes the memo also keep the values of every other snapshot that
     * still exists, so another file's request doesn't push them out.
     *
     * Analyzer hooks need this. A hook that waits on the host lets requests
     * for other files run in between.
     *
     * A value must not hold its snapshot. PHP 8.1 and 8.2 never drop a weak
     * map entry whose value holds its key, so every snapshot the worker saw
     * would stay in memory.
     *
     * @return $this
     */
    public function keepingSnapshots(): self
    {
        $this->snapshots ??= new WeakMap();

        return $this;
    }

    /**
     * The value for the key, built again only for another key.
     *
     * The closure costs an allocation per call. Code that runs for every node
     * calls has(), value() and keep() instead.
     *
     * @param string|SourceFile $key The file's text, or its snapshot.
     * @param Closure(): T $build
     *
     * @return T
     */
    public function get(string|SourceFile $key, Closure $build): mixed
    {
        return $this->has($key) ? $this->value() : $this->keep($key, $build());
    }

    /**
     * Whether the memo holds the value for the key.
     *
     * The key becomes the last key, so value() returns its value.
     *
     * @param string|SourceFile $key The file's text, or its snapshot.
     */
    public function has(string|SourceFile $key): bool
    {
        if ($this->key !== $key) {
            $this->switchTo($key);
        }

        return $this->value !== [];
    }

    /**
     * The value of the last key. Call it right after has() returns true.
     *
     * @return T
     */
    public function value(): mixed
    {
        return $this->value[0];
    }

    /**
     * Keeps the value for the key.
     *
     * @param string|SourceFile $key The file's text, or its snapshot.
     * @param T $value
     *
     * @return T
     */
    public function keep(string|SourceFile $key, mixed $value): mixed
    {
        // A hook that waits on the host while it builds the value lets a
        // request for another file run in between. That request may have
        // switched the memo to its own key.
        if ($this->key !== $key) {
            $this->switchTo($key);
        }

        $this->value = [$value];

        return $value;
    }

    /**
     * Makes the key the last key, and stores the old snapshot's value.
     */
    private function switchTo(string|SourceFile $key): void
    {
        if ($this->snapshots !== null && $this->key instanceof SourceFile) {
            $this->snapshots->offsetSet($this->key, $this->value);
        }

        $this->key = $key;
        $this->value = $key instanceof SourceFile ? $this->takeKept($key) : [];
    }

    /**
     * Removes the value kept for the snapshot from the weak map and returns
     * it. Removing it means a later write doesn't copy the array.
     *
     * @return list<T>
     */
    private function takeKept(SourceFile $file): array
    {
        if ($this->snapshots === null || !$this->snapshots->offsetExists($file)) {
            return [];
        }

        $value = $this->snapshots->offsetGet($file);
        $this->snapshots->offsetUnset($file);

        return $value;
    }
}
