<?php

/**
 * Part of starter project.
 *
 * @copyright  Copyright (C) 2020 __ORGANIZATION__.
 * @license    __LICENSE__
 */

declare(strict_types=1);

namespace Windwalker\Core\Attributes;

use Psr\Http\Message\ResponseInterface;
use Windwalker\Core\Asset\AssetService;
use Windwalker\Core\Html\HtmlFrame;
use Windwalker\Core\Module\ModuleInterface;
use Windwalker\Core\View\Event\BeforeRenderEvent;
use Windwalker\Core\View\View;
use Windwalker\Core\View\ViewModelInterface;
use Windwalker\DI\Attributes\AttributeHandler;
use Windwalker\DI\Attributes\ContainerAttributeInterface;
use Windwalker\DI\Container;
use Windwalker\Filesystem\Path;
use Windwalker\Http\Response\HtmlResponse;
use Windwalker\Utilities\Str;

/**
 * The HtmlPage class.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ViewModel implements ContainerAttributeInterface
{
    public array $css;

    public array $js;

    protected ?ResponseInterface $response = null;

    /**
     * View constructor.
     *
     * @param  string|array|null  $layout
     * @param  array              $headers
     * @param  array|string       $css
     * @param  array|string       $js
     * @param  array              $modules
     * @param  array              $options
     */
    public function __construct(
        public string|array|null $layout = null,
        public array $headers = [],
        array|string $css = [],
        array|string $js = [],
        public array $modules = [],
        public array $options = []
    ) {
        $this->css = (array) $css;
        $this->js  = (array) $js;
    }

    public function __invoke(AttributeHandler $handler): callable
    {
        $container = $handler->getContainer();
        $container->share(static::class, $this);
        $container->share('vm.self', $this);

        return function (...$args) use ($container, $handler) {
            $options = [
                'vmAttr' => $this,
                'css' => $this->css,
                'js' => $this->js,
                'modules' => $this->modules,
                'headers' => $this->headers,
            ];

            $viewModel = $handler(...$args);

            $vm = $container->newInstance(
                View::class,
                compact('viewModel', 'options')
            )
                ->setLayoutMap($this->layout);

            $container->remove(static::class);
            $container->remove('vm.self');

            return $vm;
        };
    }

    /**
     * @return string|null
     */
    public function getModule(): ?string
    {
        return $this->module;
    }

    // public function guessName(ViewModelInterface $vm, Container $container): string
    // {
    //     $root = $container->getParam('asset.namespace_base');
    //
    //     $ref = new \ReflectionClass($vm);
    //     $ns  = $ref->getNamespaceName();
    //
    //     return Str::removeLeft($ns, $root);
    // }
    //
    // public function header(string $name, string|array $value): static
    // {
    //     // Init response
    //     $this->getResponse();
    //
    //     foreach ((array) $value as $v) {
    //         $this->response = $this->response->withAddedHeader($name, $v);
    //     }
    //
    //     return $this;
    // }
    //
    // public function getResponse(): ResponseInterface
    // {
    //     return $this->response ??= $this->createResponse();
    // }
    //
    // public function createResponse(): ResponseInterface
    // {
    //     $res = new HtmlResponse();
    //
    //     if ($this->headers !== []) {
    //         foreach ($this->headers as $header) {
    //             foreach ((array) $header as $value) {
    //                 $res = $res->withAddedHeader($header, $value);
    //             }
    //         }
    //     }
    //
    //     return $res;
    // }
}
