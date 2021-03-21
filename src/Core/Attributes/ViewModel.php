<?php

/**
 * Part of starter project.
 *
 * @copyright  Copyright (C) 2020 __ORGANIZATION__.
 * @license    __LICENSE__
 */

declare(strict_types=1);

namespace Windwalker\Core\Attributes;

use Windwalker\Core\Asset\AssetService;
use Windwalker\Core\View\Event\BeforeRenderEvent;
use Windwalker\Core\View\View;
use Windwalker\Core\View\ViewModelInterface;
use Windwalker\DI\Attributes\AttributeHandler;
use Windwalker\DI\Attributes\ContainerAttributeInterface;
use Windwalker\DI\Container;

use Windwalker\Filesystem\Path;
use Windwalker\Utilities\Str;

use function Windwalker\arr;

/**
 * The ViewModel class.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ViewModel implements ContainerAttributeInterface
{
    public array $css;

    public array $js;

    /**
     * View constructor.
     *
     * @param  string|null   $name
     * @param  string|null   $layout
     * @param  array|string  $css
     * @param  array|string  $js
     * @param  array         $modules
     */
    public function __construct(
        protected ?string $name = null,
        public ?string $layout = null,
        array|string $css = [],
        array|string $js = [],
        public array $modules = []
    ) {
        //
        $this->css = (array) $css;
        $this->js = (array) $js;
    }

    public function __invoke(AttributeHandler $handler): callable
    {
        $container = $handler->getContainer();

        return function (...$args) use ($container, $handler) {
            $viewModel = $handler(...$args);

            return $this->registerEvents(
                $container->newInstance(
                    View::class,
                    compact('viewModel')
                )
                    ->setLayout($this->layout),
                $viewModel,
                $container
            );
        };
    }

    protected function registerEvents(View $view, ViewModelInterface $vm, Container $container): View
    {
        $view->on(
            BeforeRenderEvent::class,
            function (BeforeRenderEvent $event) use ($vm, $container) {
                $asset = $container->resolve(AssetService::class);
                $name = $this->getName() ?? $this->guessName($vm, $container);
                $vmName = Path::clean(strtolower(ltrim($name, '\\/')), '/');

                // foreach ($this->css as $name => $css) {
                //     $path = '@view/' . $css;
                //
                //     $asset->css($path);
                // }

                foreach ($this->js as $name => $js) {
                    $path = '@view/' . $vmName . '/' . $js;

                    $asset->js($path);
                }

                foreach ($this->modules as $name => $js) {
                    $path = '@view/' . $vmName . '/' . $js;

                    if (!str_ends_with($js, '/')) {
                        $asset->import($path);
                    }

                    if (!is_numeric($name)) {
                        $asset->importMap($name, $path);
                    }
                }
            }
        );

        return $view;
    }

    /**
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    public function guessName(ViewModelInterface $vm, Container $container): string
    {
        $root = $container->getParam('asset.namespace_root');

        $ref = new \ReflectionClass($vm);
        $ns = $ref->getNamespaceName();

        return Str::removeLeft($ns, $root);
    }
}
