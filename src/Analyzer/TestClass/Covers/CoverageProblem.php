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

    case MissingNamespace;

    /**
     * A class-like of the wrong kind, such as an interface for `CoversClassesThatExtendClass`.
     */
    case WrongKind;

    /**
     * A class that isn't extended by any class, or an interface that isn't implemented by any class.
     */
    case Unused;
}
