<?php

/**
 * @file
 * A promoted constructor parameter documented as a prophecy union.
 */

declare(strict_types=1);

namespace Corpus;

final class PromotedProphecy
{
    /**
     * @param \Corpus\Thing|\Prophecy\Prophecy\ObjectProphecy $thing
     *   The prophecy, reported once although it's a parameter and a property.
     */
    public function __construct(
        // @mago-expect analysis:phpunit/prophecy-union(1)
        public $thing,
    ) {}
}
