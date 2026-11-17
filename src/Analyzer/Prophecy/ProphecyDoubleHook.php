<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Prophecy;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\CallExpression;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Internal\Declaration\NativeFinal;
use MagoPHPUnit\Internal\Type\Types;

use function in_array;
use function ltrim;
use function strtolower;

/**
 * Reports a class that Prophecy can't double, given to `prophesize()`,
 * `willExtend()` or `willImplement()`.
 *
 * @internal
 */
final class ProphecyDoubleHook implements MethodCallAnalysisHook
{
    public const CODE = 'prophecy-invalid-double';

    private readonly NativeFinal $final;

    /**
     * Sets up the check for the `final` keyword.
     */
    public function __construct()
    {
        $this->final = new NativeFinal();
    }

    /**
     * Targets `prophesize()` on any class, and `willExtend()` and
     * `willImplement()` on a prophecy.
     */
    public function getTargets(): array
    {
        return [
            MethodTarget::anyClass('prophesize'),
            MethodTarget::exact(ProphesizedMethod::OBJECT_PROPHECY, method: 'willExtend'),
            MethodTarget::exact(ProphesizedMethod::OBJECT_PROPHECY, method: 'willImplement'),
        ];
    }

    /**
     * Asks for the argument types, the call's type, its subtree and the file's text.
     */
    public function getRequirements(): array
    {
        return [
            FileAnalysisRequirement::ArgumentTypes,
            FileAnalysisRequirement::TargetExpressionTypes,
            FileAnalysisRequirement::TargetSubtree,
            FileAnalysisRequirement::SourceText,
        ];
    }

    /**
     * Reports the class when Prophecy throws for it.
     *
     * Prophecy builds the double when the prophecy is first revealed or set
     * up. It throws then for a final class or an enum, for a class given to
     * `willImplement()` and for an interface given to `willExtend()`.
     */
    public function analyze(NodeAnalysisContext $context): void
    {
        // A `prophesize()` method that doesn't return a prophecy isn't
        // Prophecy's.
        if (!in_array(ProphesizedMethod::OBJECT_PROPHECY, Types::names($context->targetType), strict: true)) {
            return;
        }

        $call = CallExpression::fromNode($context->source, $context->node);
        $argument = $call->arguments[0] ?? null;
        if ($argument === null || $argument->unpacked) {
            return;
        }

        $name = ltrim(Types::literalName($context->argumentTypes[0] ?? null), characters: '\\');
        $class = $name === '' ? null : $context->codebase->getClassLike($name);
        if ($class === null) {
            return;
        }

        $issue = match (strtolower($call->getName($context->source) ?? '')) {
            'willimplement' => self::implemented($class),
            'willextend' => $this->extended($class),
            default => $this->prophesized($class),
        };
        if ($issue === null) {
            return;
        }

        Reporter::report(
            $context,
            Level::Error,
            self::CODE,
            Issue::new($issue[0], $argument->node->span)->withHelp($issue[1]),
        );
    }

    /**
     * The message and the help for a class given to `willImplement()`, or
     * null when it's an interface.
     *
     * @return array{string, string}|null
     */
    private static function implemented(ClassLikeMetadata $class): ?array
    {
        if ($class->kind === ClassLikeKind::Interface) {
            return null;
        }

        return [
            "`willImplement()` gets `{$class->originalName}`, which isn't an interface. Prophecy throws for it.",
            'Use `willExtend()` for a class.',
        ];
    }

    /**
     * The message and the help for a class given to `willExtend()`, or null
     * when Prophecy can extend it.
     *
     * @return array{string, string}|null
     */
    private function extended(ClassLikeMetadata $class): ?array
    {
        if ($class->kind === ClassLikeKind::Interface) {
            return [
                "`willExtend()` gets the interface `{$class->originalName}`. Prophecy throws for it.",
                'Use `willImplement()` for an interface.',
            ];
        }

        return $this->prophesized($class);
    }

    /**
     * The message and the help for a class given to `prophesize()`, or null
     * when Prophecy can double it.
     *
     * A class marked final only in its docblock can be doubled. Prophecy
     * only checks the keyword.
     *
     * @return array{string, string}|null
     */
    private function prophesized(ClassLikeMetadata $class): ?array
    {
        $name = $class->originalName;

        return match (true) {
            $class->kind === ClassLikeKind::Enum => [
                "Prophecy can't double the enum `{$name}`.",
                'Use a case of the enum.',
            ],
            $class->kind === ClassLikeKind::Trait => [
                "Prophecy can't double the trait `{$name}`.",
                'Double a class that uses the trait.',
            ],
            $class->kind === ClassLikeKind::Class_ && $this->final->is($class) => [
                "Prophecy can't double `{$name}`, since it's final.",
                'Double an interface of the class, or use a real object.',
            ],
            default => null,
        };
    }
}
