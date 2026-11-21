<?php

declare(strict_types=1);

namespace MagoPHPUnit\Tests;

use Mago\Sdk\Span;
use MagoPHPUnit\Linter\AssertionNames;
use MagoPHPUnit\Linter\NameMentions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function strlen;
use function strpos;
use function strtoupper;

#[CoversClass(NameMentions::class)]
final class NameMentionsTest extends TestCase
{
    /**
     * A span has a mention when a name starts inside it.
     */
    public function testANameCountsWhereItStarts(): void
    {
        $text = '<?php $this->assertSame(1, $value); $this->save();';
        $mentions = new NameMentions($text);
        $name = (int) strpos($text, needle: 'assertSame');

        self::assertTrue($mentions->within(new Span(0, strlen($text))));
        self::assertTrue($mentions->within(new Span($name, $name + 1)));
        self::assertFalse($mentions->within(new Span(0, $name)));
        self::assertFalse($mentions->within(new Span($name + 1, strlen($text))));
    }

    /**
     * Every assertion and helper name is found, in any case.
     */
    public function testEveryNameIsFoundInAnyCase(): void
    {
        foreach (array_keys(AssertionNames::ASSERTIONS + AssertionNames::HELPERS) as $name) {
            foreach ([$name, strtoupper($name)] as $written) {
                $text = "\$this->{$written}();";

                self::assertTrue((new NameMentions($text))->within(new Span(0, strlen($text))), $written);
            }
        }
    }

    /**
     * A name next to a comment, without a space between them, still counts.
     */
    public function testANameNextToACommentCounts(): void
    {
        $text = '$this->/* c */assertEquals/* d */(1, 2);';

        self::assertTrue((new NameMentions($text))->within(new Span(0, strlen($text))));
    }

    /**
     * A longer or a shorter word than a name isn't a mention.
     */
    public function testOnlyAWholeNameCounts(): void
    {
        foreach (['$this->assertSession();', '$this->assertSameSite();', '$this->assert();', 'failure();'] as $text) {
            self::assertFalse((new NameMentions($text))->within(new Span(0, strlen($text))), $text);
        }
    }

    /**
     * A text without any name doesn't have a mention in any span.
     */
    public function testATextWithoutNames(): void
    {
        $mentions = new NameMentions('<?php echo 1;');

        self::assertFalse($mentions->within(new Span(0, 13)));
        self::assertFalse($mentions->within(new Span(20, 30)));
    }
}
