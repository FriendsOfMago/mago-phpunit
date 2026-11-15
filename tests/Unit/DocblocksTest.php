<?php

declare(strict_types=1);

namespace MagoPHPUnit\Tests;

use MagoPHPUnit\Internal\Text\Docblocks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function strpos;
use function substr;

#[CoversClass(Docblocks::class)]
final class DocblocksTest extends TestCase
{
    /**
     * A docblock above a method's modifiers is found.
     */
    public function testDocblockAboveModifiers(): void
    {
        $code = "<?php\nclass A {\n    /**\n     * Doc.\n     */\n    public function a() {}\n}\n";
        $span = Docblocks::before($code, (int) strpos($code, needle: 'public'));

        self::assertNotNull($span);
        self::assertSame("/**\n     * Doc.\n     */", substr($code, $span->start, $span->length()));
    }

    /**
     * A docblock above attribute groups is found.
     */
    public function testDocblockAboveAttributes(): void
    {
        $code = "<?php\n/** Doc. */\n#[A([1, 2])]\n#[B]\nfinal class C {}\n";
        $span = Docblocks::before($code, (int) strpos($code, needle: 'final'));

        self::assertNotNull($span);
        self::assertSame('/** Doc. */', substr($code, $span->start, $span->length()));
        // The same docblock is found from the text before the class alone,
        // and from the cached tokens of the whole file.
        self::assertEquals($span, Docblocks::beforeFirst(
            $code . "\n/** Later. */",
            (int) strpos($code, needle: 'final'),
        ));
        self::assertEquals($span, Docblocks::beforeFirst($code, (int) strpos($code, needle: 'final')));
    }

    /**
     * A docblock above `//` and `#` comment lines is found.
     */
    public function testDocblockAboveLineComment(): void
    {
        $code = "<?php\n/** Doc. */\n// A note.\n# Another.\nfunction f() {}\n";
        $span = Docblocks::before($code, (int) strpos($code, needle: 'function'));

        self::assertNotNull($span);
        self::assertSame('/** Doc. */', substr($code, $span->start, $span->length()));
    }

    /**
     * A `/*` in a docblock's text doesn't start another comment.
     */
    public function testDocblockWithAGlob(): void
    {
        $code = "<?php\n/**\n * Reads src/*.php.\n */\nfunction f() {}\n";
        $span = Docblocks::before($code, (int) strpos($code, needle: 'function'));

        self::assertNotNull($span);
        self::assertSame("/**\n * Reads src/*.php.\n */", substr($code, $span->start, $span->length()));
    }

    /**
     * A `/**` in a docblock's text doesn't start another docblock.
     */
    public function testDocblockWithAnInnerStart(): void
    {
        $code = "<?php\n/** A. */\n\$a = 1;\n/**\n * @dataProvider p\n * See src/** for details.\n */\nfunction f() {}\n";
        $span = Docblocks::before($code, (int) strpos($code, needle: 'function'));

        self::assertNotNull($span);
        self::assertSame((int) strpos($code, needle: "/**\n"), $span->start);
    }

    /**
     * A `/*` in a string above the docblock doesn't start a comment.
     */
    public function testDocblockAfterAStringWithACommentStart(): void
    {
        $code = "<?php\n\$a = 'image/*';\n/** Doc. */\nfunction f() {}\n";
        $span = Docblocks::before($code, (int) strpos($code, needle: 'function'));

        self::assertNotNull($span);
        self::assertSame('/** Doc. */', substr($code, $span->start, $span->length()));
    }

    /**
     * A bracket in a string inside an attribute group doesn't end the group.
     */
    public function testDocblockAboveAttributeWithBracketInString(): void
    {
        $code = "<?php\n/** Doc. */\n#[A('[')]\nfunction f() {}\n";
        $span = Docblocks::before($code, (int) strpos($code, needle: 'function'));

        self::assertNotNull($span);
        self::assertSame('/** Doc. */', substr($code, $span->start, $span->length()));
    }

    /**
     * A plain block comment right above a declaration isn't a docblock.
     */
    public function testPlainCommentIsNotADocblock(): void
    {
        $code = "<?php\n/** Other. */\n/* Plain. */\nfunction f() {}\n";

        self::assertNull(Docblocks::before($code, (int) strpos($code, needle: 'function')));
    }

    /**
     * A docblock above an earlier statement doesn't belong to the declaration.
     */
    public function testNoDocblock(): void
    {
        $code = "<?php\n/** Other. */\n\$a = 1;\nfunction f() {}\n";

        self::assertNull(Docblocks::before($code, (int) strpos($code, needle: 'function')));
    }

    /**
     * Only the named tags are returned, with their value, gap and span.
     */
    public function testTags(): void
    {
        $code = "<?php\n/**\n * @covers \\Foo::bar\n * @group\n * @dataProviderfoo\n * @covers\\Baz */\nclass A {}\n";
        $span = Docblocks::before($code, (int) strpos($code, needle: 'class A'));
        self::assertNotNull($span);

        $tags = Docblocks::tags($code, $span, ['covers', 'group', 'dataProvider']);
        self::assertCount(3, $tags);
        self::assertSame(['covers', '\Foo::bar', ' '], [$tags[0]['name'], $tags[0]['value'], $tags[0]['gap']]);
        self::assertSame('@covers \Foo::bar', substr($code, $tags[0]['span']->start, $tags[0]['span']->length()));
        self::assertSame(['group', '', ''], [$tags[1]['name'], $tags[1]['value'], $tags[1]['gap']]);
        self::assertSame(['covers', '\Baz', ''], [$tags[2]['name'], $tags[2]['value'], $tags[2]['gap']]);
        self::assertSame('@covers\Baz', substr($code, $tags[2]['span']->start, $tags[2]['span']->length()));
    }
}
