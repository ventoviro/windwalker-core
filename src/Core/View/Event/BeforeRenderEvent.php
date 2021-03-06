<?php

/**
 * Part of starter project.
 *
 * @copyright  Copyright (C) 2021 __ORGANIZATION__.
 * @license    __LICENSE__
 */

declare(strict_types=1);

namespace Windwalker\Core\View\Event;

/**
 * The BeforeRenderEvent class.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class BeforeRenderEvent extends AbstractViewRenderEvent
{
    //
}
