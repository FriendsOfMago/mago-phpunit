<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Mock;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\TypeComparator;
use MagoPHPUnit\Internal\Type\Types;

use function in_array;
use function preg_match;

/**
 * Drops the argument report for an `X|MockObject` passed where `X` is expected.
 *
 * @internal
 */
final class MockUnionArgumentFilter implements IssueFilterHook
{
    private const MOCK_OBJECT = 'PHPUnit\Framework\MockObject\MockObject';

    /**
     * Members skipped when the passed union is checked. Mago reports a `null`
     * member separately as `possibly-null-argument`.
     */
    private const SKIPPED = [self::MOCK_OBJECT, 'null'];

    /**
     * Matches the expected and the passed type in the message of Mago's report.
     */
    private const MESSAGE = '/expected `(?<expected>[^`]+)`, but possibly received `(?<received>[^`]+)`\.$/';

    /**
     * The brackets that keep a `|` inside a member of a printed union, such as a generic, a shape or a callable.
     */
    private const BRACKETS = '<>{}()[]';

    private const CLASS_NAME = '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/';

    /**
     * Filters `possibly-invalid-argument` reports.
     */
    public function getCodes(): array
    {
        return ['possibly-invalid-argument'];
    }

    /**
     * Removes the report when `MockObject` is the only member that doesn't fit.
     *
     * Tests document mocks as `X|MockObject`, from before PHP had intersection
     * types. Drupal core's `UnitTestCase` helpers return them that way too. At
     * runtime the value is both. phpstan-phpunit treats the union as
     * `X&MockObject`. Mago checks each half on its own and reports the
     * `MockObject` half as a possibly invalid argument.
     *
     * The report is removed when every other member of the passed union is
     * the expected type or a class that fits it. A `null` member is ignored.
     * The report stays for any other member that doesn't fit.
     */
    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $matches = [];
        if (preg_match(self::MESSAGE, $context->issue->message, $matches) !== 1) {
            return IssueFilterDecision::Keep;
        }

        $received = Types::unionMembers($matches['received'], brackets: self::BRACKETS);
        if (!in_array(self::MOCK_OBJECT, $received, strict: true)) {
            return IssueFilterDecision::Keep;
        }

        $expected = Types::unionMembers($matches['expected'], brackets: self::BRACKETS);
        $others = 0;
        foreach ($received as $member) {
            if (in_array($member, self::SKIPPED, strict: true)) {
                continue;
            }

            if (!self::fits($context->types, $member, $expected)) {
                return IssueFilterDecision::Keep;
            }

            $others++;
        }

        return $others > 0 ? IssueFilterDecision::Remove : IssueFilterDecision::Keep;
    }

    /**
     * Whether one member of the passed union is a member of the expected
     * type, or a class that fits one of its classes.
     *
     * @param list<string> $expected
     */
    private static function fits(TypeComparator $types, string $member, array $expected): bool
    {
        if (in_array($member, $expected, strict: true)) {
            return true;
        }

        if (preg_match(self::CLASS_NAME, $member) !== 1) {
            return false;
        }

        foreach ($expected as $class) {
            if (
                preg_match(self::CLASS_NAME, $class) === 1
                && $types->isContainedBy(Type::namedObject($member), Type::namedObject($class))
            ) {
                return true;
            }
        }

        return false;
    }
}
