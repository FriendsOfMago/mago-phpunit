<?php

/**
 * @file
 * Assertions whose effect depends on the value of an argument.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertNotEmpty;

final class KeyAndCountAssertionsTest extends TestCase
{
    /**
     * @return array{primary?: array{canUpload?: bool}, 3?: string}
     */
    private function build(): array
    {
        return ['primary' => ['canUpload' => true]];
    }

    /**
     * The key is present after `assertArrayHasKey()` (mago#2383).
     */
    public function testArrayHasKey(): bool
    {
        $result = $this->build();
        $this->assertArrayHasKey('primary', $result);
        $primary = $result['primary'];
        self::assertArrayHasKey('canUpload', $primary);

        return $primary['canUpload'];
    }

    /**
     * An integer key, through `Assert::` and the function.
     */
    public function testIntegerKey(): string
    {
        $result = $this->build();
        Assert::assertArrayHasKey(3, $result);
        $copy = $this->build();
        assertArrayHasKey(3, $copy);

        return $result[3] . $copy[3];
    }

    /**
     * A key that's only known at runtime doesn't narrow the type.
     */
    public function testDynamicKey(string $key): bool
    {
        $result = $this->build();
        $this->assertArrayHasKey($key, $result);
        // @mago-expect analysis:possibly-undefined-string-array-index
        $primary = $result['primary'];

        return $primary['canUpload'] ?? false;
    }

    /**
     * The key is gone after `assertArrayNotHasKey()`.
     */
    public function testArrayNotHasKey(): void
    {
        $result = $this->build();
        $this->assertArrayNotHasKey('primary', $result);
        // @mago-expect analysis:undefined-string-array-index
        echo $result['primary'];
    }

    /**
     * The property is there after `assertObjectHasProperty()`.
     */
    public function testObjectHasProperty(object $object): mixed
    {
        $this->assertObjectHasProperty('label', $object);

        return $object->label;
    }

    /**
     * A value of a class type isn't narrowed, so it keeps its class. Mago
     * treats a class type combined with a property type as impossible.
     */
    public function testObjectHasPropertyOnClass(Thing $thing, \stdClass $bag): void
    {
        $this->assertObjectHasProperty('label', $thing);
        $this->assertObjectHasProperty('label', $bag);
        $thing->onlyOnThing();
    }

    /**
     * A list has that many items after `assertCount()`.
     *
     * @param list<string> $items
     */
    public function testCount(array $items): void
    {
        $this->assertCount(2, $items);
        $this->takesNonEmpty($items);
    }

    /**
     * Without the assertion, the list may be empty.
     *
     * @param list<string> $items
     */
    public function testWithoutCount(array $items): void
    {
        // @mago-expect analysis:possibly-invalid-argument
        $this->takesNonEmpty($items);
    }

    /**
     * After `assertNotCount()`, the list may still be empty.
     *
     * @param list<string> $items
     */
    public function testNotCount(array $items): void
    {
        $this->assertNotCount(2, $items);
        // @mago-expect analysis:possibly-invalid-argument
        $this->takesNonEmpty($items);
    }

    /**
     * @param non-empty-list<string> $items
     */
    private function takesNonEmpty(array $items): void {}

    /**
     * The emptiness assertions work as functions too.
     */
    public function testEmptinessFunction(?Thing $thing): void
    {
        assertNotEmpty($thing);
        $thing->onlyOnThing();
    }
}
