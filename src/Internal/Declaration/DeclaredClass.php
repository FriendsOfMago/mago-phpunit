<?php

declare(strict_types=1);

namespace MagoPHPUnit\Internal\Declaration;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\SourceFile;

use function array_slice;

/**
 * Finds the class declared by a class node, without the node's children.
 *
 * @internal
 */
final class DeclaredClass
{
    private function __construct() {}

    /**
     * The metadata of the class-like declared at the node's span, or null when it isn't found.
     */
    public static function resolve(SourceFile $file, Node $node, Codebase $codebase): ?ClassLikeMetadata
    {
        // Without a subtree request, only the node span and resolved file names are available
        // in a lifecycle hook. Bare names are resolved against the namespace. Imported names
        // are flagged. Unimported names include the declared name, parents and traits written
        // without a `use`. Called functions and constants are included too.
        $candidates = FileNames::of($file)->within($node->span);
        if ($candidates === []) {
            return null;
        }

        // The declared name comes first in source order, unless an unimported
        // attribute comes before it. The other names are only fetched in that
        // case.
        $first = $codebase->getClassLike($candidates[0]);
        // The declared class is the candidate with a location starting at the node span.
        if ($first !== null && self::declaredAt($first, $file, $node->span)) {
            return $first;
        }

        $others = array_slice($candidates, offset: 1);
        foreach ($others === [] ? [] : $codebase->getMultipleClassLikes($others) as $class) {
            if ($class !== null && self::declaredAt($class, $file, $node->span)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Whether the class-like is the one declared at the span.
     *
     * A location's file is the same path as the snapshot's. A declaration's
     * location includes its attributes, the same as the node span, so the
     * start offsets match.
     */
    private static function declaredAt(ClassLikeMetadata $class, SourceFile $file, Span $span): bool
    {
        $location = $class->location;

        return $location->file === $file->path && $location->span->start === $span->start;
    }
}
