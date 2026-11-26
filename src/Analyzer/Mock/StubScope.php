<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\SourceFile;

/**
 * The configured method and the syntax that values given to the double are
 * read from.
 *
 * The syntax starts as the call's own subtree. A local variable is followed
 * to the expression assigned to it, in the file's full syntax, which is then
 * used for the rest.
 *
 * @internal
 */
final class StubScope
{
    /**
     * How many variables assigned from another variable are followed.
     */
    private const MAXIMUM_ALIASES = 4;

    /**
     * @param bool $shared Whether a variable followed to get here is read
     *   more than once, so a stub found here may also be given to another
     *   method.
     */
    public function __construct(
        public readonly NodeAnalysisContext $context,
        public readonly SourceFile $source,
        public readonly ConfiguredMethod $method,
        public readonly bool $shared = false,
    ) {}

    /**
     * The values given to the stub, the scope they're read in and the
     * expression that creates the stub, or null when they aren't known. A
     * variable that holds the stub is followed.
     *
     * @return array{self, Node, list<Node>}|null
     */
    public function arguments(Node $node): ?array
    {
        [$scope, $node] = $this->followed($node);
        $arguments = StubSyntax::arguments($scope->context, $scope->source, $node);

        return $arguments === null ? null : [$scope, $node, $arguments];
    }

    /**
     * The elements of an array that's written out, and the scope they're
     * read in, or null. A variable that holds the array is followed.
     *
     * @return array{self, list<Node>}|null
     */
    public function elements(Node $node): ?array
    {
        [$scope, $node] = $this->followed($node);
        $elements = StubSyntax::elements($scope->source, $node);

        return $elements === null ? null : [$scope, $elements];
    }

    /**
     * Whether the class of the double in the value is known, since the
     * value is created right there, such as `$this->createStub(Foo::class)`,
     * or by the expression assigned to the variable.
     *
     * The variable is only followed when the answer can change the result:
     * for a double whose fit isn't known, with a return type that's checked.
     * Following it reads the file's full syntax.
     */
    public function isExact(Node $node, Type $value): bool
    {
        if (Doubles::isCreatedBy($this->context, $this->source, $node)) {
            return true;
        }

        if (
            !LocalAssignment::isVariable($this->source, $node)
            || Doubles::classes($value) === []
            || !self::isChecked($this->context, $this->method->returnType)
            || ReturnFit::atReturn($this->context, $value, $this->method->returnType, created: false) !== null
        ) {
            return false;
        }

        [$scope, $assigned] = $this->followed($node);

        return $scope !== $this && Doubles::isCreatedBy($scope->context, $scope->source, $assigned);
    }

    /**
     * The expression assigned to the variable, and the scope it's read in.
     * A node that isn't a variable that's followed is returned as it is.
     *
     * @return array{self, Node}
     */
    public function expression(Node $node): array
    {
        if (!LocalAssignment::isVariable($this->source, $node)) {
            return [$this, $node];
        }

        [$scope, $node] = $this->followed($node);

        return [$scope, $node];
    }

    /**
     * Whether the class of the double that's configured is known.
     */
    public function isDoubleExact(): bool
    {
        $root = new self($this->context, $this->context->source, $this->method);

        return $root->isExact($this->method->doubleNode, $this->method->double);
    }

    /**
     * The expression assigned to a variable, followed through other
     * variables, with its scope. A node that isn't such a variable is
     * returned as it is. The scope is shared when one of the variables is
     * read more than once.
     *
     * @return array{self, Node}
     */
    private function followed(Node $node): array
    {
        $scope = $this;
        for ($step = 0; $step < self::MAXIMUM_ALIASES && LocalAssignment::isVariable($scope->source, $node); $step++) {
            $file = $this->context->analysis->getSourceFile();
            $assigned = LocalAssignment::of($this->context, $scope->source, $node, $file);
            if ($assigned === null) {
                break;
            }

            [$node, $reads] = $assigned;
            $scope = new self($this->context, $file, $this->method, $scope->shared || $reads > 1);
        }

        return [$scope, $node];
    }

    /**
     * Whether values are checked against the return type, which isn't the
     * case for `mixed`, `callable` or an intersection type. A type that
     * isn't checked gives an unknown fit for any value, also for null.
     */
    private static function isChecked(NodeAnalysisContext $context, Type $returnType): bool
    {
        return ReturnFit::atReturn($context, Type::null(), $returnType, created: false) !== null;
    }
}
