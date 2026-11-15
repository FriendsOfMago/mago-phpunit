<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\Covers;

/**
 * The reason PHPUnit rejects a coverage target.
 *
 * @internal
 */
enum CoverageProblem
{
    case MissingClass;

    case MissingMember;

    case Builtin;

    case Interface;

    case Trait;

    case Inherited;
}
