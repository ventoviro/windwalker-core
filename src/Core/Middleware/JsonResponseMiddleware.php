<?php

/**
 * Part of starter project.
 *
 * @copyright  Copyright (C) 2021 __ORGANIZATION__.
 * @license    __LICENSE__
 */

declare(strict_types=1);

namespace Windwalker\Core\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Windwalker\Core\Application\AppContext;
use Windwalker\Core\Service\ErrorService;
use Windwalker\DI\DICreateTrait;
use Windwalker\Http\Response\JsonResponse;

use function Windwalker\response;

/**
 * The JsonResponseMiddleware class.
 */
class JsonResponseMiddleware implements MiddlewareInterface
{
    use DICreateTrait;

    /**
     * JsonResponseMiddleware constructor.
     */
    public function __construct(protected AppContext $app)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->run(fn () => $handler->handle($request));
    }

    public function run(\Closure $callback): ResponseInterface
    {
        try {
            $response = $callback();

            return static::toJsonResponse($response);
        } catch (\Throwable $e) {
            return response()->json(
                [
                    'error' => !$this->app->isDebug() ? $e->getMessage() : sprintf(
                        '#%d %s - File: %s (%d)',
                        $e->getCode(),
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine()
                    ),
                    'status' => ErrorService::normalizeCode($e->getCode()),
                    'code' => $e->getCode(),
                ]
            );
        }
    }

    protected static function toJsonResponse(ResponseInterface $response): JsonResponse
    {
        return JsonResponse::from($response);
    }
}
