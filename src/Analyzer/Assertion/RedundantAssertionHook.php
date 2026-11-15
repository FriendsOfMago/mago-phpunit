<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Assertion;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\CallArgument;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\NodeKind;
use MagoPHPUnit\Analyzer\Support\Reporter;
use MagoPHPUnit\Internal\Type\Types;

use function array_keys;
use function implode;
use function ltrim;

/**
 * Reports a PHPUnit assertion method call that always passes, given the value's type.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final class RedundantAssertionHook implements MethodCallAnalysisHook
{
    public const CODE = 'redundant-assertion';

    /**
     * The assertion methods checked, each with the name of the parameter
     * that takes the value.
     */
    public const METHODS = [
        'assertInstanceOf' => 'actual',
        'assertNotNull' => 'actual',
        'assertNull' => 'actual',
        'assertTrue' => 'condition',
        'assertFalse' => 'condition',
        'assertNotTrue' => 'condition',
        'assertNotFalse' => 'condition',
        'assertIsArray' => 'actual',
        'assertIsBool' => 'actual',
        'assertIsFloat' => 'actual',
        'assertIsInt' => 'actual',
        'assertIsNumeric' => 'actual',
        'assertIsObject' => 'actual',
        'assertIsScalar' => 'actual',
        'assertIsString' => 'actual',
    ];

    /**
     * The argument types, plus the subtree and the text, which are needed to
     * match named arguments to parameters.
     */
    private const REQUIREMENTS = [
        FileAnalysisRequirement::ArgumentTypes,
        FileAnalysisRequirement::TargetSubtree,
        FileAnalysisRequirement::SourceText,
    ];

    private const ASSERT = 'PHPUnit\Framework\Assert';

    /**
     * @param key-of<self::METHODS> $method
     */
    public function __construct(
        private readonly string $method,
    ) {}

    /**
     * Targets the one assertion method checked by this instance.
     */
    public function getTargets(): array
    {
        return [MethodTarget::exact(self::ASSERT, $this->method)];
    }

    /**
     * Asks for the argument types, the call's subtree and the file's text.
     */
    public function getRequirements(): array
    {
        return self::REQUIREMENTS;
    }

    /**
     * Checks the call with the argument types from the context.
     */
    public function analyze(NodeAnalysisContext $context): void
    {
        $this->check($context, $context->argumentTypes);
    }

    /**
     * Reports the call when it always passes for the asserted value's type.
     *
     * RedundantAssertionFunctionHook calls this for the global assertion
     * functions too. phpstan-phpunit reports the same calls. A type from a
     * docblock counts, as it does in PHPStan. Only assertions that always
     * pass are reported here. Mago itself reports an assertion on a variable
     * that can never pass, as `impossible-type-comparison`.
     *
     * @param array<int, ?Type> $types
     * @param ?CallExpression $call The parsed call, or null to parse it here.
     */
    public function check(NodeAnalysisContext $context, array $types, ?CallExpression $call = null): void
    {
        if (!$this->anyMayPass($types)) {
            return;
        }

        $call ??= Arguments::call($context);
        $argument = $call === null ? null : $this->argument($context, $call);
        $value = $argument === null ? null : $types[$argument->index] ?? null;
        $expected = $call !== null && $this->method === 'assertInstanceOf' ? self::expected($call, $types) : '';
        $passes = fn(Type $type): bool => $this->passes($context, $type, $expected);
        // A variable that may be undefined is null when undefined, and null
        // fails the assertion. Mago also sets these flags on typed properties,
        // so the check is limited to variables. Properties are checked by
        // their declaration in SettledValue.
        if (
            $argument === null
            || $value === null
            || $argument->value->kind === NodeKind::Variable
            && ($value->flags->possiblyUndefined || $value->flags->possiblyUndefinedFromTry)
            || !$passes($value)
            || !SettledValue::holds($context, $argument->value, $passes)
        ) {
            return;
        }

        // Mago describes a literal `true` as `bool`. So for these three
        // methods, and for a literal bool passed to any other method, the
        // message has the single value instead.
        $literal = $value->getLiteralBool();
        $already = match (true) {
            $this->method === 'assertTrue' => 'always `true`',
            $this->method === 'assertFalse' => 'always `false`',
            $this->method === 'assertNull' => 'always `null`',
            $literal !== null => $literal ? 'always `true`' : 'always `false`',
            default => 'already `' . self::describe((string) $value) . '`',
        };
        Reporter::report(
            $context,
            Level::Warning,
            self::CODE,
            Issue::new(
                "`{$this->method}()` always passes: the value is {$already}.",
                primarySpan: $context->node->span,
                annotationMessage: 'always passes',
            )->withHelp(
                'Remove the assertion, or assert something about the value that its type doesn\'t already guarantee. '
                . 'If the type comes from a docblock that is missing what the assertion checks, such as null, '
                . 'fix the docblock instead.',
            ),
        );
    }

    /**
     * Returns the type's description with each top-level member once.
     */
    private static function describe(string $description): string
    {
        // A union isn't always simplified by Mago. A member can appear twice in its description, as
        // in `int|int`.
        $members = [];
        foreach (Types::unionMembers($description, brackets: '<>{}()') as $member) {
            $members[$member] = true;
        }

        return implode('|', array_keys($members));
    }

    /**
     * Whether any argument's type could make the call always pass.
     *
     * Most calls fail this check, and it's cheaper than parsing the call.
     *
     * @param array<int, ?Type> $types
     */
    private function anyMayPass(array $types): bool
    {
        foreach ($types as $type) {
            $may =
                $type !== null
                && (
                    $this->method === 'assertInstanceOf'
                        ? Types::objectsOnly($type)
                        : AlwaysPasses::check($this->method, $type)
                );
            if ($may) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the argument with the asserted value.
     *
     * Returns null when the method name is a variable, since Mago may have
     * resolved it to more than one method.
     */
    private function argument(NodeAnalysisContext $context, CallExpression $call): ?CallArgument
    {
        if ($call->getName($context->source) === null) {
            return null;
        }

        $position = $this->method === 'assertInstanceOf' ? 1 : 0;

        return Arguments::argument($call, $position, self::METHODS[$this->method]);
    }

    /**
     * Whether a value of this type always passes the assertion.
     *
     * For `assertInstanceOf()`, every member of the type must be an instance
     * of `$expected`, the class named by the call.
     */
    private function passes(NodeAnalysisContext $context, Type $type, string $expected): bool
    {
        if ($this->method !== 'assertInstanceOf') {
            return AlwaysPasses::check($this->method, $type);
        }

        return (
            $expected !== ''
            && Types::objectsOnly($type)
            && $context->types->isContainedBy($type, Type::namedObject($expected))
        );
    }

    /**
     * Returns the class named by the first argument of `assertInstanceOf()`.
     *
     * Returns an empty string when the argument isn't a literal.
     *
     * @param array<int, ?Type> $types
     */
    private static function expected(CallExpression $call, array $types): string
    {
        $argument = Arguments::argument($call, position: 0, name: 'expected');
        $type = $argument === null ? null : $types[$argument->index] ?? null;

        return ltrim((string) $type?->getLiteralString(), characters: '\\');
    }
}
