<?php

/**
 * Part of starter project.
 *
 * @copyright  Copyright (C) 2021 __ORGANIZATION__.
 * @license    __LICENSE__
 */

declare(strict_types=1);

namespace Windwalker\Core\View\Event;

use Psr\Http\Message\ResponseInterface;

/**
 * The AfterRenderEvent class.
 */
class AfterRenderEvent extends AbstractViewRenderEvent
{
    protected string $content;

    protected ?ResponseInterface $response = null;

    /**
     * @return string
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * @param  string  $content
     *
     * @return  static  Return self to support chaining.
     */
    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    /**
     * @return ResponseInterface|null
     */
    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }

    /**
     * @param  ResponseInterface|null  $response
     *
     * @return  static  Return self to support chaining.
     */
    public function setResponse(?ResponseInterface $response): static
    {
        $this->response = $response;

        return $this;
    }
}
