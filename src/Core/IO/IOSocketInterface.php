<?php

/**
 * Part of starter project.
 *
 * @copyright  Copyright (C) 2021 __ORGANIZATION__.
 * @license    __LICENSE__
 */

declare(strict_types=1);

namespace Windwalker\Core\IO;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Windwalker\Console\IOInterface;

/**
 * Interface IOSocketInterface
 */
interface IOSocketInterface
{
    /**
     * Set IO object.
     *
     * @param  InputInterface|OutputInterface|IOInterface|null  $io
     *
     * @return  void
     */
    public function setIO(InputInterface|OutputInterface|IOInterface|null $io): void;

    /**
     * Use IO if exists.
     *
     * @param  callable  $callback
     *
     * @return  void
     */
    public function useIO(callable $callback): void;
}
