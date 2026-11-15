<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\DataSets;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Span;
use MagoPHPUnit\Internal\Type\Types;
use PhpToken;

use function array_key_exists;
use function count;
use function ctype_digit;
use function in_array;
use function is_int;
use function is_string;
use function preg_match;
use function rtrim;
use function strlen;
use function substr;
use function trim;

use const T_DO;
use const T_ELSE;
use const T_ELSEIF;
use const T_ENDFOR;
use const T_ENDFOREACH;
use const T_ENDIF;
use const T_ENDSWITCH;
use const T_ENDWHILE;
use const T_FN;
use const T_FOR;
use const T_FOREACH;
use const T_FUNCTION;
use const T_GOTO;
use const T_IF;
use const T_MATCH;
use const T_RETURN;
use const T_SWITCH;
use const T_TRY;
use const T_WHILE;
use const T_YIELD;
use const T_YIELD_FROM;

/**
 * Finds the data sets in the arrays returned or yielded by a data provider.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class ProviderReturns
{
    /**
     * The tokens of control flow, including the ends of the alternative syntax.
     */
    private const FLOW = [
        T_IF,
        T_ELSEIF,
        T_ELSE,
        T_WHILE,
        T_DO,
        T_FOR,
        T_FOREACH,
        T_SWITCH,
        T_MATCH,
        T_TRY,
        T_RETURN,
        T_GOTO,
        T_ENDIF,
        T_ENDWHILE,
        T_ENDFOR,
        T_ENDFOREACH,
        T_ENDSWITCH,
    ];

    private function __construct() {}

    /**
     * The label, type and span of each data set in the values, once their types are known.
     *
     * @param list<ReturnedValue> $values
     * @return list<DataSet>
     */
    public static function build(array $values): array
    {
        $sets = [];
        $yields = 0;
        foreach ($values as $value) {
            $type = $value->type;
            if ($type === null) {
                continue;
            }

            // PHP numbers the values yielded without a key from 0.
            if ($value->keyword === T_YIELD) {
                $key = $value->key;
                $label = match (true) {
                    $key === null => '#' . (string) $yields++,
                    ctype_digit($key) => '#' . $key,
                    default => $key,
                };
                $sets[] = new DataSet($label, $type, $value->span);
                continue;
            }

            foreach (self::rows($type) as [$rowKey, $row]) {
                $sets[] = new DataSet(is_int($rowKey) ? '#' . (string) $rowKey : "'{$rowKey}'", $row, $value->span);
            }
        }

        return $sets;
    }

    /**
     * The string keys of data sets always given, each with its data set span.
     *
     * @param list<ReturnedValue> $values
     * @return array<array-key, Span>
     */
    public static function stringKeys(array $values): array
    {
        // Keys are taken from the provider's only `return` or `yield from`. A top-level `yield`
        // with a quoted key counts in a generator without control flow. Any `if`, `elseif`, `else`,
        // `while`, `do`, `for`, `foreach`, `switch`, `match`, `try`, `return` or `goto` outside a
        // closure counts as control flow. The alternative syntax `end` keywords count too.
        $keys = [];
        $returns = count($values) === 1 && $values[0]->keyword !== T_YIELD;
        foreach ($values as $value) {
            $type = $value->type;
            $span = $value->span;
            if (!$value->alwaysGiven || $type === null) {
                continue;
            }

            if ($value->keyword === T_YIELD) {
                $text = $value->key === null ? null : self::stringKey($value->key);
                if ($text !== null) {
                    $keys[$text] ??= $span;
                }

                continue;
            }

            // PHP turns an array key such as '3' into an integer. PHPUnit appends a data
            // set with an integer key. Such keys aren't returned here for arrays.
            // For generators, '3' remains a string key. PHPUnit checks it as a string.
            $atomic = $returns && count($type->atomicTypes) === 1 ? $type->atomicTypes[0] : null;
            foreach ($atomic instanceof KeyedArrayType ? $atomic->knownItems ?? [] : [] as $item) {
                if ($item->optional || !is_string($item->key->value)) {
                    continue;
                }

                $keys[$item->key->value] ??= $span;
            }
        }

        return $keys;
    }

    /**
     * The string written as a quoted key without escapes or interpolation, or null.
     */
    private static function stringKey(string $key): ?string
    {
        $matches = [];
        if (preg_match('/^\'([^\'\\\\]*)\'$|^"([^"\\\\$]*)"$/', $key, $matches) !== 1) {
            return null;
        }

        return ($matches[1] ?? '') . ($matches[2] ?? '');
    }

    /**
     * Whether the provider returns only empty arrays without any data sets.
     *
     * @param list<ReturnedValue> $values
     */
    public static function empty(array $values): bool
    {
        // A generator isn't checked. Whether a value is yielded depends on its control flow.
        // Generators are also skipped by phpstan-phpunit's `EmptyDataProviderRule`.
        if ($values === []) {
            return false;
        }

        foreach ($values as $value) {
            if ($value->keyword !== T_RETURN || !Types::emptyArray($value->type)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The rows of an array of data sets, each with its key.
     *
     * @return list<array{int|string, Type}>
     */
    private static function rows(Type $type): array
    {
        if (count($type->atomicTypes) !== 1) {
            return [];
        }

        $atomic = $type->atomicTypes[0];
        $rows = [];
        foreach ($atomic instanceof ListType ? $atomic->knownElements ?? [] : [] as $element) {
            $rows[] = [$element->index, $element->type];
        }

        foreach ($atomic instanceof KeyedArrayType ? $atomic->knownItems ?? [] : [] as $item) {
            $rows[] = [$item->key->value ?? '', $item->type];
        }

        return $rows;
    }

    /**
     * The values of `return`, `yield` and `yield from` in a provider body, without their types.
     *
     * @param string $contents The contents of the provider's file.
     * @param Span $method The span of the provider method.
     * @return list<ReturnedValue>
     *
     * @mago-expect lint:halstead
     */
    public static function find(string $contents, Span $method): array
    {
        // The types are fetched by the caller in one query, together with the values of the
        // other providers in the file.
        $code = '<?php ' . substr($contents, $method->start, $method->length());
        $tokens = PhpToken::tokenize($code);
        $skipped = self::closureBodies($tokens);
        $offset = $method->start - strlen('<?php ');
        $found = [];
        $generator = false;
        // Whether the body runs straight through, without control flow
        // outside its closures.
        $straight = true;
        foreach ($tokens as $index => $token) {
            $straight = $straight && (array_key_exists($index, $skipped) || !$token->is(self::FLOW));
        }

        $count = count($tokens);
        // The brace depth, which is 1 in the method body, and the last token
        // that isn't whitespace or a comment.
        $depth = 0;
        $previous = '';
        for ($index = 0; $index < $count; $index++) {
            if (array_key_exists($index, $skipped)) {
                continue;
            }

            $token = $tokens[$index];
            if (!$token->is([T_RETURN, T_YIELD, T_YIELD_FROM])) {
                $depth += match ($token->text) {
                    '{', '${' => 1,
                    '}' => -1,
                    default => 0,
                };
                $previous = $token->isIgnorable() ? $previous : $token->text;
                continue;
            }

            // A return is treated as always given only directly in the method body,
            // outside a control structure. A yield or yield from counts only in a body
            // without control flow. Blocks in the alternative syntax don't have braces.
            // A return in such a block can be in a branch.
            $top =
                $depth === 1
                && in_array($previous, [';', '{', '}'], strict: true)
                && ($straight || $token->id === T_RETURN);
            $kind = $token->id === T_YIELD ? T_YIELD : T_RETURN;
            $generator = $generator || $token->id !== T_RETURN;
            $value = self::value($code, $tokens, $index, $kind);
            if ($value === null) {
                continue;
            }

            [$start, $end, $key, $next] = $value;
            // Each value keeps its keyword: `T_RETURN`, `T_YIELD` or `T_YIELD_FROM`.
            // For `yield`, the key is the text before the value. Rows from `yield from`
            // are data sets, as with `return`.
            $found[] = new ReturnedValue($token->id, new Span($offset + $start, $offset + $end), $key, $top);
            $index = $next;
            $previous = $tokens[$next]->text;
            $depth += $previous === '}' ? -1 : 0;
        }

        $values = [];
        foreach ($found as $value) {
            // A provider with a `yield` is a generator. PHPUnit doesn't iterate
            // a generator's return value, so it isn't a data set.
            if ($generator && $value->keyword === T_RETURN) {
                continue;
            }

            $values[] = $value;
        }

        return $values;
    }

    /**
     * The indexes of the tokens inside each closure and arrow function in the
     * method.
     *
     * The first `function` token is the method itself and is skipped. A
     * `return` or `yield` inside a closure belongs to the closure, not to the
     * provider.
     *
     * @param list<PhpToken> $tokens
     * @return array<int, true>
     */
    private static function closureBodies(array $tokens): array
    {
        $skipped = [];
        $functions = 0;
        $count = count($tokens);
        $previous = null;
        for ($index = 0; $index < $count; $index++) {
            // A method named `fn` is a `T_FN` token after `function`.
            $arrow = $tokens[$index]->is(T_FN) && $previous?->is(T_FUNCTION) !== true;
            if (!$tokens[$index]->isIgnorable()) {
                $previous = $tokens[$index];
            }

            if (!$arrow && (!$tokens[$index]->is(T_FUNCTION) || ++$functions === 1)) {
                continue;
            }

            // A closure's body ends at its closing brace. An arrow function's
            // body ends at the end of its expression.
            $depth = 0;
            $open = false;
            for ($inner = $index; $inner < $count; $inner++) {
                $skipped[$inner] = true;
                $text = $tokens[$inner]->text;
                if (!$arrow && $text === '{') {
                    $open = true;
                }

                $depth += match ($text) {
                    '(', '[', '{', '${' => 1,
                    ')', ']', '}' => -1,
                    default => 0,
                };
                if (
                    $depth < 0
                    || $depth === 0 && ($arrow ? in_array($text, [';', ','], strict: true) : $open && $text === '}')
                ) {
                    break;
                }
            }

            $index = $inner;
        }

        return $skipped;
    }

    /**
     * The value of the statement whose keyword is at the index.
     *
     * Returns the start and end offsets of the expression after the keyword,
     * the key in front of a yielded value, and the index of the token that
     * ends the statement. Null when the statement doesn't have a value.
     *
     * @param list<PhpToken> $tokens
     * @return array{int, int, ?string, int}|null
     */
    private static function value(string $code, array $tokens, int $index, int $kind): ?array
    {
        $start = null;
        $key = null;
        $depth = 0;
        $count = count($tokens);
        for ($next = $index + 1; $next < $count; $next++) {
            $current = $tokens[$next];
            if ($depth === 0 && in_array($current->text, [';', ')', ']', '}'], strict: true)) {
                break;
            }

            if ($depth === 0 && $kind === T_YIELD && $current->text === '=>' && $start !== null) {
                // The data set is the value after the key.
                $key = trim(substr($code, $start, $current->pos - $start));
                $start = null;
                continue;
            }

            if ($start === null && !$current->isIgnorable()) {
                $start = $current->pos;
            }

            $depth += match ($current->text) {
                '(', '[', '{', '${' => 1,
                ')', ']', '}' => -1,
                default => 0,
            };
        }

        if ($start === null || $next >= $count) {
            return null;
        }

        $end = $start + strlen(rtrim(substr($code, $start, $tokens[$next]->pos - $start)));

        return [$start, $end, $key, $next];
    }
}
