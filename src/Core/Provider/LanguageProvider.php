<?php

/**
 * Part of starter project.
 *
 * @copyright  Copyright (C) 2021 __ORGANIZATION__.
 * @license    __LICENSE__
 */

declare(strict_types=1);

namespace Windwalker\Core\Provider;

use Windwalker\Core\Language\LangService;
use Windwalker\DI\BootableDeferredProviderInterface;
use Windwalker\DI\Container;
use Windwalker\DI\ServiceProviderInterface;
use Windwalker\Language\Language;
use Windwalker\Language\LanguageInterface;

/**
 * The LanguageProvider class.
 */
class LanguageProvider implements ServiceProviderInterface, BootableDeferredProviderInterface
{
    /**
     * @inheritDoc
     */
    public function register(Container $container): void
    {
        $container->prepareSharedObject(LangService::class)
            ->alias(Language::class, LangService::class)
            ->alias(LanguageInterface::class, Language::class);
    }

    /**
     * @inheritDoc
     */
    public function bootDeferred(Container $container): void
    {
        $container->get(LangService::class)->loadAll();
    }
}
