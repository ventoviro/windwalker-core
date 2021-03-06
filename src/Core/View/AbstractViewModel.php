<?php

/**
 * Part of starter project.
 *
 * @copyright  Copyright (C) 2020 __ORGANIZATION__.
 * @license    __LICENSE__
 */

declare(strict_types=1);

namespace Windwalker\Core\View;

use Windwalker\Core\Application\AppContext;

/**
 * The ViewModel class.
 */
abstract class AbstractViewModel implements ViewModelInterface
{
    /**
     * Prepare
     *
     * @param  AppContext  $app
     * @param  View        $view
     *
     * @return  mixed
     */
    abstract public function prepare(AppContext $app, \Windwalker\Core\View\View $view): mixed;
}
