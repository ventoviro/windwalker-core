<?php

/**
 * Part of starter project.
 *
 * @copyright  Copyright (C) 2021 __ORGANIZATION__.
 * @license    __LICENSE__
 */

declare(strict_types=1);

namespace Windwalker\Core\Attributes;

use Psr\Http\Message\RequestInterface;
use Windwalker\Core\Middleware\CsrfMiddleware;
use Windwalker\DI\Attributes\AttributeHandler;
use Windwalker\DI\Attributes\ContainerAttributeInterface;

/**
 * The Csrf class.
 */
#[\Attribute(\Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
class Csrf implements ContainerAttributeInterface
{
    public function __invoke(AttributeHandler $handler): callable
    {
        return function (...$args) use ($handler) {
            $container = $handler->getContainer();

            return $container->newInstance(CsrfMiddleware::class)
                ->run(
                    $container->get(RequestInterface::class),
                    fn () => $handler(...$args)
                );
        };
    }
}
