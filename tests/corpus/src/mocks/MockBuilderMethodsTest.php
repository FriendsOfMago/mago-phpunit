<?php

/**
 * @file
 * A mock builder can select a protected method. PHPUnit throws for a method
 * given to `onlyMethods()` that isn't found by reflection.
 */

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\TestCase;

final class MockBuilderMethodsTest extends TestCase
{
    public function testProtected(): void
    {
        $mock = $this->getMockBuilder(Parts::class)->onlyMethods(['inner'])->getMock();
        $mock->method('inner');
        // A final method is still reported.
        // @mago-expect analysis:phpunit/mock-undefined-method
        $mock->method('sealed');
    }

    public function testOnlyMethods(): void
    {
        // @mago-expect analysis:phpunit/mock-undefined-method
        $this->getMockBuilder(Parts::class)->onlyMethods(['inner', 'missing']);
        // A method from an `@method` tag isn't found by reflection.
        // @mago-expect analysis:phpunit/mock-undefined-method
        $this->getMockBuilder(Tagged::class)->onlyMethods(['label']);
        // A method is found by reflection in any letter case. A private method is found too.
        $this->getMockBuilder(Parts::class)->onlyMethods(['INNER', 'hidden', 'make']);
        $this->getMockBuilder(Parts::class)->onlyMethods([]);
        $this->addToAssertionCount(1);
    }

    /**
     * A class that isn't a literal isn't checked, since it may be a subclass.
     * Names that aren't literals aren't checked.
     *
     * @param class-string<Parts> $class
     * @param non-empty-string $name
     */
    public function testUnknown(string $class, string $name): void
    {
        $this->getMockBuilder($class)->onlyMethods(['missing']);
        $this->getMockBuilder(Parts::class)->onlyMethods([$name]);
        $this->addToAssertionCount(1);
    }

    /**
     * A real method behind an `@method` tag and a private method of a parent
     * class are found by reflection.
     */
    public function testInherited(): void
    {
        $this->getMockBuilder(RetaggedThing::class)->onlyMethods(['onlyOnThing']);
        $this->createPartialMock(RetaggedThing::class, ['onlyOnThing']);
        $this->getMockBuilder(PartsChild::class)->onlyMethods(['hidden']);
        $this->addToAssertionCount(1);
    }

    public function testNames(bool $flag): void
    {
        // @mago-expect analysis:invalid-argument
        // @mago-expect analysis:phpunit/mock-undefined-method
        $this->getMockBuilder(Parts::class)->onlyMethods(['']);
        // @mago-expect analysis:phpunit/mock-undefined-method
        $this->getMockBuilder(Parts::class)->onlyMethods(['missing', 'MISSING']);
        // @mago-expect analysis:phpunit/mock-undefined-method
        $this->getMockBuilder(Parts::class)->onlyMethods([$flag ? 'inner' : 'gone']);
        // A numeric name is looked up on the parent class too.
        // @mago-expect analysis:phpunit/mock-undefined-method
        $this->getMockBuilder(PartsChild::class)->onlyMethods(['123']);
        // A builder kept in a variable isn't checked, since its class may be a subclass.
        $builder = $this->getMockBuilder(Parts::class);
        $builder->onlyMethods(['gone']);
        $this->addToAssertionCount(1);
    }
}

/**
 * @method void onlyOnThing()
 */
class RetaggedThing extends Thing {}

class PartsChild extends Parts {}
