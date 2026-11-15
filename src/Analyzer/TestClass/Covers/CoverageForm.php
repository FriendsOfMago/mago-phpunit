<?php

declare(strict_types=1);

namespace MagoPHPUnit\Analyzer\TestClass\Covers;

/**
 * The way a coverage target is written: a `@covers` tag or one of the covers attributes.
 *
 * @internal
 */
enum CoverageForm: string
{
    /**
     * A `@covers` tag, mapped like `Class`, `Class::name` or `name`.
     */
    case Annotation = '@covers';

    case ClassAttribute = 'CoversClass';

    case TraitAttribute = 'CoversTrait';

    case MethodAttribute = 'CoversMethod';

    case FunctionAttribute = 'CoversFunction';
}
