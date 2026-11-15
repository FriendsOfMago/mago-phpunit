<?php

declare(strict_types=1);

namespace MagoPHPUnit\Tests;

use MagoPHPUnit\Internal\Text\CodeMask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function strlen;

#[CoversClass(CodeMask::class)]
final class CodeMaskTest extends TestCase
{
    /**
     * Sources with their comments mask and their code mask.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function files(): iterable
    {
        yield 'comments of all three kinds' => [
            "<?php\nfoo /* a */ (// b\n# c\n);\n",
            "<?php\nfoo         (    \n   \n);\n",
            "<?php\nfoo         (    \n   \n);\n",
        ];
        yield 'docblock' => [
            "<?php\n/**\n * @var int\n */\n\$a = 1;\n",
            "<?php\n   \n           \n   \n\$a = 1;\n",
            "<?php\n   \n           \n   \n\$a = 1;\n",
        ];
        yield 'close tag inside a line comment' => [
            "<?php // a ?> b(); <?php c();\n",
            "<?php      ?>      <?php c();\n",
            "<?php      ?>      <?php c();\n",
        ];
        yield 'attribute' => [
            "<?php\n#[Attr('x')] # y\nfunction f() {}\n",
            "<?php\n#[Attr('x')]    \nfunction f() {}\n",
            "<?php\n#[Attr(' ')]    \nfunction f() {}\n",
        ];
        yield 'heredoc' => [
            "<?php\n\$a = <<<EOT\n  f(\$b); // c\n  EOT;\n",
            "<?php\n\$a = <<<EOT\n  f(\$b); // c\n  EOT;\n",
            "<?php\n\$a = <<<EOT\n    \$b       \n  EOT;\n",
        ];
        yield 'nowdoc' => [
            "<?php\n\$a = <<<'EOT'\n  f(\$b); // c\n  EOT;\n",
            "<?php\n\$a = <<<'EOT'\n  f(\$b); // c\n  EOT;\n",
            "<?php\n\$a = <<<'EOT'\n             \n  EOT;\n",
        ];
        yield 'alternative syntax' => [
            "<?php if (\$a): // x\nf(); endif; ?>\n<p>Text</p>\n",
            "<?php if (\$a):     \nf(); endif; ?>\n           \n",
            "<?php if (\$a):     \nf(); endif; ?>\n           \n",
        ];
        yield 'strings with code-like text' => [
            "<?php \$a = 'f(); // b' . \"/* c */ \$d\" . b'e';\n",
            "<?php \$a = 'f(); // b' . \"/* c */ \$d\" . b'e';\n",
            "<?php \$a = '         ' . \"        \$d\" . b' ';\n",
        ];
    }

    /**
     * Comments and literal text are blanked in the code mask, and the length stays the same.
     */
    #[DataProvider('files')]
    public function testMasksAFile(string $contents, string $comments, string $code): void
    {
        self::assertSame($code, CodeMask::code($contents));
        self::assertSame(strlen($contents), strlen(CodeMask::code($contents)));
    }
}
