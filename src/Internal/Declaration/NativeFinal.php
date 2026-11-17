<?php

declare(strict_types=1);

namespace MagoPHPUnit\Internal\Declaration;

use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;

use function file_get_contents;
use function is_file;
use function max;
use function preg_match;

/**
 * Tells whether a class is declared with the `final` keyword.
 *
 * Mago sets the final flag for a `@final` docblock tag too. PHP and
 * reflection only see the keyword.
 *
 * @internal
 */
final class NativeFinal
{
    /**
     * How many bytes before the class name are read. The modifiers and the
     * `class` keyword are just before the name.
     */
    private const LOOK_BACK = 256;

    /**
     * Matches modifiers with `final` and the `class` keyword at the end of
     * the text. The modifiers start a line or follow the open tag, a
     * statement, an attribute or a comment block, so a `final` in a line
     * comment doesn't count.
     */
    private const MODIFIERS = '/(?:^|[;{}\]\n]|\*\/|<\?php\s)[ \t]*(?:(?:abstract|readonly)\s+)*final\s+(?:(?:abstract|readonly)\s+)*class\s+$/i';

    /**
     * The answers by file and declaration span.
     *
     * @var array<string, bool>
     */
    private array $known = [];

    /**
     * Whether the class has the `final` keyword.
     *
     * A built-in class is final when its flag says so. For any other class,
     * the text before the class name is read from its file. The answer is
     * false when the file can't be read.
     */
    public function is(ClassLikeMetadata $class): bool
    {
        if (!$class->flags->contains(MetadataFlags::FINAL)) {
            return false;
        }

        if ($class->flags->contains(MetadataFlags::BUILTIN)) {
            return true;
        }

        $file = $class->location->file;
        $name = $class->nameLocation?->span->start;
        if ($file === null || $name === null) {
            return false;
        }

        $key = "{$file}:{$class->location->span->start}:{$name}";

        return $this->known[$key] ??= self::keyword($file, $name);
    }

    /**
     * Whether the modifiers before the class name at the offset include `final`.
     */
    private static function keyword(string $file, int $name): bool
    {
        if (!is_file($file)) {
            return false;
        }

        $start = max(0, $name - self::LOOK_BACK);
        $text = file_get_contents($file, offset: $start, length: $name - $start);

        return $text !== false && preg_match(self::MODIFIERS, $text) === 1;
    }
}
