<?php

/**
 * @file
 * Objects made by getMockForTrait() and getObjectForTrait(). Both methods exist on PHPUnit 10 and 11.
 */

// Corpus: PHPUnit 11 and earlier.

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\TestCase;

trait GreetsByName
{
    abstract public function name(): string;

    public function greet(): string
    {
        return 'Hello ' . $this->name();
    }

    protected function title(): string
    {
        return '';
    }
}

abstract class GreeterBase
{
    protected function title(): string
    {
        return '';
    }
}

final class TraitDoubleTest extends TestCase
{
    /**
     * The mock has the trait's methods, and is a mock.
     */
    public function testMock(): void
    {
        $mock = $this->getMockForTrait(GreetsByName::class);
        $mock->method('name')->willReturn('Ada');
        $this->assertSame('Hello Ada', $mock->greet());
    }

    /**
     * A protected method named in `$mockedMethods` is doubled.
     */
    public function testMockedProtectedMethod(): void
    {
        $mock = $this->getMockForTrait(GreetsByName::class, [], '', true, true, true, ['title']);
        $mock->method('title')->willReturn('Dr');
    }

    /**
     * A protected method named in `$mockedMethods` is doubled, and so is a
     * method missing from the class.
     */
    public function testMockedMethodsOfAbstractClass(): void
    {
        $mock = $this->getMockForAbstractClass(GreeterBase::class, [], '', true, true, true, ['title', 'extra']);
        $mock->method('title')->willReturn('Dr');
        $mock->method('extra')->willReturn(null);
    }

    /**
     * The trait can't have abstract methods here, since the object's class isn't abstract.
     */
    public function testObject(): void
    {
        $object = $this->getObjectForTrait(Greets::class);
        // @mago-expect analysis:phpunit/redundant-assertion
        $this->assertIsString($object->greet());
    }
}
