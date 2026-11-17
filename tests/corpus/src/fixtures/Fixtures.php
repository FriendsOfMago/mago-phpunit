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
