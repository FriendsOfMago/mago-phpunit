<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass;

use Mago\Sdk\Analyzer\ClassLikeAnalysisHook;
use Mago\Sdk\Analyzer\ClassLikeTarget;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\NodeKind;
use MagoPHPUnit\Analyzer\TestClass\Covers\CoversCheck;
use MagoPHPUnit\Analyzer\TestClass\DataSets\DataProviderCheck;
use MagoPHPUnit\Analyzer\TestClass\DataSets\DataProviderDataCheck;
use MagoPHPUnit\Analyzer\TestClass\DataSets\DependencyCheck;
use MagoPHPUnit\Analyzer\TestClass\DataSets\TestWithCheck;
use MagoPHPUnit\Internal\Declaration\DeclaredClass;
use MagoPHPUnit\Internal\Text\Docblocks;

use function preg_match;
use function stripos;
use function strlen;
use function substr;

/**
 * Runs the class checks on every class that extends PHPUnit's `TestCase`.
 *
 * @internal
 */
final class TestClassHook implements ClassLikeAnalysisHook
{
    /**
     * The annotations and the names of the PHPUnit attributes read by the checks.
     */
    private const CHECKED = '@(?:backup|covers|dataProvider|depends|group|preserveGlobalState|requires|testdox|testWith|ticket|uses)|DataProvider|TestWith|Depends|Covers(?:Class|Trait|Method|Function)|Requires';

    /**
     * Text that at least one check can report on: annotations, the names of
     * the checked PHPUnit attributes, and `setUp()` or `tearDown()`.
     */
    private const MAY_REPORT = '/' . self::CHECKED . '|set[Uu]p|tear[Dd]own/';

    /**
     * Text read by a check other than ParentCallCheck. Those checks match
     * names without case, so this pattern does too.
     */
    private const BEYOND_PARENT_CALLS = '/' . self::CHECKED . '/i';

    /**
     * Targets every subclass of `TestCase`.
     */
    public function getTargets(): array
    {
        return [ClassLikeTarget::descendantsOf('PHPUnit\Framework\TestCase')];
    }

    /**
     * Requests the file text, since the checks match patterns against it.
     */
    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText];
    }

    /**
     * Runs each class check on a test class.
     */
    public function analyze(NodeAnalysisContext $context): void
    {
        if ($context->node->kind !== NodeKind::Class_) {
            return;
        }

        // The class node's span doesn't include its docblock, so the text
        // starts at the docblock.
        $source = $context->source;
        $span = $context->node->span;
        $docblock = Docblocks::beforeFirst($source->contents, $span->start);
        $start = $docblock->start ?? $span->start;
        $text = substr($source->contents, $start, $span->end - $start) . self::attributeNames($context);
        // Skip a class without anything to report, so a plain test class costs
        // one pattern match.
        if (preg_match(self::MAY_REPORT, $text) !== 1) {
            return;
        }

        // Most test classes only have a `setUp()` that starts with the
        // parent call. That's read from the text, so such a class doesn't
        // cost a query.
        if (preg_match(self::BEYOND_PARENT_CALLS, $text) !== 1 && ParentCallCheck::neverMissing($source->contents)) {
            return;
        }

        $class = DeclaredClass::resolve($source, $context->node, $context->codebase);
        if ($class === null) {
            return;
        }

        $test = new TestClass($class, $context, $text, $docblock);
        DataProviderCheck::check($test);
        DataProviderDataCheck::check($test);
        TestWithCheck::check($test);
        DependencyCheck::check($test);
        ParentCallCheck::check($test);
        CoversCheck::check($test);
        RequiresCheck::check($test);
        AnnotationSpaceCheck::check($test);
    }

    /**
     * The short names of the PHPUnit attributes in the class, one per line.
     *
     * An attribute imported under an alias only appears by the alias in the
     * text. Adding its real name lets the check patterns match it.
     */
    private static function attributeNames(NodeAnalysisContext $context): string
    {
        $names = '';
        foreach ($context->source->getResolvedNames($context->node) as $resolved) {
            if (stripos($resolved->name, PHPUnitAttributes::NAMESPACE) !== 0) {
                continue;
            }

            $names .= "\n" . substr($resolved->name, strlen(PHPUnitAttributes::NAMESPACE));
        }

        return $names;
    }
}
