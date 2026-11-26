<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * Finds the uses of a local variable that can change it.
 *
 * @internal
 */
final class VariableChanges
{
    private function __construct() {}

    /**
     * Whether a use can change the variable.
     *
     * A use changes the variable when it's on the left of an assignment,
     * also inside an array or an array access there, or in a `foreach`, a
     * `global`, `static` or `unset` statement, a `catch` clause, a `&`
     * reference, an increment or a decrement, or a closure's `use` clause,
     * also by value. It can change it in an argument, unless the argument is
     * known to be passed by value.
     */
    public static function changes(NodeAnalysisContext $context, SourceFile $file, Node $use): bool
    {
        $child = $use;
        foreach ($file->getAncestors($use) as $ancestor) {
            $changes = match ($ancestor->kind) {
                NodeKind::Statement => false,
                NodeKind::Assignment => $child->id === ($file->getChildren($ancestor)[0] ?? null)?->id,
                NodeKind::UnaryPrefix, NodeKind::UnaryPostfix => self::isChangingOperator($file, $ancestor),
                NodeKind::PositionalArgument, NodeKind::NamedArgument => ArgumentPassing::byValue(
                    $context,
                    $file,
                    $ancestor,
                )
                        ? null
                        : true,
                NodeKind::Foreach,
                NodeKind::Global,
                NodeKind::Static,
                NodeKind::Unset,
                NodeKind::ClosureUseClauseVariable,
                NodeKind::TryCatchClause,
                    => true,
                default => null,
            };
            if ($changes !== null) {
                return $changes;
            }

            $child = $ancestor;
        }

        return false;
    }

    /**
     * Whether a unary operator is `&`, `++` or `--`.
     */
    private static function isChangingOperator(SourceFile $file, Node $unary): ?bool
    {
        foreach ($file->getChildren($unary) as $part) {
            $operator =
                $part->kind === NodeKind::UnaryPrefixOperator || $part->kind === NodeKind::UnaryPostfixOperator
                    ? $file->getText($part)
                    : '';
            if ($operator === '&' || $operator === '++' || $operator === '--') {
                return true;
            }
        }

        return null;
    }
}
