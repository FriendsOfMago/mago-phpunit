<?php

/**
 * @file
 * Version requirements with an operator but without a minor or patch level, which PHPUnit 12.5 and later warn about.
 */

// Corpus: PHPUnit 12 and later.

declare(strict_types=1);

namespace Corpus;

use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RequiresPhpunit;
use PHPUnit\Framework\TestCase;

// @mago-expect analysis:phpunit/incomplete-requires-version
#[RequiresPhp('>= 8')]
final class IncompleteRequiresVersionTest extends TestCase
{
    // @mago-expect analysis:phpunit/incomplete-requires-version
    #[RequiresPhp('>=8.1')]
    public function testMinorOnly(): void
    {
        $this->addToAssertionCount(1);
    }

    // @mago-expect analysis:phpunit/incomplete-requires-version
    #[RequiresPhpunit('< 14')]
    public function testMajorOnly(): void
    {
        $this->addToAssertionCount(1);
    }

    // @mago-expect analysis:phpunit/incomplete-requires-version
    #[RequiresPhpExtension('json', '>= 1.0')]
    public function testExtension(): void
    {
        $this->addToAssertionCount(1);
    }

    #[RequiresPhp('>= 8.1.0')]
    public function testComplete(): void
    {
        $this->addToAssertionCount(1);
    }

    /**
     * A constraint without an operator goes through PHPUnit's version constraint parser, which doesn't warn.
     */
    #[RequiresPhp('^8.1')]
    public function testConstraint(): void
    {
        $this->addToAssertionCount(1);
    }

    /**
     * A label right after the number isn't accepted by the constraint parser, so PHPUnit reads it as `>= 8.1dev`.
     */
    // @mago-expect analysis:phpunit/incomplete-requires-version
    #[RequiresPhp('8.1dev')]
    public function testLabel(): void
    {
        $this->addToAssertionCount(1);
    }

    /**
     * A caret doesn't help when the constraint parser rejects the label.
     */
    // @mago-expect analysis:phpunit/incomplete-requires-version
    #[RequiresPhp('^8.1dev')]
    public function testCaretLabel(): void
    {
        $this->addToAssertionCount(1);
    }

    /**
     * One part with an operator makes the constraint parser reject the whole requirement.
     */
    // @mago-expect analysis:phpunit/incomplete-requires-version
    #[RequiresPhp('8.4.0 || >=9')]
    public function testOrGroup(): void
    {
        $this->addToAssertionCount(1);
    }

    /**
     * PHPUnit doesn't trim the requirement, and the constraint parser rejects the space.
     */
    // @mago-expect analysis:phpunit/incomplete-requires-version
    #[RequiresPhp('^8.1 ')]
    public function testTrailingSpace(): void
    {
        $this->addToAssertionCount(1);
    }

    /**
     * The constraint parser throws for `^8`, which isn't a SemVer version, so PHPUnit errors instead of warning.
     */
    #[RequiresPhp('^8 || >= 9')]
    public function testCaretMajorInGroup(): void
    {
        $this->addToAssertionCount(1);
    }

    /**
     * With a dash, the constraint parser accepts the label, and PHPUnit doesn't warn.
     */
    #[RequiresPhp('8.1-dev')]
    public function testDashedLabel(): void
    {
        $this->addToAssertionCount(1);
    }

    #[RequiresPhpExtension('json')]
    public function testWithoutVersion(): void
    {
        $this->addToAssertionCount(1);
    }
}
