<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass;

use Mago\Sdk\Span;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Analyzer\TestClass\DataSets\TestWithTag;
use MagoPHPUnit\Internal\Text\Docblocks;

use function implode;
use function preg_match;

/**
 * Reports a PHPUnit annotation without a space and a value after its name.
 *
 * @internal
 */
final class AnnotationSpaceCheck
{
    public const CODE = 'annotation-without-value';

    /**
     * The annotations that take a value.
     */
    private const ANNOTATIONS = [
        'backupGlobals',
        'backupStaticAttributes',
        'covers',
        'coversDefaultClass',
        'dataProvider',
        'depends',
        'group',
        'preserveGlobalState',
        'requires',
        'testdox',
        'testWith',
        'ticket',
        'uses',
    ];

    private function __construct() {}

    /**
     * Checks the class docblock and the docblock of each method.
     */
    public static function check(TestClass $test): void
    {
        // PHPUnit 12 and later ignore annotations. The docblocks are null for those versions.
        // Issues aren't reported there.
        // Examples are `@covers\Foo` and a bare `@dataProvider`. PHPUnit ignores the first one
        // without a warning. PHPUnit reads the second one with an empty provider name. PHPUnit
        // fails that test. This matches phpstan-phpunit's `NoMissingSpaceInClassAnnotationRule` and
        // `NoMissingSpaceInMethodAnnotationRule`.
        self::checkDocblock($test, $test->annotationDocblock());
        // Skip the method docblocks when the class text doesn't mention any of
        // the tags.
        if (preg_match('/@(?:' . implode('|', self::ANNOTATIONS) . ')/i', $test->text) !== 1) {
            return;
        }

        foreach ($test->methods() as $method) {
            self::checkDocblock($test, $test->methodAnnotationDocblock($method));
        }
    }

    /**
     * Reports each annotation in the docblock without a space and a value.
     */
    private static function checkDocblock(TestClass $test, ?Span $docblock): void
    {
        if ($docblock === null) {
            return;
        }

        foreach (Docblocks::tags($test->contents, $docblock, self::ANNOTATIONS, Docblocks::LETTERS) as $tag) {
            if ($tag['gap'] !== '' && $tag['value'] !== '') {
                continue;
            }

            // PHPUnit reads the rows of a bare `@testWith` from the next lines.
            // A tag without rows is reported in TestWithCheck.
            if ($tag['name'] === 'testWith' && $tag['value'] === '' && TestWithTag::found($test->contents, $docblock)) {
                continue;
            }

            $test->reporter->warning(self::CODE, Reporter::issue(
                "@{$tag['name']} must be followed by a space and a value.",
                where: $tag['span'],
                help: 'PHPUnit ignores the annotation as written, or reads it with an empty value.',
            ));
        }
    }
}
