<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use ArrayObject;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;
use WeakMap;

use function array_key_exists;
use function in_array;
use function ltrim;
use function strtolower;

/**
 * Finds the value of a local variable that's assigned once, such as
 * `$stub = new ReturnStub(5)` before `willReturnOnConsecutiveCalls($stub)`.
 *
 * Mago knows the type of the variable, but not the values given to a stub
 * or the class of a double, so the assigned expression is read from the
 * syntax. The value is only known when nothing else can change the
 * variable:
 *
 * - The variable is assigned once in the method or function, by a
 *   statement of the body itself that's a plain `=` assignment, before
 *   it's read.
 * - No other use changes it, as listed in `VariableChanges`.
 * - The body doesn't use `eval`, `include`, `require`, `goto`, variable
 *   variables, `extract()` or `parse_str()`.
 *
 * The variables of a file and the uses in a body are read once.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class LocalAssignment
{
    /**
     * The lowercased functions that can set any local variable.
     */
    private const WRITERS = ['extract', 'parse_str', 'mb_parse_str'];

    private const UNSAFE = [
        NodeKind::EvalConstruct,
        NodeKind::IncludeConstruct,
        NodeKind::IncludeOnceConstruct,
        NodeKind::RequireConstruct,
        NodeKind::RequireOnceConstruct,
        NodeKind::IndirectVariable,
        NodeKind::NestedVariable,
        NodeKind::Goto,
    ];

    /**
     * The variables of each file by their start, and the uses of each
     * variable name in each body, or null for a body that can set any
     * variable.
     *
     * @var WeakMap<SourceFile, array{array<int, Node>, array<int, array<string, list<Node>>|null>}>|null
     */
    private static ?WeakMap $files = null;

    /**
     * The assignment found for each name in each body, as the assigned
     * expression, the start of the assignment and the number of reads, or
     * null when the variable isn't followed.
     *
     * @var WeakMap<SourceFile, ArrayObject<string, array{Node, int, int}|null>>|null
     */
    private static ?WeakMap $found = null;

    private function __construct() {}

    /**
     * Whether the node is a plain variable, such as `$stub`.
     */
    public static function isVariable(SourceFile $source, Node $node): bool
    {
        return self::direct($source, $node) !== null;
    }

    /**
     * The expression assigned to the variable, in the file's full syntax,
     * and the number of other uses that read the variable, or null when it
     * isn't known.
     *
     * @param SourceFile $source The syntax that has the variable, which may only hold part of the file.
     * @param SourceFile $file The file's full syntax.
     *
     * @return array{Node, int}|null
     */
    public static function of(
        NodeAnalysisContext $context,
        SourceFile $source,
        Node $variable,
        SourceFile $file,
    ): ?array {
        $direct = self::direct($source, $variable);
        [$variables, $bodies] = $direct === null ? [[], []] : self::index($file);
        $node = $direct === null ? null : $variables[$direct->span->start] ?? null;
        $body = $node === null ? null : self::body($file, $node);
        if ($node === null || $body === null) {
            return null;
        }

        $name = $file->getText($node);
        self::$found ??= new WeakMap();
        $key = "{$body->id}:{$name}";
        /** @var ArrayObject<string, array{Node, int, int}|null> $found */
        $found = self::$found[$file] ?? new ArrayObject();
        self::$found[$file] = $found;
        if (!$found->offsetExists($key)) {
            $uses = self::uses($file, $body, $bodies);
            $found[$key] = $uses === null ? null : self::assignment($context, $file, $body, $uses[$name] ?? []);
        }

        $assignment = $found[$key];

        return $assignment === null || $assignment[1] >= $node->span->start ? null : [$assignment[0], $assignment[2]];
    }

    /**
     * The single assignment among the uses of a name, or null when there
     * isn't exactly one, or another use can change the variable.
     *
     * @param list<Node> $uses
     *
     * @return array{Node, int, int}|null
     */
    private static function assignment(NodeAnalysisContext $context, SourceFile $file, Node $body, array $uses): ?array
    {
        $assigned = null;
        $reads = 0;
        foreach ($uses as $use) {
            $value = $assigned === null ? self::assignedBy($file, $use, $body) : null;
            if ($value !== null) {
                $assigned = [$value, $use->span->start];
                continue;
            }

            if (VariableChanges::changes($context, $file, $use)) {
                return null;
            }

            $reads++;
        }

        return $assigned === null ? null : [$assigned[0], $assigned[1], $reads];
    }

    /**
     * The `DirectVariable` node inside the wrappers, or null when the node isn't a plain variable.
     */
    private static function direct(SourceFile $source, Node $node): ?Node
    {
        while ($node->kind === NodeKind::Expression || $node->kind === NodeKind::Variable) {
            $inner = $source->getChildren($node)[0] ?? null;
            if ($inner === null) {
                return null;
            }

            $node = $inner;
        }

        return $node->kind === NodeKind::DirectVariable ? $node : null;
    }

    /**
     * The variables of the file by their start, and the uses already read.
     *
     * @return array{array<int, Node>, array<int, array<string, list<Node>>|null>}
     */
    private static function index(SourceFile $file): array
    {
        self::$files ??= new WeakMap();
        $index = self::$files[$file] ?? null;
        if ($index !== null) {
            return $index;
        }

        $variables = [];
        foreach ($file->getNodes(NodeKind::DirectVariable) as $variable) {
            $variables[$variable->span->start] = $variable;
        }

        return self::$files[$file] = [$variables, []];
    }

    /**
     * The uses of each variable name in the body, or null when the body
     * has code that can set any variable.
     *
     * @param array<int, array<string, list<Node>>|null> $bodies
     *
     * @return array<string, list<Node>>|null
     */
    private static function uses(SourceFile $file, Node $body, array $bodies): ?array
    {
        if (array_key_exists($body->id, $bodies)) {
            return $bodies[$body->id];
        }

        $uses = [];
        foreach ($file->getDescendants($body) as $node) {
            if (self::isUnsafe($file, $node)) {
                $uses = null;
                break;
            }

            if ($node->kind === NodeKind::DirectVariable) {
                $uses[$file->getText($node)][] = $node;
            }
        }

        $index = self::index($file);
        $index[1][$body->id] = $uses;
        self::$files[$file] = $index;

        return $uses;
    }

    /**
     * Whether the node can set any local variable.
     */
    private static function isUnsafe(SourceFile $file, Node $node): bool
    {
        if (in_array($node->kind, self::UNSAFE, strict: true)) {
            return true;
        }

        $callee = $node->kind === NodeKind::FunctionCall ? $file->getChildren($node)[0] ?? null : null;
        $name = $callee === null ? '' : strtolower(ltrim($file->getText($callee), characters: '\\'));

        return in_array($name, self::WRITERS, strict: true);
    }

    /**
     * The block of the method or function around the variable, or null
     * when the variable is in a closure, an arrow function or outside a
     * function.
     */
    private static function body(SourceFile $file, Node $variable): ?Node
    {
        foreach ($file->getAncestors($variable) as $ancestor) {
            if ($ancestor->kind === NodeKind::Closure || $ancestor->kind === NodeKind::ArrowFunction) {
                return null;
            }

            if ($ancestor->kind !== NodeKind::MethodBody && $ancestor->kind !== NodeKind::Function) {
                continue;
            }

            foreach ($file->getChildren($ancestor) as $child) {
                if ($child->kind === NodeKind::Block) {
                    return $child;
                }
            }

            return null;
        }

        return null;
    }

    /**
     * The value assigned to the variable, when the use is on the left of a
     * plain `=` assignment that's a statement of the block itself, or null.
     */
    private static function assignedBy(SourceFile $file, Node $use, Node $body): ?Node
    {
        // Variable, Expression, Assignment, Expression, ExpressionStatement, Statement.
        $chain = [];
        $node = $use;
        foreach ([
            NodeKind::Variable,
            NodeKind::Expression,
            NodeKind::Assignment,
            NodeKind::Expression,
            NodeKind::ExpressionStatement,
            NodeKind::Statement,
        ] as $kind) {
            $node = $file->getParent($node);
            if ($node?->kind !== $kind) {
                return null;
            }

            $chain[] = $node;
        }

        $parts = $file->getChildren($chain[2]);
        $operator = $parts[1] ?? null;
        $value = $parts[2] ?? null;
        if (
            ($parts[0] ?? null)?->id !== $chain[1]->id
            || $operator === null
            || $value === null
            || $file->getText($operator) !== '='
            || $file->getParent($chain[5])?->id !== $body->id
        ) {
            return null;
        }

        $inner = $value;
        while ($inner->kind === NodeKind::Expression) {
            $next = $file->getChildren($inner)[0] ?? null;
            if ($next === null) {
                return null;
            }

            $inner = $next;
        }

        return $inner->kind === NodeKind::UnaryPrefix ? null : $inner;
    }
}
