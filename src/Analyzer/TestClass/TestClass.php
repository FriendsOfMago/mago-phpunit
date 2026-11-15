<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MethodMetadataProjection;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Internal\Declaration\ClassFacts;
use MagoPHPUnit\Internal\PHPUnitFeatures;
use MagoPHPUnit\Internal\Text\Docblocks;

use function str_starts_with;

/**
 * A test class and the data shared by the class checks.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-methods
 */
final class TestClass
{
    private readonly ClassFacts $facts;

    public readonly Codebase $codebase;

    public readonly string $contents;

    public readonly Reporter $reporter;

    private ?bool $readsAnnotations = null;

    /**
     * @param string $text The class's text with its docblock and the short
     *   names of its PHPUnit attributes. Checks match patterns against it to
     *   skip a class without anything to report.
     * @param ?Span $docblock The docblock above the class, or null when there isn't one.
     */
    public function __construct(
        public readonly ClassLikeMetadata $class,
        public readonly NodeAnalysisContext $context,
        public readonly string $text,
        private readonly ?Span $docblock,
    ) {
        $this->codebase = $context->codebase;
        $this->contents = $context->source->contents;
        $this->reporter = new Reporter($context);
        $this->facts = new ClassFacts($class, $context->codebase);
    }

    /**
     * The methods declared by the class.
     *
     * @return list<MethodMetadataProjection>
     */
    public function methods(): array
    {
        return $this->facts->methods();
    }

    /**
     * The method declared by the class with the given name, or null.
     */
    public function method(string $name): ?MethodMetadataProjection
    {
        return $this->facts->method($name);
    }

    /**
     * Whether the location is in the class's file.
     *
     * Spans are only looked up in this file's text. An inherited method can
     * be in another file.
     */
    private function inFile(?SourceLocation $location): bool
    {
        return $location !== null && $location->file === $this->class->location->file;
    }

    /**
     * The class docblock, or null when PHPUnit ignores its annotations.
     *
     * PHPUnit 12 and later ignore all annotations. PHPUnit 10 and 11 ignore
     * them on a class with a PHPUnit attribute.
     */
    public function annotationDocblock(): ?Span
    {
        if (!$this->readsAnnotations() || $this->hasPHPUnitAttribute($this->class)) {
            return null;
        }

        return $this->docblock;
    }

    /**
     * The method docblock, or null when PHPUnit ignores its annotations.
     *
     * The rules are the same as in annotationDocblock(). A method declared in
     * another file gets null too.
     */
    public function methodAnnotationDocblock(MethodMetadataProjection $method): ?Span
    {
        return $this->hasPHPUnitAttribute($method) ? null : $this->methodDocblock($method);
    }

    /**
     * The method docblock on PHPUnit 10 and 11, also on a method with a PHPUnit attribute.
     *
     * A method declared in another file gets null.
     */
    public function methodDocblock(MethodMetadataProjection $method): ?Span
    {
        $location = $method->location;
        if ($location === null || !$this->inFile($location) || !$this->readsAnnotations()) {
            return null;
        }

        return Docblocks::before($this->contents, $location->span->start);
    }

    /**
     * The tags with one of the given names in the method docblock, or none when PHPUnit ignores its annotations.
     *
     * @param list<string> $names
     * @return list<array{name: string, value: string, gap: string, span: Span}>
     */
    public function methodTags(MethodMetadataProjection $method, array $names): array
    {
        $docblock = $this->methodAnnotationDocblock($method);

        return $docblock === null ? [] : Docblocks::tags($this->contents, $docblock, $names);
    }

    /**
     * Whether the method is run as a test by PHPUnit.
     */
    public function isTest(MethodMetadataProjection $method): bool
    {
        // A test method must be public. It must have a lowercase `test` prefix, the `Test`
        // attribute or a `@test` tag. The tag counts only when annotations are parsed by the
        // installed PHPUnit version.
        if ($method->visibility !== null && $method->visibility !== Visibility::Public) {
            return false;
        }

        if (
            str_starts_with($method->originalName ?? '', 'test')
            || PHPUnitAttributes::of($method, name: 'Test') !== []
        ) {
            return true;
        }

        // PHPUnit only counts `@test` when whitespace or the end of the line
        // follows it, so `me@test.com` doesn't count.
        foreach ($this->methodTags($method, ['test']) as $tag) {
            if ($tag['gap'] !== '' || $tag['value'] === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the installed PHPUnit parses annotations, cached for the class.
     */
    private function readsAnnotations(): bool
    {
        return $this->readsAnnotations ??= PHPUnitFeatures::readsAnnotations($this->codebase);
    }

    /**
     * Whether a class or method has an existing attribute from `PHPUnit\Framework\Attributes`.
     */
    private function hasPHPUnitAttribute(MethodMetadataProjection|ClassLikeMetadata $member): bool
    {
        // PHPUnit 10 and 11 skip attributes with a class missing from the installed version.
        // PHPUnit reads the annotations if every attribute is skipped.
        foreach ($member->attributes ?? [] as $attribute) {
            if (
                PHPUnitAttributes::shortName($attribute->name) !== null
                && $this->codebase->classLikeExists($attribute->name)
            ) {
                return true;
            }
        }

        return false;
    }
}
