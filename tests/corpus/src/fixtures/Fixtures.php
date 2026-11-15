<?php

declare(strict_types=1);

namespace Corpus;

class Thing
{
    public function onlyOnThing(): void {}
}

class SpecialThing extends Thing {}

class Other
{
    public function onlyOnOther(): void {}
}

interface ThingLabelInterface
{
    public function label(): string;
}

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

interface Clock
{
    public function now(): int;
}

/**
 * @method string label()
 */
class Magic
{
    public function __call(string $name, array $arguments): mixed
    {
        return null;
    }
}

/**
 * @method string label()
 */
interface Tagged {}

function coveredFunction(): void {}

final class Providers
{
    /**
     * @return iterable<array{int}>
     */
    public static function numbers(): iterable
    {
        yield [1];
    }
}

final class Sealed
{
    public function onlyOnSealed(): void {}
}

class Parts
{
    final public function sealed(): void {}

    protected function inner(): void {}

    // @mago-expect analysis:unused-method
    private function hidden(): void {}

    public static function make(): void {}
}

interface LabelledClock extends ThingLabelInterface, Clock {}

trait Greets
{
    public function greet(): string
    {
        return 'hello';
    }
}

class Greeter
{
    use Greets;
}

final class NoItems extends \EmptyIterator {}
