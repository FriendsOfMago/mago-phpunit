<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\SourceFile;
use MagoPHPUnit\Internal\Type\Types;

use function array_values;
use function in_array;
use function str_starts_with;
use function strtolower;

/**
 * Reads the classes of a PHPUnit double from its type.
 *
 * @internal
 */
final class Doubles
{
    /**
     * The lowercased names of PHPUnit's double interfaces.
     */
    public const NAMES = ['phpunit\framework\mockobject\mockobject', 'phpunit\framework\mockobject\stub'];

    private const PHPUNIT = 'phpunit\\';

    /**
     * The lowercased `TestCase` methods that create a double of the class
     * they're given first.
     */
    private const CREATORS = [
        'createmock',
        'createstub',
        'createconfiguredmock',
        'createconfiguredstub',
        'createpartialmock',
    ];

    private function __construct() {}

    /**
     * Whether the name is one of PHPUnit's double interfaces.
     */
    public static function isDoubleName(string $name): bool
    {
        return in_array(strtolower($name), self::NAMES, strict: true);
    }

    /**
     * Whether the expression creates a double of a named class, such as
     * `$this->createStub(Foo::class)`, so the class of the double is known.
     *
     * The call must be on `$this`, `self`, `static` or `parent`, with a
     * literal class name.
     *
     * @param SourceFile $source The syntax that has the expression.
     */
    public static function isCreatedBy(NodeAnalysisContext $context, SourceFile $source, Node $expression): bool
    {
        $call = CallExpression::fromExpression($source, $expression);
        $class = $call?->arguments[0] ?? null;
        $receiver = $call?->receiver === null ? '' : strtolower($source->getText($call->receiver));
        if (
            $call === null
            || $class === null
            || !in_array($receiver, ['$this', 'self', 'static', 'parent'], strict: true)
            || !in_array(strtolower($call->getName($source) ?? ''), self::CREATORS, strict: true)
        ) {
            return false;
        }

        return Types::literalName($context->analysis->getExpressionType($class->value)) !== '';
    }

    /**
     * The doubled classes in the type, such as `Foo` for `MockObject&Foo`, or
     * an empty list when the type doesn't name a double.
     *
     * The classes of PHPUnit itself are left out. A legacy union such as
     * `Foo|MockObject` counts too.
     *
     * @return list<non-empty-string>
     */
    public static function classes(?Type $type): array
    {
        $isDouble = false;
        $classes = [];
        foreach (Types::names($type) as $name) {
            $lower = strtolower($name);
            if (in_array($lower, self::NAMES, strict: true)) {
                $isDouble = true;
                continue;
            }

            if (!str_starts_with($lower, self::PHPUNIT)) {
                $classes[$lower] = $name;
            }
        }

        return $isDouble ? array_values($classes) : [];
    }
}
