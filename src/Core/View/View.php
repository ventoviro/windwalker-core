<?php

/**
 * Part of starter project.
 *
 * @copyright  Copyright (C) 2020 __ORGANIZATION__.
 * @license    __LICENSE__
 */

declare(strict_types=1);

namespace Windwalker\Core\View;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Windwalker\Attributes\AttributesAccessor;
use Windwalker\Core\Application\AppContext;
use Windwalker\Core\Http\ViewResponse;
use Windwalker\Core\Module\AbstractModule;
use Windwalker\Core\Module\ModuleInterface;
use Windwalker\Core\Renderer\RendererService;
use Windwalker\Core\Router\RouteUri;
use Windwalker\Core\State\AppState;
use Windwalker\Core\View\Event\AfterRenderEvent;
use Windwalker\Core\View\Event\BeforeRenderEvent;
use Windwalker\Event\EventAwareInterface;
use Windwalker\Event\EventAwareTrait;
use Windwalker\Http\Response\HtmlResponse;
use Windwalker\Http\Response\RedirectResponse;
use Windwalker\Renderer\CompositeRenderer;
use Windwalker\Renderer\RendererInterface;
use Windwalker\Stream\Stream;
use Windwalker\Utilities\Attributes\Prop;
use Windwalker\Utilities\Iterator\PriorityQueue;
use Windwalker\Utilities\Options\OptionsResolverTrait;

/**
 * The ViewModel class.
 */
class View implements EventAwareInterface
{
    use OptionsResolverTrait;
    use EventAwareTrait;

    protected string|array|null $layout = null;

    protected ?RendererInterface $renderer = null;

    /**
     * View constructor.
     *
     * @param  object           $viewModel
     * @param  AppContext       $app
     * @param  RendererService  $rendererService
     * @param  array            $options
     */
    public function __construct(
        protected object $viewModel,
        protected AppContext $app,
        protected RendererService $rendererService,
        array $options = []
    ) {
        $this->resolveOptions($options, [$this, 'configureOptions']);
    }

    protected function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->define('layout_var_name')
            ->default('layout')
            ->allowedTypes('string');

        $resolver->define('module')
            ->allowedTypes(ModuleInterface::class, 'null')
            ->default(null);
    }

    public function render(array $data = []): mixed
    {
        $vm = $this->getViewModel();

        if (!$vm instanceof ViewModelInterface && method_exists($vm, 'prepare')) {
            throw new \LogicException(
                sprintf(
                    '%s must implement %s or has prepare() method.',
                    $vm::class,
                    ViewModelInterface::class
                )
            );
        }

        $layout = $this->resolveLayout();

        $event = $this->emit(
            BeforeRenderEvent::class,
            [
                'view' => $this,
                'viewModel' => $vm,
                'data' => $data,
                'layout' => $layout,
                'state' => $this->getState()
            ]
        );

        $data = $event->getData();

        if ($data !== []) {
            $this->injectData($vm, $data);
        }

        $data = $vm->prepare($event->getState(), $this->app);

        $data = $this->handlerViewModelReturn($data, $response);

        if ($response instanceof RedirectResponse) {
            return $response;
        }

        $data = array_merge($this->rendererService->getGlobals(), $event->getData(), $data);

        $data['vm'] = $vm;
        
        $this->preparePaths($vm);
        
        $content = $this->getRenderer()
            ->render($layout = $event->getLayout(), $data, ['context' => $event->getViewModel()]);

        $event = $this->emit(
            AfterRenderEvent::class,
            [
                'view' => $this,
                'viewModel' => $vm,
                'data' => $data,
                'layout' => $layout,
                'content' => $content,
                'response' => $response
            ]
        );

        $response = $event->getResponse();

        if ($response instanceof ResponseInterface) {
            $response->getBody()->write($event->getContent());
            return $response;
        }

        return $event->getContent();
    }

    protected function handlerViewModelReturn(mixed $data, ?ResponseInterface &$response = null): array
    {
        if ($data instanceof ResponseInterface) {
            $response = $data;

            if ($data instanceof ViewResponse) {
                $data = $response->getData();
                $response = HtmlResponse::from($response);
                return $data;
            }

            if ($data instanceof RedirectResponse) {
                return [];
            }
        }

        if ($data instanceof RouteUri) {
            $response = $data->toResponse();
            return [];
        }

        if ($data instanceof UriInterface) {
            $response = new RedirectResponse($data);
            return [];
        }

        if (!is_array($data)) {
            throw new \UnexpectedValueException(
                sprintf(
                    'ViewModel return value not support for: %s',
                    get_debug_type($data)
                )
            );
        }

        return $data;
    }

    protected function getState(): AppState
    {
        return $this->getModule()?->getState() ?? $this->app->getState();
    }

    protected function getModule(): ?ModuleInterface
    {
        return $this->options['module'];
    }

    public function resolveLayout(): string
    {
        $vm = $this->getViewModel();

        $layouts = $this->app->config('di.layouts') ?? [];
        $layout = $layouts[$vm::class] ?? $this->getLayout();

        if (is_array($layout)) {
            $varName = $this->options['layout_var_name'];
            $layoutType = $this->app->input($varName) ?: 'default';

            $layout = $layout[$layoutType] ?? $layout['default'] ?? null;
        }

        if ($layout === null) {
            throw new \LogicException('View must provide at least 1 default layout name.');
        }

        return $layout;
    }

    protected function injectData(object $vm, array $data): void
    {
        $ref = new \ReflectionClass($vm);

        foreach ($ref->getProperties() as $property) {
            AttributesAccessor::runAttributeIfExists(
                $property,
                Prop::class,
                function ($attr) use ($data, $vm, $property) {
                    if (array_key_exists($property->getName(), $data)) {
                        $property->setValue($vm, $data[$property->getName()]);
                    }
                }
            );
        }
    }

    /**
     * @return string|array|null
     */
    public function getLayout(): string|array|null
    {
        return $this->layout;
    }

    /**
     * @param  string|array|null  $layout
     *
     * @return  static  Return self to support chaining.
     */
    public function setLayout(string|array|null $layout): static
    {
        $this->layout = $layout;

        return $this;
    }

    /**
     * @return object
     */
    public function getViewModel(): object
    {
        return $this->viewModel;
    }

    /**
     * @param  object  $viewModel
     *
     * @return  static  Return self to support chaining.
     */
    public function setViewModel(object $viewModel): static
    {
        $this->viewModel = $viewModel;

        return $this;
    }

    public function addPath(string $path, int $priority = 100): static
    {
        $renderer = $this->getRenderer();

        if ($renderer instanceof CompositeRenderer) {
            $renderer->addPath($path, $priority);
        }

        return $this;
    }

    /**
     * @return RendererInterface
     */
    public function getRenderer(): RendererInterface
    {
        return $this->renderer ??= $this->rendererService->createRenderer();
    }

    /**
     * @param  RendererInterface|null  $renderer
     *
     * @return  static  Return self to support chaining.
     */
    public function setRenderer(RendererInterface|null $renderer): static
    {
        $this->renderer = $renderer;

        return $this;
    }

    protected function preparePaths(object $vm): void
    {
        $ref = new \ReflectionClass($vm);
        $dir = dirname($ref->getFileName()) . '/views';

        if (is_dir($dir)) {
            $this->addPath($dir, PriorityQueue::HIGH);
        }
    }
}
