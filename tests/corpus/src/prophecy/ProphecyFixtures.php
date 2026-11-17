<?php

/**
 * @file
 * Classes and interfaces prophesized by the Prophecy cases only.
 */

declare(strict_types=1);

namespace Corpus;

/**
 * An interface that extends Traversable without Iterator or IteratorAggregate.
 *
 * @extends \Traversable<int, mixed>
 */
interface ThingListInterface extends \Traversable {}

/**
 * An interface that is traversable through an iterator aggregate.
 *
 * @extends \IteratorAggregate<int, mixed>
 */
interface ThingAggregateInterface extends \IteratorAggregate {}

interface Mailbox
{
    public function send(Thing $thing, string $subject = ''): bool;

    public function sendAll(string $subject, Thing ...$things): int;

    /**
     * @param list<Thing> $things
     */
    public function sendList(array $things): void;

    public function find(int $id): ?Thing;

    /**
     * @return list<Thing>
     */
    public function findAll(): array;

    public function withSubject(string $subject): static;

    /**
     * @return $this
     */
    public function copyTo(string $address);

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function make(string $class): object;
}

interface Outbox
{
    public function fail(): never;

    public function queue(): \Countable&\Traversable;

    public function pending(): (\Countable&\Traversable)|null;

    public function count(): int;
}

enum Priority
{
    case High;
}

/**
 * @final
 */
class SealedByDocblock {}

interface Ledger
{
    public function total(): ?int;

    public function closed(): bool;

    public function label(): string;

    public function open(): true;

    public function balance(): int|false;

    /**
     * @return int
     */
    public function legacy();
}

interface Counter
{
    public function step(int $by): int;
}

interface Namer
{
    public function step(string $by): string;
}

interface Measure
{
    public function size(int $unit): int;
}

class Ruler implements Measure
{
    public function size(int|string $unit): int
    {
        return 1;
    }
}

/**
 * @final
 */
// final
class SoftFinal {}

abstract class Drawer
{
    abstract protected function open(): int;

    public function _fill(): void {}
}

interface Relay
{
    public function pass(string $message): string;

    public function swap(Thing $thing): Other;

    /**
     * @return \Generator<int, Thing>
     */
    public function things(): \Generator;

    public function name(): string;
}

interface Loader
{
    public function _load(): int;
}

/**
 * @method int _peek()
 */
class Stack
{
    public function _load(): int
    {
        return 1;
    }

    public static function create(): self
    {
        return new self();
    }

    public function pick(int $index, string $fallback): int
    {
        return $index;
    }

    public function __call(string $name, array $arguments): mixed
    {
        return null;
    }
}

class Label
{
    public function __toString(): string
    {
        return 'label';
    }
}

interface Scale
{
    public function weigh(float $grams): int;

    public function tag(Label $label): int;

    public function stamp(\DateTimeImmutable $at): int;

    public function sort(array $items): int;
}
