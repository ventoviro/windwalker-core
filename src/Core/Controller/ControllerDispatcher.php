<?php

/**
 * Part of starter project.
 *
 * @copyright  Copyright (C) 2020 .
 * @license    __LICENSE__
 */

namespace Windwalker\Core\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Windwalker\Core\Application\AppContext;
use Windwalker\Core\Controller\Exception\ControllerDispatchException;
use Windwalker\Core\Events\Web\AfterControllerDispatchEvent;
use Windwalker\Core\Events\Web\BeforeControllerDispatchEvent;
use Windwalker\DI\Container;
use Windwalker\Http\Response\RedirectResponse;
use Windwalker\Http\Response\Response;
use Windwalker\Utilities\StrNormalise;

/**
 * The ControllerDispatcher class.
 *
 * @since  __DEPLOY_VERSION__
 */
class ControllerDispatcher
{
    /**
     * ControllerDispatcher constructor.
     *
     * @param  Container  $container
     */
    public function __construct(protected Container $container)
    {
        //
    }

    public function dispatch(AppContext $app): ResponseInterface
    {
        $controller = $app->getController();

        $event = $app->emit(
            BeforeControllerDispatchEvent::class,
            compact('app', 'controller')
        );

        $controller = $event->getController();

        if ($controller === null) {
            throw new \LogicException(
                sprintf(
                    'Controller not found, please set "controller" as a callable to :' . $app::class
                )
            );
        }

        if (is_string($controller)) {
            if (str_contains($controller, '::')) {
                $controller = explode('::', $controller, 2);
            } elseif (class_exists($controller)) {
                $controller = [$controller, $this->getDefaultTask($app->getServerRequest())];
            }
        }

        if (is_array($controller)) {
            $controller = $this->prepareArrayCallable($controller);
        } else {
            $controller = fn(AppContext $app): mixed => $this->container->call($controller, $app->getUrlVars());
        }

        $response = $this->handleResponse($controller($app));

        $event = $app->emit(
            AfterControllerDispatchEvent::class,
            compact('app', 'response')
        );

        return $event->getResponse();
    }

    protected function getDefaultTask(ServerRequestInterface $request): string
    {
        $task = strtolower($request->getMethod());

        $map = [
            'get' => 'index',
            'post' => 'save',
            'put' => 'save',
            'patch' => 'save',
        ];

        $task = $map[$task] ?? $task;

        if (str_contains($task, '_')) {
            $task = StrNormalise::toCamelCase($task);
        }

        return $task;
    }

    protected function prepareArrayCallable(array $handler): \Closure
    {
        if (\Windwalker\count($handler) !== 2) {
            throw new \LogicException(
                'Controller callable should be array with 2 elements, got: ' . \Windwalker\count($handler)
            );
        }

        $handler[0] = $this->container->createSharedObject($handler[0]);

        if ($handler[0] instanceof ControllerInterface) {
            return function (AppContext $app) use ($handler): mixed {
                [$object, $task] = $handler;
                return $this->container->call(
                    [$object, 'execute'],
                    [$task, $app->getUrlVars()]
                );
            };
        }

        if (!is_callable($handler)) {
            throw new ControllerDispatchException('Controller is not callable.');
        }

        return function (AppContext $app) use ($handler) {
            $this->container->call($handler, $app->getUrlVars());
        };
    }

    /**
     * handleResponse
     *
     * @param  mixed  $res
     *
     * @return  ResponseInterface|Response
     *
     * @throws \JsonException
     * @since  __DEPLOY_VERSION__
     */
    protected function handleResponse(mixed $res): ResponseInterface
    {
        if ($res instanceof UriInterface) {
            return new RedirectResponse($res);
        }

        if (!$res instanceof ResponseInterface) {
            if (is_array($res) && is_object($res)) {
                return Response::fromString(json_encode($res, JSON_THROW_ON_ERROR));
            }

            return Response::fromString((string) $res);
        }

        return $res;
    }
}
