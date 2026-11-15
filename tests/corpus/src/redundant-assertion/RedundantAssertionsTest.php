<?php

declare(strict_types=1);

// Assertions that always pass, given the type of the value.
namespace Corpus;

use PHPUnit\Framework\TestCase;

use function Corpus\Helpers\assertNotNull as helperNotNull;
use function PHPUnit\Framework\assertIsString;
use function PHPUnit\Framework\assertNotNull;

/**
 * Asserts facts already known from the types, next to assertions that can
 * fail.
 */
final class RedundantAssertionsTest extends TestCase
{
    /**
     * The thing under test.
     */
    protected Thing $thing;

    /**
     * A thing typed only by a docblock, so it's null until it's set.
     *
     * @var \Corpus\Thing
     */
    protected $documented;

    /**
     * A thing that may be set or cleared by a call.
     *
     * @var \Corpus\Thing|null
     */
    protected $found = null;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        $this->thing = new Thing();
        $this->documented = new Thing();
    }

    /**
     * Each of these can only pass.
     *
     * @param \Corpus\Thing $thing
     *   A thing.
     * @param string $name
     *   A name.
     * @param array $items
     *   Some items.
     */
    public function testAlwaysPasses(Thing $thing, string $name, array $items): void
    {
        // @mago-expect analysis:phpunit/redundant-assertion
        $this->assertInstanceOf(Thing::class, $thing);
        // @mago-expect analysis:phpunit/redundant-assertion
        $this->assertNotNull($thing);
        // @mago-expect analysis:phpunit/redundant-assertion
        self::assertIsString($name);
        // @mago-expect analysis:phpunit/redundant-assertion
        static::assertIsArray($items);
        // @mago-expect analysis:phpunit/redundant-assertion
        $this->assertTrue(true, 'No exception.');
        // @mago-expect analysis:phpunit/redundant-assertion
        $this->assertNotFalse($name);
    }

    // @mago-format-ignore-start
  /**
   * A comment between the method name and its parenthesis.
   *
   * @param \Corpus\Thing $thing
   *   A thing.
   */
  public function testCommentBeforeParenthesis(Thing $thing): void {
    // @mago-expect analysis:phpunit/redundant-assertion
    $this->assertNotNull /* Reason. */ ($thing);
  }
  // @mago-format-ignore-end

    /**
     * Each of these can fail.
     *
     * @param \Corpus\Thing|null $maybe
     *   A thing, or nothing.
     * @param bool $flag
     *   A flag.
     * @param bool $other
     *   Another flag.
     * @param object $any
     *   Any object.
     * @param mixed $value
     *   Anything.
     */
    public function testCanFail(?Thing $maybe, bool $flag, bool $other, object $any, mixed $value): void
    {
        $this->assertInstanceOf(Thing::class, $any);
        $this->assertNotNull($maybe);
        $this->assertTrue($flag);
        $this->assertNotFalse($other);
        $this->assertIsString($value);
    }

    /**
     * Only a property's declared type counts.
     */
    public function testProperties(): void
    {
        // @mago-expect analysis:phpunit/redundant-assertion
        $this->assertNotNull($this->thing);
        // PHP doesn't check a docblock type, so the assertion is the only check.
        $this->assertNotNull($this->documented);
        $this->found = new Thing();
        $this->thing->onlyOnThing();
        // Declared nullable, and the call above may have cleared it.
        $this->assertNotNull($this->found);
        $items = ['a' => 1];
        // An isset() goes through __isset() or ArrayAccess. Neither is described
        // by the type.
        $this->assertTrue(isset($items['a']));
        // The same with a negation in front.
        $this->assertFalse(!isset($items['a']));
        // Mago's ArrayAccess stub doesn't include the null returned for an empty
        // list.
        $this->assertNotNull($items['a']);
    }

    /**
     * An inline docblock type is a claim made by the test. The assertion checks
     * it.
     */
    public function testDocumentedVariable(): void
    {
        /** @var \Corpus\Thing $thing */
        $thing = $this->findThing();
        $this->assertNotNull($thing);
    }

    /**
     * The other forms of an inline `@var` tag count too.
     */
    public function testDocumentedVariableForms(): void
    {
        /** @var array<string, mixed> $config */
        $config = $this->settings();
        $this->assertIsArray($config);
        /** @var array{id: int, name: string} $row */
        $row = $this->settings();
        $this->assertIsArray($row);
        /** @phpstan-var \Corpus\Thing $first */
        $first = $this->findThing();
        $this->assertNotNull($first);
        /** @psalm-var \Corpus\Thing $second */
        $second = $this->findThing();
        $this->assertNotNull($second);
        /** @var \Corpus\Thing */
        $third = $this->findThing();
        $this->assertNotNull($third);
        /**
         * @var \Corpus\Thing
         */
        $fourth = $this->findThing();
        $this->assertNotNull($fourth);
    }

    /**
     * A tag without a name only counts for the assignment right after it.
     */
    public function testUnnamedTagOfOtherVariable(): void
    {
        $thing = new Thing();
        /** @var \Corpus\Thing */
        $other = $this->findThing();
        // @mago-expect analysis:phpunit/redundant-assertion
        $this->assertNotNull($thing);
        $this->assertNotNull($other);
    }

    /**
     * Returns some settings.
     *
     * @return array<array-key, mixed>
     */
    private function settings(): array
    {
        return [];
    }

    /**
     * Finds the thing that may have been set by a call.
     */
    private function findThing(): ?Thing
    {
        return $this->found;
    }

    /**
     * Another receiver's property doesn't count.
     *
     * Only `$this->name` is looked up, even with a property of that name on
     * the test class.
     */
    public function testOtherReceiver(): void
    {
        $other = new \stdClass();
        $other->thing = new Thing();
        $this->assertNotNull($other->thing);
    }

    /**
     * Assertions on the one value allowed by the type.
     */
    public function testOneValue(): void
    {
        // @mago-expect analysis:phpunit/redundant-assertion
        $this->assertNull(null);
        // @mago-expect analysis:phpunit/redundant-assertion
        $this->assertFalse(false);
        // @mago-expect analysis:phpunit/redundant-assertion
        $this->assertNotTrue(false);
    }

    /**
     * One pin for each type assertion.
     *
     * @param int $number
     *   A number.
     * @param float $fraction
     *   A fraction.
     */
    public function testTypes(int $number, float $fraction): void
    {
        // @mago-expect analysis:phpunit/redundant-assertion
        $this->assertIsBool(true);
        // @mago-expect analysis:phpunit/redundant-assertion
        $this->assertIsFloat($fraction);
        // @mago-expect analysis:phpunit/redundant-assertion
        $this->assertIsInt($number);
        // @mago-expect analysis:phpunit/redundant-assertion
        $this->assertIsNumeric($number);
        // @mago-expect analysis:phpunit/redundant-assertion
        $this->assertIsObject($this->thing);
        // @mago-expect analysis:phpunit/redundant-assertion
        $this->assertIsScalar($number);
    }

    /**
     * A `static` or `global` variable keeps its value across calls.
     */
    public function testSharedVariables(): void
    {
        static $seen = null;
        global $_corpus_flag;
        $this->assertNull($seen);
        $seen = 1;
        $this->assertNull($_corpus_flag);
    }

    /**
     * A variable changed through a reference keeps its old type in Mago. The
     * assertion fails when the test runs.
     */
    public function testReferencedVariable(): void
    {
        $value = null;
        $alias = &$value;
        $alias = 5;
        self::assertNull($value);
    }

    /**
     * A variable bound as the alias of another keeps its old type in Mago
     * when the other variable changes. The assertion fails when the test runs.
     */
    public function testReferenceAlias(): void
    {
        $base = 1;
        $alias = &$base;
        $base = null;
        self::assertIsInt($alias);
    }

    /**
     * A reference in a comment, such as &$label, doesn't count.
     *
     * @param string $label
     *   A label.
     */
    public function testCommentedReference(string $label): void
    {
        // @mago-expect analysis:phpunit/redundant-assertion
        self::assertIsString($label);
    }

    /**
     * A variable after a logical `&&` isn't bound by reference.
     */
    public function testVariableAfterLogicalAnd(bool $flag): void
    {
        $thing = new Thing();
        if ($flag && $thing instanceof SpecialThing) {
            $this->addToAssertionCount(1);
        }
        // @mago-expect analysis:phpunit/redundant-assertion
        self::assertNotNull($thing);
    }

    /**
     * A comment with global $label in it doesn't share the variable.
     *
     * @param string $label
     *   A label.
     */
    public function testCommentedGlobal(string $label): void
    {
        // @mago-expect analysis:phpunit/redundant-assertion
        self::assertIsString($label);
    }

    /**
     * A variable set only in a loop is null when the loop doesn't run, so
     * the assertion can fail.
     *
     * @param list<int> $items
     *   The items.
     */
    public function testSetInLoop(array $items): void
    {
        foreach ($items as $item) {
            $found = $item > 0;
            $found = true;
        }

        // @mago-expect analysis:possibly-undefined-variable
        self::assertTrue($found);
    }

    /**
     * PHPUnit's global assertion functions, by import and by full name, and
     * a function of the same name from another namespace.
     *
     * @param string $label
     *   A label.
     */
    public function testGlobalFunctions(Thing $thing, string $label): void
    {
        // @mago-expect analysis:phpunit/redundant-assertion
        assertNotNull($thing);
        // @mago-expect analysis:phpunit/redundant-assertion
        assertIsString($label);
        // @mago-expect analysis:phpunit/redundant-assertion
        \PHPUnit\Framework\assertInstanceOf(Thing::class, $thing);
        helperNotNull($thing);
        assertNotNull($this->findThing());
    }
}
