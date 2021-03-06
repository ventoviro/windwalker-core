<?php

/**
 * Part of starter project.
 *
 * @copyright  Copyright (C) 2021 __ORGANIZATION__.
 * @license    __LICENSE__
 */

declare(strict_types=1);

namespace Windwalker\Core\Http;

use JetBrains\PhpStorm\Immutable;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;
use Windwalker\Core\Form\Exception\ValidateFailException;
use Windwalker\Core\Router\Route;
use Windwalker\Core\Router\SystemUri;
use Windwalker\Data\Collection;
use Windwalker\Data\Format\FormatRegistry;
use Windwalker\Filter\Exception\ValidateException;
use Windwalker\Filter\Traits\FilterAwareTrait;
use Windwalker\Uri\Uri;
use Windwalker\Utilities\Arr;
use Windwalker\Utilities\Assert\ArgumentsAssert;

use Windwalker\Utilities\TypeCast;

use function Windwalker\collect;

/**
 * The AppRequest class.
 */
#[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
class AppRequest implements \JsonSerializable
{
    use FilterAwareTrait;

    protected ?array $input = null;

    protected ?Route $matchedRoute = null;

    /**
     * AppRequest constructor.
     *
     * @param  ServerRequestInterface  $request
     * @param  SystemUri               $systemUri
     */
    public function __construct(protected ServerRequestInterface $request, protected SystemUri $systemUri)
    {
        //
    }

    public function getMethod(): string
    {
        return $this->request->getMethod();
    }

    public function getOverrideMethod(): string
    {
        return $this->request->getHeaderLine('X-Http-Method-Override')
            ?: $this->input('_method')
            ?: $this->request->getMethod();
    }

    /**
     * @return Uri
     */
    public function getUri(): Uri
    {
        return $this->request->getUri();
    }

    /**
     * @param  UriInterface|null  $uri
     *
     * @return  static  Return self to support chaining.
     */
    public function withUri(UriInterface $uri): static
    {
        $new = clone $this;
        $new->request = $new->request->withUri($uri);
        $this->input = null;

        return $new;
    }

    public function getQueryValues(): array
    {
        return array_merge(
            $this->getUrlVars(),
            $this->getUri()->getQueryValues()
        );
    }

    /**
     * @return array
     */
    public function getUrlVars(): array
    {
        if (!$this->matchedRoute) {
            return [];
        }

        return $this->matchedRoute->getVars();
    }

    /**
     * @param  array  $vars
     *
     * @return  static  Return self to support chaining.
     */
    public function withUrlVars(array $vars): static
    {
        $new = clone $this;
        $new->matchedRoute = clone $new->matchedRoute;
        $new->matchedRoute->vars($vars);

        $new->input = null;

        return $new;
    }

    public function getBodyValues(): array
    {
        return $this->getRequest()->getParsedBody();
    }

    /**
     * @return array
     */
    public function getInput(): array
    {
        return $this->input;
    }

    /**
     * @param  array  $input
     *
     * @return  static  Return self to support chaining.
     */
    public function withInput(array $input): static
    {
        $new = clone $this;
        $new->input = $input;

        return $new;
    }

    public function getHeader(string $name): string
    {
        return $this->request->getHeaderLine($name);
    }

    /**
     * input
     *
     * @param  mixed  ...$fields
     *
     * @return  mixed|Collection
     */
    public function input(...$fields): mixed
    {
        $input = $this->compileInput();

        if ($fields === []) {
            return collect($input);
        }

        return $this->fetchInputFields($input, $fields);
    }

    /**
     * inputWithMethod
     *
     * @param  string  $method
     * @param  mixed   ...$fields
     *
     * @return  mixed|Collection
     */
    public function inputWithMethod(string $method = 'REQUEST', ...$fields): mixed
    {
        if ($method === '' || strtoupper($method) === 'REQUEST') {
            $input = $this->compileInput();
        } elseif (strtoupper($method) === 'GET') {
            $input = $this->getQueryValues();
        } else {
            $input = $this->getBodyValues();
        }

        if ($fields === []) {
            return $input;
        }

        return $this->fetchInputFields($input, $fields);
    }

    /**
     * fetchInputFields
     *
     * @param  array  $data
     * @param  array  $fields
     *
     * @return  mixed|Collection
     */
    private function fetchInputFields(array $input, array $fields): mixed
    {
        $data = [];

        if (array_is_list($fields)) {
            foreach ($fields as $field) {
                $data[$field] = $input[$field] ?? null;
            }
        } else {
            foreach (array_keys($fields) as $field) {
                $data[$field] = $input[$field] ?? null;
            }

            try {
                $this->getFilterFactory()->createNested($fields)->test($data);
            } catch (ValidateException $e) {
                throw new ValidateFailException(
                    $e->getMessage(),
                    400,
                    $e
                );
            }
        }

        if (count($fields) === 1) {
            return array_shift($data);
        }

        return collect($data);
    }

    /**
     * file
     *
     * @param  mixed  ...$fields
     *
     * @return  UploadedFileInterface[]|UploadedFileInterface|array
     */
    public function file(...$fields): mixed
    {
        $files = $this->getRequest()->getUploadedFiles();

        if ($fields === []) {
            return $files;
        }

        $data = [];

        foreach ($fields as $field) {
            $data[$field] = $files[$field] ?? null;
        }

        if (\Windwalker\count($fields) === 1) {
            return array_shift($data);
        }

        return collect($data);
    }

    protected function compileInput(): array
    {
        return $this->input ??= array_merge(
            $this->getQueryValues(),
            $this->getBodyValues()
        );
    }

    /**
     * @return ServerRequestInterface
     */
    public function getRequest(): ServerRequestInterface
    {
        return $this->request;
    }

    /**
     * @param  ServerRequestInterface  $request
     *
     * @return  static  Return self to support chaining.
     */
    public function withRequest(ServerRequestInterface $request): static
    {
        $new = clone $this;
        $new->request = $request;
        $this->input = null;

        return $new;
    }

    /**
     * @return SystemUri
     */
    public function getSystemUri(): SystemUri
    {
        return $this->systemUri;
    }

    /**
     * @param  SystemUri  $uri
     *
     * @return  static  Return self to support chaining.
     */
    public function withSystemUri(SystemUri $uri): static
    {
        $new            = clone $this;
        $new->systemUri = $uri;

        return $new;
    }

    public function accept(string $type): bool
    {
        return str_contains(
            $this->getRequest()->getHeaderLine('accept'),
            $type
        );
    }

    public function acceptJson(): bool
    {
        return $this->accept('application/json');
    }

    /**
     * @return Route|null
     */
    public function getMatchedRoute(): ?Route
    {
        return $this->matchedRoute;
    }

    /**
     * @param  Route|null  $matchedRoute
     *
     * @return  static  Return self to support chaining.
     */
    public function withMatchedRoute(?Route $matchedRoute): static
    {
        $new = clone $this;
        $new->matchedRoute = $matchedRoute;
        $new->input = null;

        return $new;
    }

    /**
     * Specify data which should be serialized to JSON
     * @link  https://php.net/manual/en/jsonserializable.jsonserialize.php
     * @return mixed data which can be serialized by <b>json_encode</b>,
     * which is a value of any type other than a resource.
     * @since 5.4
     */
    public function jsonSerialize()
    {
        $req = FormatRegistry::makeDumpable($this->getRequest());
        $req['stream'] = null;

        return array_merge(
            $req,
            [
                'systemUri' => $this->getSystemUri()
            ]
        );
    }
}
