<?php

/**
 * Part of starter project.
 *
 * @copyright  Copyright (C) 2021 __ORGANIZATION__.
 * @license    __LICENSE__
 */

declare(strict_types=1);

namespace Windwalker\Core\Application;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Relay\Relay;
use Windwalker\DI\Container;

/**
 * The MiddlewareRunner class.
 */
class MiddlewareRunner
{
    /**
     * MiddlewareRunner constructor.
     *
     * @param  Container  $container
     */
    public function __construct(protected Container $container)
    {
    }

    public function run(
        ServerRequestInterface $request,
        iterable $middlewares,
        ?callable $last = null
    ): ResponseInterface {
        $middlewares = $this->compileMiddlewares($middlewares, $last);

        return static::createRequestHandler($middlewares)->handle($request);
    }

    /**
     * compileMiddlewares
     *
     * @param  iterable  $middlewares
     * @param  callable|null  $last
     *
     * @return \Generator
     *
     * @throws \ReflectionException
     */
    public function compileMiddlewares(iterable $middlewares, ?callable $last = null): \Generator
    {
        foreach ($middlewares as $i => $middleware) {
            yield $this->container->resolve($middleware);
        }

        if ($last) {
            yield $last;
        }
    }

    public function resolveMiddleware(mixed $middleware): mixed
    {
        if ($middleware === false || $middleware === null) {
            return null;
        }

        if (is_callable($middleware)) {
            return $middleware;
        }

        return $this->container->resolve($middleware);
    }

    public static function createRequestHandler(iterable $queue): RequestHandlerInterface
    {
        return new Relay($queue);
    }
}
