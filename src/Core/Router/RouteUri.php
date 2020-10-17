<?php

/**
 * Part of starter project.
 *
 * @copyright  Copyright (C) 2020 __ORGANIZATION__.
 * @license    __LICENSE__
 */

declare(strict_types=1);

namespace Windwalker\Core\Router;

use Windwalker\Core\Router\Exception\RouteNotFoundException;
use Windwalker\Http\Uri;

/**
 * The RouteUri class.
 */
class RouteUri extends Uri implements NavConstantInterface
{
    /**
     * @var callable
     */
    protected $handler;

    protected Uri $uri;

    protected int $options = 0;

    /**
     * RouteUri constructor.
     *
     * @param  mixed      $uri
     * @param  Navigator  $navigator
     * @param  int        $options
     */
    public function __construct(mixed $uri, protected Navigator $navigator, int $options = 0)
    {
        $this->uri = new Uri();

        if ($uri instanceof \Closure) {
            $this->handler = $uri;
            $uri = '';
        }

        parent::__construct($uri);

        $this->options = $options;
    }

    /**
     * type
     *
     * @param  int  $options
     *
     * @return  $this
     */
    public function options(int $options): static
    {
        $new = clone $this;
        $new->options = $options;

        return $new;
    }

    /**
     * full
     *
     * @return  static
     */
    public function full(): static
    {
        return $this->options(Navigator::TYPE_FULL);
    }

    /**
     * path
     *
     * @return  static
     */
    public function path(): static
    {
        return $this->options(Navigator::TYPE_PATH);
    }

    /**
     * raw
     *
     * @return  static
     */
    public function raw(): static
    {
        return $this->options(Navigator::TYPE_RAW);
    }

    /**
     * id
     *
     * @param int $id
     *
     * @return  static
     */
    public function id(mixed $id): static
    {
        return $this->var('id', $id);
    }

    /**
     * alias
     *
     * @param string $alias
     *
     * @return  static
     */
    public function alias(string $alias): static
    {
        return $this->var('alias', $alias);
    }

    /**
     * page
     *
     * @param int $page
     *
     * @return  static
     */
    public function page(int $page): static
    {
        return $this->var('page', $page);
    }

    /**
     * layout
     *
     * @param string $layout
     *
     * @return  static
     *
     * @since  3.5.8
     */
    public function layout(string $layout): static
    {
        return $this->var('layout', $layout);
    }

    /**
     * task
     *
     * @param string $task
     *
     * @return  static
     *
     * @since  3.5.8
     */
    public function task(string $task): static
    {
        return $this->var('task', $task);
    }

    /**
     * addVar
     *
     * @param string $name
     * @param mixed  $value
     *
     * @return  $this
     */
    public function var(string $name, mixed $value): static
    {
        return $this->withVar($name, $value);
    }

    /**
     * delVar
     *
     * @param string $name
     *
     * @return  static
     *
     * @since  3.5.5
     */
    public function delVar(string $name): static
    {
        return $this->withoutVar($name);
    }

    /**
     * Method to get property Escape
     *
     * @return  bool
     */
    public function isEscape(): bool
    {
        return (bool) ($this->options & static::MODE_ESCAPE);
    }

    /**
     * escape
     *
     * @param bool $bool
     *
     * @return  $this
     */
    public function escape(bool $bool = true): static
    {
        return $this->options(
            $bool
                ? $this->getOptions() | static::MODE_ESCAPE
                : $this->getOptions() & ~static::MODE_ESCAPE
        );
    }

    /**
     * Method to get property Mute
     *
     * @return  bool
     */
    public function isMute(): bool
    {
        return (bool) ($this->options & static::MODE_MUTE);
    }

    /**
     * Method to set property mute
     *
     * @param   bool $mute
     *
     * @return  static  Return self to support chaining.
     */
    public function mute(bool $mute = true): static
    {
        return $this->options(
            $mute
                ? $this->getOptions() | static::MODE_MUTE
                : $this->getOptions() & ~static::MODE_MUTE
        );
    }

    protected function handleError(RouteNotFoundException $e): string
    {
        if (!($this->computedOptions() & static::MODE_MUTE)) {
            throw $e;
        }

        if ($this->computedOptions() & static::DEBUG_ALERT) {
            return "javascript: alert('{$e->getMessage()}');";
        }

        return '#';
    }

    public function computedOptions(): int
    {
        return $this->navigator->getOptions() | $this->getOptions();
    }

    /**
     * @return int
     */
    public function getOptions(): int
    {
        return $this->options;
    }

    /**
     * Magic method to get the string representation of the URI object.
     *
     * @return  string
     *
     * @since   2.0
     */
    public function __toString(): string
    {
        if ($this->handler instanceof \Closure) {
            try {
                $uri = new Uri(($this->handler)());
            } catch (RouteNotFoundException $e) {
                return $this->handleError($e);
            }

            if ($this->scheme !== null) {
                $uri->scheme = $this->scheme;
            }

            if ($this->host !== null) {
                $uri->host = $this->host;
            }

            if ($this->user !== null) {
                $uri->user = $this->user;
            }

            if ($this->pass !== null) {
                $uri->pass = $this->pass;
            }

            if ($this->port !== null) {
                $uri->port = $this->port;
            }

            if ($this->vars !== []) {
                foreach ($this->vars as $key => $var) {
                    $uri = $uri->withVar($key, $var);
                }
            }
        } else {
            $uri = new Uri(parent::__toString());
        }

        $uri = ltrim((string) $uri, '/');

        $options = $this->navigator->getOptions() | $this->options;

        if ($options & static::TYPE_RAW) {
            return $uri;
        }

        if ($options & static::TYPE_FULL) {
            return $this->navigator->absolute($uri, static::TYPE_FULL);
        }

        return $this->navigator->absolute($uri, static::TYPE_PATH);
    }
}
