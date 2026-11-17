<?php

declare(strict_types=1);

namespace MagoPHPUnit\Linter;

use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

use function count;
use function in_array;
use function intdiv;
use function usort;

/**
 * The method calls of a file, for finding the calls of a method body
 * without walking it.
 *
 * A walk of a method body builds a node object for every node in it. The
 * index asks the file for the call nodes once, and only builds those and
 * their ancestors.
 *
 * @internal
 */
final class CallIndex
{
    /**
     * The kinds of the call nodes, each with the operator before the method name.
     */
    private const OPERATORS = [
        [NodeKind::MethodCall,                     '->'],
        [NodeKind::MethodPartialApplication,       '->'],
        [NodeKind::StaticMethodCall,               '::'],
        [NodeKind::StaticMethodPartialApplication, '::'],
    ];

    /**
     * The nodes whose code doesn't belong to the method around them.
     */
    private const SKIPPED = [
        NodeKind::Closure,
        NodeKind::ArrowFunction,
        NodeKind::CompositeString,
        NodeKind::Function,
        NodeKind::Class_,
        NodeKind::Interface,
        NodeKind::Trait,
        NodeKind::Enum,
    ];

    /**
     * The call nodes and the operator before the method name, by the
     * call's start and then the outer call first.
     *
     * @var list<array{Node, '->'|'::'}>
     */
    private readonly array $calls;

    /**
     * Reads the call nodes of the file.
     */
    public function __construct(SourceFile $file)
    {
        $calls = [];
        foreach (self::OPERATORS as [$kind, $operator]) {
            foreach ($file->getNodes($kind) as $node) {
                $calls[] = [$node, $operator];
            }
        }

        usort(
            $calls,
            static fn(array $left, array $right): int => (
                [
                    $left[0]->span->start,
                    $right[0]->span->end,
                ] <=> [$right[0]->span->start, $left[0]->span->end]
            ),
        );
        $this->calls = $calls;
    }

    /**
     * The calls in the method body, with the operator of each, in the order
     * they're written, outer calls first.
     *
     * @return list<array{Node, '->'|'::'}>
     */
    public function callsIn(SourceFile $file, Node $body): array
    {
        $calls = [];
        $count = count($this->calls);
        for ($index = $this->first($body->span->start); $index < $count; ++$index) {
            $call = $this->calls[$index];
            if ($call[0]->span->start >= $body->span->end) {
                break;
            }

            if (self::reached($file, $call[0], $body)) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    /**
     * Whether the call belongs to the method body.
     *
     * Closures, arrow functions and anonymous class bodies are skipped, since
     * `$this` there isn't the test case. The constructor arguments of an
     * anonymous class still belong to the body. Interpolated strings are
     * skipped too, the same as in Mago's built-in rules, since `static::`
     * can't be written inside `{...}`. A nested declaration has its own
     * methods.
     */
    private static function reached(SourceFile $file, Node $call, Node $body): bool
    {
        $below = $call;
        $node = $file->getParent($call);
        while ($node !== null) {
            if ($node->id === $body->id) {
                return true;
            }

            if (
                in_array($node->kind, self::SKIPPED, strict: true)
                || $node->kind === NodeKind::AnonymousClass
                && $below->kind !== NodeKind::ArgumentList
                && $below->kind !== NodeKind::PartialArgumentList
            ) {
                return false;
            }

            $below = $node;
            $node = $file->getParent($node);
        }

        return false;
    }

    /**
     * The index of the first call that starts at or after the offset.
     */
    private function first(int $offset): int
    {
        [$low, $high] = [0, count($this->calls)];
        while ($low < $high) {
            $middle = intdiv($low + $high, num2: 2);
            if ($this->calls[$middle][0]->span->start < $offset) {
                $low = $middle + 1;

                continue;
            }

            $high = $middle;
        }

        return $low;
    }
}
