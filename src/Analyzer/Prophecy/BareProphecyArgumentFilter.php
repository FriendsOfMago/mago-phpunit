<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\Prophecy;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use MagoPHPUnit\Internal\Type\Types;

use function array_diff;
use function array_filter;
use function in_array;
use function preg_match;

/**
 * Drops the argument report for a bare prophecy passed to a prophecy call.
 *
 * @internal
 */
final class BareProphecyArgumentFilter implements IssueFilterHook
{
    /**
     * Matches the expected and the passed type in the message of Mago's report.
     */
    private const MESSAGE = '/expected `(?<expected>[^`]+)`, but provided type `(?<received>[^`]+)` is less specific\.$/';

    /**
     * The brackets that keep a `|` inside a member of a printed union, such as a generic, a shape or a callable.
     */
    private const BRACKETS = '<>{}()[]';

    /**
     * Matches the printed type of a prophecy without the prophesized class,
     * also inside an array type.
     */
    private const BARE = '/Prophecy\\\\Prophecy\\\\(?:ObjectProphecy|ProphecyInterface)(?:<object>)?(?![\\w<\\\\])/';

    /**
     * Filters `less-specific-argument` reports.
     */
    public function getCodes(): array
    {
        return ['less-specific-argument'];
    }

    /**
     * Removes the report when a prophecy call gets a prophecy documented
     * without its class, such as `@var ObjectProphecy`, `@var
     * Foo|ObjectProphecy` or an array of them.
     *
     * The class of such a prophecy isn't known, so it isn't checked. Each
     * other member of the passed type must be one of the expected members.
     * The parameter types of a prophecy call are built by
     * ProphecyCallProvider. Only these types have the argument token as a
     * member.
     */
    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $matches = [];
        if (preg_match(self::MESSAGE, $context->issue->message, $matches) !== 1) {
            return IssueFilterDecision::Keep;
        }

        $expected = Types::unionMembers($matches['expected'], brackets: self::BRACKETS);
        $received = Types::unionMembers($matches['received'], brackets: self::BRACKETS);

        if (!in_array(ProphecyCallProvider::ARGUMENT_MATCHER, $expected, strict: true)) {
            return IssueFilterDecision::Keep;
        }

        $bare = array_filter($received, static fn(string $member): bool => preg_match(self::BARE, $member) === 1);
        $other = array_diff($received, $bare, $expected, ['null']);

        return $bare !== [] && $other === [] ? IssueFilterDecision::Remove : IssueFilterDecision::Keep;
    }
}
