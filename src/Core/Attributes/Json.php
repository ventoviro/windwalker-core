<?php

/**
 * Part of starter project.
 *
 * @copyright  Copyright (C) 2021 __ORGANIZATION__.
 * @license    __LICENSE__
 */

declare(strict_types=1);

namespace Windwalker\Core\Attributes;

use Windwalker\Core\Application\AppContext;
use Windwalker\Core\Middleware\JsonResponseMiddleware;
use Windwalker\DI\Attributes\AttributeHandler;
use Windwalker\DI\Attributes\ContainerAttributeInterface;

/**
 * The Json class.
 */
#[\Attribute(\Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
class Json implements ContainerAttributeInterface
{
    public function __invoke(AttributeHandler $handler): callable
    {
        return function (...$args) use ($handler) {
            $container = $handler->getContainer();

            return $container->newInstance(JsonResponseMiddleware::class)->run(fn () => $handler(...$args));
        };
    }
}
