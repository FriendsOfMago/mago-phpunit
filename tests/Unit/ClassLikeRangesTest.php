<?php

declare(strict_types=1);

namespace MagoPHPUnit\Tests;

use MagoPHPUnit\Internal\Declaration\ClassLikeRanges;
use MagoPHPUnit\Internal\Text\PhpTokens;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function preg_match;
use function strpos;

#[CoversClass(ClassLikeRanges::class)]
final class ClassLikeRangesTest extends TestCase
{
    /**
     * Files and whether mayDeclare() accepts them.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function declarations(): iterable
    {
        yield 'a line of its own' => ["<?php\n\nfinal class Foo {}\n", true];
        yield 'after an attribute on its line' => ["<?php\n\n#[\\Attribute] final class Foo {}\n", true];
        yield 'after a comment on its line' => ["<?php\n\n/* Old. */ abstract class Foo {}\n", true];
        yield 'a comment between its tokens' => ["<?php\n\nfinal /* x */ readonly # y\nclass\n// z\nFoo {}\n", true];
        yield 'after the open tag' => ['<?php interface Foo {}', true];
        yield 'after another statement' => ["<?php\n\nfoo(); enum Foo {}\n", true];
        yield 'keywords in any case' => ["<?php\n\nFINAL CLASS Foo {}\n", true];
        yield 'a procedural file' => ["<?php\n\nfunction foo(): string {\n  return Foo::class;\n}\n", false];
        yield 'an anonymous class' => ["<?php\n\n\$foo = new class {};\n", false];
        yield 'a named argument' => ["<?php\n\nfoo(class: 'Foo');\n", false];
    }

    /**
     * mayDeclare() accepts a file only when it can declare a named class-like.
     */
    #[DataProvider('declarations')]
    public function testScreensTheFilesThatCanDeclareAClassLike(string $contents, bool $declares): void
    {
        self::assertSame($declares, ClassLikeRanges::mayDeclare($contents));
    }

    /**
     * Files and whether mayHoldAnonymous() accepts them.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function anonymous(): iterable
    {
        yield 'new class' => ['<?php $a = new class {};', true];
        yield 'an attribute first' => ['<?php $a = new #[A] class {};', true];
        yield 'readonly first' => ['<?php $a = new readonly class {};', true];
        yield 'a comment between' => ["<?php \$a = new /* x */ class {};\n\$b = new // y\nclass {};", true];
        yield 'keywords in any case' => ['<?php $a = NEW CLASS {};', true];
        yield 'a named class' => ['<?php $a = new Foo();', false];
        yield 'a name that starts with the keyword' => ['<?php $a = new classes();', false];
    }

    /**
     * mayHoldAnonymous() accepts a file only when it can hold `new class`.
     */
    #[DataProvider('anonymous')]
    public function testScreensTheFilesThatCanHoldAnAnonymousClass(string $contents, bool $holds): void
    {
        self::assertSame($holds, ClassLikeRanges::mayHoldAnonymous($contents));
    }

    /**
     * A `"${name}"` interpolation in an anonymous class doesn't end its
     * range or the range of the class around it.
     */
    public function testAnInterpolationKeepsTheClassBodyOpen(): void
    {
        $contents = <<<'PHP'
            <?php

            final class Outer
            {
                public function a(): object
                {
                    return new class {
                        public function b(string $name): string
                        {
                            return "Hi ${name}";
                        }

                        public function c(): void { /* anonymous */ }
                    };
                }

                public function d(): void { /* outer */ }
            }
            PHP;
        $ranges = ClassLikeRanges::scan(PhpTokens::of($contents));

        self::assertNull($ranges->at((int) strpos($contents, needle: '/* anonymous */')));
        self::assertSame('Outer', $ranges->at((int) strpos($contents, needle: '/* outer */')));
    }

    /**
     * Code between a callee and its parenthesis, and whether GAP matches all
     * of it.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function gaps(): iterable
    {
        yield 'nothing' => ['', true];
        yield 'whitespace' => [" \t\r\n", true];
        yield 'block comment' => ['/* a ( */', true];
        yield 'docblock' => ["/**\n * a\n */", true];
        yield 'slash comment' => ["// a (\n", true];
        yield 'hash comment' => ["# a (\n", true];
        yield 'all three kinds' => ["/* a */ // b\n # c\n", true];
        yield 'close tag in a slash comment' => ["// a ?>\n", false];
        yield 'close tag in a hash comment' => ["# a ?>\n", false];
        yield 'question mark in a comment' => ["// a?\n", true];
        yield 'attribute' => ['#[A]', false];
        yield 'code' => ['x', false];
    }

    /**
     * GAP matches whitespace and comments between two tokens, and nothing else.
     */
    #[DataProvider('gaps')]
    public function testMatchesTheGapBetweenTwoTokens(string $gap, bool $matches): void
    {
        self::assertSame($matches, preg_match('/\Gf' . ClassLikeRanges::GAP . '\(/', 'f' . $gap . '(') === 1);
    }
}
