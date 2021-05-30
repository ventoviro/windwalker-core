<?php

/**
 * Part of starter project.
 *
 * @copyright  Copyright (C) 2020 __ORGANIZATION__.
 * @license    __LICENSE__
 */

declare(strict_types=1);

namespace Windwalker\Core\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Windwalker\Core\Application\AppContext;
use Windwalker\Core\Application\MiddlewareRunner;
use Windwalker\Core\Http\AppRequest;
use Windwalker\Core\Router\Exception\UnAllowedMethodException;
use Windwalker\Core\Router\Route;
use Windwalker\Core\Router\Router;
use Windwalker\Core\Router\SystemUri;
use Windwalker\DI\Container;
use Windwalker\DI\Exception\DefinitionException;

/**
 * The RoutingMiddleware class.
 */
class RoutingMiddleware implements MiddlewareInterface
{
    /**
     * RoutingMiddleware constructor.
     *
     * @param  AppContext  $app
     * @param  Router      $router
     */
    public function __construct(protected AppContext $app, protected Router $router)
    {
        //
    }

    /**
     * Process an incoming server request.
     *
     * Processes an incoming server request in order to produce a response.
     * If unable to produce the response itself, it may delegate to the provided
     * request handler to do so.
     *
     * @param  ServerRequestInterface   $request
     * @param  RequestHandlerInterface  $handler
     *
     * @return ResponseInterface
     * @throws DefinitionException
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $router = $this->router;

        $route = $router->match($request, $this->app->getSystemUri()->route);

        $controller = $this->findController($this->app->getRequestMethod(), $route);

        $this->app->getContainer()->modify(
            AppContext::class,
            fn(AppContext $context) => $context->withController($controller)
                ->withMatchedRoute($route)
        );

        $middlewares = $route->getMiddlewares();
        $runner = $this->app->make(MiddlewareRunner::class);

        return $runner->run(
            $request,
            $middlewares,
            fn (ServerRequestInterface $request) => $handler->handle($request)
        );
    }

    /**
     * findAction
     *
     * @param  ServerRequestInterface  $request
     * @param  Route                   $route
     *
     * @return  mixed
     */
    protected function findController(string $method, Route $route): mixed
    {
        $method = strtolower($method);

        $handlers = $route->getHandlers();

        if (isset($handlers[$method])) {
            $handler = $handlers[$method];

            if (is_string($handler)) {
                $handler = [$handlers['*'], $handler];
            }
        } else {
            $handler = $handlers['*'] ?? null;
        }

        if (!$handler) {
            throw new UnAllowedMethodException(
                sprintf(
                    'Handler for method: "%s" not found in route: "%s".',
                    $method,
                    $route->getName()
                )
            );
        }

        return $handler;
    }
}
