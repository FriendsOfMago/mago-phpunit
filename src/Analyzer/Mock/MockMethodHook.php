<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\Metadata\MemberIdentifier;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\SourceFile;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Internal\PHPUnitFeatures;
use MagoPHPUnit\Internal\Text\CodeMask;
use MagoPHPUnit\Internal\Type\Types;
use WeakMap;

use function array_values;
use function count;
use function implode;
use function in_array;
use function preg_match;
use function str_starts_with;
use function strtolower;

/**
 * Reports `method('name')` on a double of a class that doesn't have the method.
 *
 * @internal
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class MockMethodHook implements MethodCallAnalysisHook
{
    public const CODE = 'mock-undefined-method';

    private const DOUBLES = ['phpunit\framework\mockobject\mockobject', 'phpunit\framework\mockobject\stub'];

    private const PHPUNIT = 'phpunit\\';

    /**
     * The `expects()` calls of each file, keyed by span. Each entry is the type
     * of the double, or the pending `method()` checks for that type.
     *
     * In `$double->expects(...)->method()`, the receiver of `method()` is
     * PHPUnit's invocation mocker, not the double. The hook also targets
     * `expects()`, whose receiver type is the double, so the type doesn't need
     * a separate lookup. The two calls arrive in either order. The check runs
     * on the second one.
     *
     * The worker can interleave the batches of several files, so the calls
     * are kept per file.
     *
     * @var WeakMap<SourceFile, array<string, Type|list<array{list<string>, Span}>>>
     */
    private WeakMap $expects;

    public function __construct()
    {
        $this->expects = new WeakMap();
    }

    /**
     * Targets `method()` and `expects()` on any class.
     */
    public function getTargets(): array
    {
        return [MethodTarget::anyClass('method'), MethodTarget::anyClass('expects')];
    }

    /**
     * Asks for the receiver and argument types, the call's subtree and the
     * file's text.
     */
    public function getRequirements(): array
    {
        return [
            FileAnalysisRequirement::ReceiverType,
            FileAnalysisRequirement::ArgumentTypes,
            FileAnalysisRequirement::TargetSubtree,
            FileAnalysisRequirement::SourceText,
        ];
    }

    /**
     * Checks `$double->method()` and `$double->expects(...)->method()`.
     */
    public function analyze(NodeAnalysisContext $context): void
    {
        // The `method()` method is declared on PHPUnit's `Stub`. Mago accepts any name there.
        // PHPUnit throws at runtime if the method is missing or can't be replaced. Missing, final,
        // private and protected methods are checked here. This matches phpstan-phpunit's
        // `MockMethodCallRule`, plus the final, private and protected cases.
        $receiver = $context->receiverType;
        if ($receiver === null) {
            return;
        }

        $call = CallExpression::fromNode($context->source, $context->node);
        if (strtolower($call->getName($context->source) ?? '') === 'expects') {
            $this->expected($context, $receiver);
            return;
        }

        $names = Types::literalStrings($context->argumentTypes[0] ?? null);
        if ($names === null) {
            return;
        }

        $argument = $call->arguments[0] ?? null;
        $span = $argument === null ? $context->node->span : $argument->value->span;
        $receiverNames = Types::names($receiver);
        if ($receiverNames === [] || $receiverNames !== Types::names(MockUnionProvider::mocker($context->codebase))) {
            self::check($context, self::mocked($receiver), $names, $span);
            return;
        }

        $inner = $call->receiver === null ? null : CallExpression::fromExpression($context->source, $call->receiver);
        if (
            $inner === null
            || !$inner->isMethod()
            || strtolower($inner->getName($context->source) ?? '') !== 'expects'
        ) {
            return;
        }

        $calls = $this->expects[$context->source] ?? [];
        $key = $inner->node->span->start . ':' . $inner->node->span->end;
        $double = $calls[$key] ?? [];
        if ($double instanceof Type) {
            self::check($context, self::mocked($double), $names, $span);
            return;
        }

        $double[] = [$names, $span];
        $calls[$key] = $double;
        $this->expects[$context->source] = $calls;
    }

    /**
     * Keeps the type of the double that an `expects()` call is made on, and
     * runs the pending `method()` checks for it.
     */
    private function expected(NodeAnalysisContext $context, Type $double): void
    {
        $calls = $this->expects[$context->source] ?? [];
        $key = $context->node->span->start . ':' . $context->node->span->end;
        $waiting = $calls[$key] ?? [];
        $calls[$key] = $double;
        $this->expects[$context->source] = $calls;
        if ($waiting instanceof Type) {
            return;
        }

        $classes = self::mocked($double);
        foreach ($waiting as [$names, $span]) {
            self::check($context, $classes, $names, $span);
        }
    }

    /**
     * Reports each method name that can't be configured on a double of the classes.
     *
     * @param non-empty-list<string>|null $classes
     * @param list<string> $names
     */
    private static function check(NodeAnalysisContext $context, ?array $classes, array $names, Span $span): void
    {
        if ($classes === null) {
            return;
        }

        foreach ($names as $name) {
            $problem = $name === '' ? null : self::problem($context, $classes, $name);
            if ($problem === null) {
                continue;
            }

            Reporter::report(
                $context,
                Level::Error,
                self::CODE,
                Issue::new($problem, $span)->withHelp(
                    'PHPUnit throws when the test runs. Name a method that can be replaced in the double.',
                ),
            );
        }
    }

    /**
     * The non-PHPUnit classes of a type that is one double, such as
     * `MockObject&Foo`, or null for any other type.
     *
     * @return non-empty-list<string>|null
     */
    private static function mocked(?Type $type): ?array
    {
        if ($type === null || count($type->atomicTypes) !== 1) {
            return null;
        }

        $isDouble = false;
        $classes = [];
        foreach (Types::names($type) as $name) {
            $lower = strtolower($name);
            if (in_array($lower, self::DOUBLES, strict: true)) {
                $isDouble = true;
                continue;
            }

            if (!str_starts_with($lower, self::PHPUNIT)) {
                $classes[$lower] = $name;
            }
        }

        return $isDouble && $classes !== [] ? array_values($classes) : null;
    }

    /**
     * Why PHPUnit can't configure the method on a double of the classes, or null.
     *
     * @param non-empty-list<string> $classes
     */
    private static function problem(NodeAnalysisContext $context, array $classes, string $method): ?string
    {
        // PHPUnit doubles public and abstract methods. Final methods, constructors and destructors
        // aren't doubled. Methods aren't added by `__call()` or `@method` tags.
        $codebase = $context->codebase;
        $identifiers = [];
        foreach ($classes as $class) {
            $identifiers[] = new MemberIdentifier($class, $method);
        }

        // Protected methods can be selected with `getMockBuilder()`, `createPartialMock()`,
        // `onlyMethods()`, `getMockForTrait()` or `getMockForAbstractClass()`.
        $declared = null;
        foreach ($codebase->getMultipleDeclaringMethods($identifiers) as $metadata) {
            // PHPUnit doesn't double a method from an `@method` tag. The
            // declaring method may come from a parent, an interface or a trait.
            if ($metadata === null || $metadata->flags->contains(MetadataFlags::MAGIC_METHOD)) {
                continue;
            }

            $declared = match (true) {
                $metadata->constructor || in_array(strtolower($method), ['__construct', '__destruct'], strict: true)
                    => 'a constructor or destructor',
                $metadata->final => 'final',
                $metadata->visibility === Visibility::Private => 'private',
                $metadata->visibility === Visibility::Protected
                    && !$metadata->abstract
                    && !self::selectsMethods($context)
                    => 'protected',
                default => null,
            };
            if ($declared === null) {
                return null;
            }
        }

        // On PHPUnit 10 and 11, missing methods can be added with `addMethods()`,
        // `getMockForTrait()` or `getMockForAbstractClass()`.
        if ($declared === null && self::addsMethods($context)) {
            return null;
        }

        // An unknown class, parent or interface counts as having the method.
        // Class metadata is fetched only when needed. File text is checked only when needed.
        foreach ($codebase->getMultipleClassLikes($classes) as $class) {
            if ($class === null || $class->hasIncompleteHierarchy()) {
                return null;
            }
        }

        $type = implode('&', $classes);

        return $declared === null
            ? "The method `{$method}()` doesn't exist on `{$type}`."
            : "The method `{$method}()` of `{$type}` is {$declared}, so PHPUnit can't replace it in a double.";
    }

    /**
     * Whether a call to a method for selecting protected methods appears in the file.
     */
    private static function selectsMethods(NodeAnalysisContext $context): bool
    {
        // The double may come from a helper in another file. A call anywhere in this file counts.
        $code = CodeMask::code($context->source->contents);
        $calls = 'getMockBuilder|createPartialMock|onlyMethods|getMockForTrait|getMockForAbstractClass';

        return preg_match('/->\s*(?:' . $calls . ')\s*\(/i', $code) === 1;
    }

    /**
     * Whether a call for adding missing methods appears in the file on PHPUnit 10 or 11.
     */
    private static function addsMethods(NodeAnalysisContext $context): bool
    {
        // Missing methods can be added with `addMethods()`. They can also be added with the
        // `$mockedMethods` argument of `getMockForTrait()` or `getMockForAbstractClass()`. Only the
        // method name is checked here.
        return (
            PHPUnitFeatures::addsMockMethods($context->codebase)
            && preg_match(
                '/->\s*(?:addMethods|getMockForTrait|getMockForAbstractClass)\s*\(/i',
                CodeMask::code($context->source->contents),
            ) === 1
        );
    }
}
