<?php

declare(strict_types=1);

namespace MagoPHPUnit\Internal\Declaration;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MethodMetadataProjection;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\SourceFile;
use MagoPHPUnit\Internal\Cache\FileMemo;

use function array_key_exists;
use function strtolower;

/**
 * Finds the class and method declared around a span.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final class FileMembers
{
    /**
     * @var list<ClassLikeMetadata>|null
     */
    private ?array $classes = null;

    /**
     * @var array<string, list<MethodMetadataProjection>>
     */
    private array $methods = [];

    /**
     * @var FileMemo<self>|null
     */
    private static ?FileMemo $memo = null;

    private function __construct() {}

    /**
     * The lookups for the snapshot, shared by the hooks of one request.
     *
     * The key is the snapshot, not the text, because the codebase results
     * belong to the snapshot's analysis.
     */
    public static function of(SourceFile $file): self
    {
        self::$memo ??= (new FileMemo())->keepingSnapshots();

        return self::$memo->get($file, static fn(): self => new self());
    }

    /**
     * The innermost class declared in the file whose span holds the span.
     *
     * A method-call hook only gets the call node, so the enclosing class is
     * found from the locations of the classes declared in the file.
     */
    public function classAt(Codebase $codebase, SourceFile $file, Span $span): ?ClassLikeMetadata
    {
        $found = null;
        foreach ($this->classes($codebase, $file) as $class) {
            $location = $class->location->span;
            if (!$location->contains($span)) {
                continue;
            }

            if ($found === null || $found->location->span->contains($location)) {
                $found = $class;
            }
        }

        // Inside an anonymous class, `$this` and `self` are that class. It
        // doesn't have a metadata name, so null is returned.
        if ($found !== null && ClassLikeRanges::mayHoldAnonymous($file->contents)) {
            return ClassLikeRanges::inAnonymous($file->contents, $span) ? null : $found;
        }

        // A node hook's snapshot only has the names inside its targets. An
        // interface or enum that isn't a target doesn't have a name there, so
        // the file's tokens are used instead.
        return $found ?? ClassLikeRanges::classAt($codebase, $file->contents, $span);
    }

    /**
     * The method declared in the class whose span holds the span.
     */
    public function methodAt(Codebase $codebase, ClassLikeMetadata $class, Span $span): ?MethodMetadataProjection
    {
        foreach ($this->methods($codebase, $class) as $method) {
            if ($method->location !== null && $method->location->span->contains($span)) {
                return $method;
            }
        }

        return null;
    }

    /**
     * The methods declared in the class itself, with their attributes.
     *
     * @return list<MethodMetadataProjection>
     */
    private function methods(Codebase $codebase, ClassLikeMetadata $class): array
    {
        $key = strtolower($class->name);
        if (!array_key_exists($key, $this->methods)) {
            // Uses the same field mask as ClassFacts, so both get the same
            // cached result from the codebase.
            $this->methods[$key] = $codebase->findMethods(
                class: $class->name,
                fields: ClassFacts::METHOD_FIELDS,
                declaredOnly: true,
            );
        }

        return $this->methods[$key];
    }

    /**
     * The classes declared in the file, from its resolved names.
     *
     * @return list<ClassLikeMetadata>
     */
    private function classes(Codebase $codebase, SourceFile $file): array
    {
        if ($this->classes !== null) {
            return $this->classes;
        }

        $candidates = FileNames::of($file)->within(span: null);
        $classes = [];
        foreach ($candidates === [] ? [] : $codebase->getMultipleClassLikes($candidates) as $class) {
            if ($class === null || !self::inFile($class, $file)) {
                continue;
            }

            $classes[] = $class;
        }

        return $this->classes = $classes;
    }

    /**
     * Whether the class is declared in the file.
     */
    private static function inFile(ClassLikeMetadata $class, SourceFile $file): bool
    {
        return $class->location->file === $file->path;
    }
}
