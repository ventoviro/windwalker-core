<?php
/**
 * Part of Windwalker project.
 *
 * @copyright  Copyright (C) 2014 - 2016 LYRASOFT. All rights reserved.
 * @license    GNU Lesser General Public License version 3 or later.
 */

namespace Windwalker\Core\Package;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Windwalker\Console\Command\Command;
use Windwalker\Console\Console;
use Windwalker\Core\Application\Middleware\AbstractWebMiddleware;
use Windwalker\Core\Application\ServiceAwareInterface;
use Windwalker\Core\Application\ServiceAwareTrait;
use Windwalker\Core\Application\WebApplication;
use Windwalker\Core\Console\CoreConsole;
use Windwalker\Core\Controller\AbstractController;
use Windwalker\Core\Controller\CallbackController;
use Windwalker\Core\Event\EventDispatcher;
use Windwalker\Core\Mvc\MvcResolver;
use Windwalker\Core\Provider\BootableDeferredProviderInterface;
use Windwalker\Core\Provider\BootableProviderInterface;
use Windwalker\Core\Router\MainRouter;
use Windwalker\Core\Router\PackageRouter;
use Windwalker\Core\Router\RouteCreator;
use Windwalker\Core\Router\RouteString;
use Windwalker\Core\Security\CsrfGuard;
use Windwalker\Core\View\AbstractView;
use Windwalker\DI\ClassMeta;
use Windwalker\DI\Container;
use Windwalker\DI\ContainerAwareTrait;
use Windwalker\DI\ServiceProviderInterface;
use Windwalker\Event\DispatcherAwareInterface;
use Windwalker\Event\DispatcherInterface;
use Windwalker\Event\EventInterface;
use Windwalker\Event\EventTriggerableInterface;
use Windwalker\Event\ListenerPriority;
use Windwalker\Filesystem\File;
use Windwalker\Http\Response\RedirectResponse;
use Windwalker\IO\Input;
use Windwalker\IO\PsrInput;
use Windwalker\Middleware\Chain\Psr7ChainBuilder;
use Windwalker\Middleware\Psr7Middleware;
use Windwalker\Router\Exception\RouteNotFoundException;
use Windwalker\Structure\Structure;
use Windwalker\Utilities\Arr;
use Windwalker\Utilities\Queue\PriorityQueue;
use Windwalker\Utilities\Reflection\ReflectionHelper;

/**
 * The AbstractPackage class.
 *
 * @property-read  Structure                  $config
 * @property-read  PackageRouter              $router
 * @property-read  PsrInput                   $input
 * @property-read  WebApplication|CoreConsole $app
 * @property-read  string                     $name
 * @property-read  Container                  $container
 * @property-read  CsrfGuard                  $csrf
 * @property-read  EventDispatcher            $dispatcher
 *
 * @since  2.0
 */
class AbstractPackage implements DispatcherAwareInterface,
    DispatcherInterface,
    ServiceAwareInterface
{
    use ContainerAwareTrait;
    use ServiceAwareTrait;

    /**
     * Bundle name.
     *
     * @var  string
     */
    protected $name;

    /**
     * Property enabled.
     *
     * @var  boolean
     */
    protected $isEnabled = true;

    /**
     * Property currentController.
     *
     * @var  AbstractController|string
     */
    protected $currentController;

    /**
     * Property isHmvc.
     *
     * @var  bool
     */
    protected $isHmvc = false;

    /**
     * Property task.
     *
     * @var  string
     */
    protected $task;

    /**
     * Property config.
     *
     * @var  Structure
     */
    protected $config;

    /**
     * Property middlewares.
     *
     * @var  PriorityQueue
     */
    protected $middlewares;

    /**
     * Property booted.
     *
     * @var  boolean
     */
    protected $booted = false;

    /**
     * initialise
     *
     * @return  void
     * @throws \ReflectionException
     * @throws \Windwalker\DI\Exception\DependencyResolutionException
     */
    public function boot()
    {
        if ($this->booted) {
            return;
        }

        if (!$this->name) {
            throw new \LogicException('Package: ' . get_class($this) . ' name property should not be empty.');
        }

        $this->getConfig();

        $this->registerProviders($this->getContainer());

        $this->registerListeners($this->getDispatcher());

        $this->registerMiddlewares();

        $this->booted = true;
    }

    /**
     * getController
     *
     * @param string      $task
     * @param array|Input $input
     * @param bool        $forceNew
     *
     * @return AbstractController
     * @throws \ReflectionException
     */
    public function getController($task, $input = null, $forceNew = false)
    {
        $resolver = $this->getMvcResolver()->getControllerResolver();

        $container = $this->getContainer();

        if ($input !== null && !$input instanceof Input) {
            $input = new Input($input);
        }

        $input = $input ?: $container->get('input');

        if ($task instanceof \Closure) {
            $controller = new CallbackController($task);

            return $controller->setApplication($this->app)
                ->setInput($input)
                ->setPackage($this)
                ->setContainer($container);
        }

        $key = $resolver::getDIKey($task);

        if (!$container->exists($key) || $forceNew) {
            try {
                /** @var AbstractController $controller */
                $controller = $resolver->create($task, $input, $this, $container);
                $controller->setApplication($this->app)
                    ->setInput($input)
                    ->setPackage($this)
                    ->setContainer($container);
            } catch (\DomainException $e) {
                throw new RouteNotFoundException($e->getMessage(), 404, $e);
            }

            $container->share($key, $controller);
        }

        return $container->get($key);
    }

    /**
     * execute
     *
     * @param string|AbstractController $controller
     * @param Request                   $request
     * @param Response                  $response
     * @param bool                      $hmvc
     *
     * @return Response
     * @throws \ReflectionException
     * @throws \Windwalker\DI\Exception\DependencyResolutionException
     * @throws \Throwable
     */
    public function execute($controller, Request $request, Response $response, $hmvc = false)
    {
        $this->currentController = $controller;
        $this->isHmvc = $hmvc;

        $this->getDispatcher()->triggerEvent('onPackagePreprocess', [
            'package' => $this,
            'controller' => &$controller,
            'task' => $controller,
        ]);

        if ($hmvc) {
            return $this->dispatch($request, $response);
        }

        $chain = $this->getMiddlewareChain()->setEndMiddleware([$this, 'dispatch']);

        return $chain->execute($request, $response);
    }

    /**
     * dispatch
     *
     * @param Request  $request
     * @param Response $response
     * @param callable $next
     *
     * @return Response
     * @throws \Exception
     * @throws \Throwable
     */
    public function dispatch(Request $request, Response $response, $next = null)
    {
        if (!$this->currentController instanceof AbstractController) {
            $this->currentController = $this->getController($this->currentController);
        }

        if ($this->isHmvc) {
            $this->currentController->isHmvc(true);
        }

        $controller = $this->currentController;

        $controller->setRequest($request)->setResponse($response);

        $this->prepareExecute();

        // @event: onPackageBeforeExecute
        $this->getDispatcher()->triggerEvent('onPackageBeforeExecute', [
            'package' => $this,
            'controller' => &$controller,
            'task' => $controller,
        ]);

        $result = $controller->execute();

        $result = $this->postExecute($result);

        // @event: onPackageAfterExecute
        $this->getDispatcher()->triggerEvent('onPackageAfterExecute', [
            'package' => $this,
            'controller' => $controller,
            'result' => &$result
        ]);

        $response = $controller->getResponse();

        if ($result !== null) {
            if ($result instanceof RouteString) {
                return new RedirectResponse(
                    (string) $result,
                    $response->getStatusCode(),
                    $response->getHeaders()
                );
            }

            // Render view if return value is a view object,
            // don't use (string) keyword to make sure we can get Exception when error occurred.
            // @see  https://bugs.php.net/bug.php?id=53648
            if ($result instanceof AbstractView) {
                $result = $result->render();
            } elseif (is_stringable($result)) {
                $result = (string) $result;
            } elseif (is_array($result) || is_object($result)) {
                $result = json_encode($result);
            }

            $response->getBody()->write((string) $result);
        }

        return $response;
    }

    /**
     * run
     *
     * @param string|AbstractController $task
     * @param array|Input               $input
     *
     * @return  Response
     * @throws \ReflectionException
     * @throws \Windwalker\DI\Exception\DependencyResolutionException
     */
    public function executeTask($task, $input = null)
    {
        return $this->execute(
            $this->getController($task, $input),
            $this->app->request,
            new \Windwalker\Http\Response\Response()
        );
    }

    /**
     * prepareExecute
     *
     * @return  void
     */
    protected function prepareExecute()
    {
    }

    /**
     * postExecute
     *
     * @param   mixed $result
     *
     * @return  mixed
     */
    protected function postExecute($result = null)
    {
        return $result;
    }

    /**
     * Register providers.
     *
     * @param Container $container
     *
     * @return  void
     * @throws \ReflectionException
     * @throws \Windwalker\DI\Exception\DependencyResolutionException
     */
    public function registerProviders(Container $container)
    {
        $sysProvider = new PackageProvider($this);
        $container->registerServiceProvider($sysProvider);

        $sysProvider->boot();

        $providers = (array) $this->get('providers');

        foreach ($providers as $interface => &$provider) {
            if ($provider === false) {
                continue;
            }

            if (is_subclass_of($provider, ServiceProviderInterface::class)) {
                // Handle provider
                if ($provider instanceof ClassMeta || (is_string($provider) && class_exists($provider))) {
                    $provider = $container->newInstance($provider);
                }

                if ($provider === false) {
                    continue;
                }

                $container->registerServiceProvider($provider);

                if ($provider instanceof BootableProviderInterface || method_exists($provider, 'boot')) {
                    $provider->boot($container);
                }
            } else {
                // Handle Service
                if (is_numeric($interface)) {
                    $container->prepareSharedObject($provider);
                } else {
                    $container->bindShared($interface, $provider);
                }
            }
        }

        foreach ($providers as $provider) {
            if ($provider === false) {
                continue;
            }

            if (is_subclass_of($provider, ServiceProviderInterface::class) && (
                $provider instanceof BootableDeferredProviderInterface
                || method_exists($provider, 'bootDeferred')
            )) {
                $provider->bootDeferred($container);
            }
        }
    }

    /**
     * registerListeners
     *
     * @param DispatcherInterface $dispatcher
     *
     * @return  void
     * @throws \ReflectionException
     * @throws \Windwalker\DI\Exception\DependencyResolutionException
     */
    public function registerListeners(DispatcherInterface $dispatcher)
    {
        $listeners = (array) $this->get('listeners', []);

        $defaultOptions = [
            'class' => '',
            'priority' => ListenerPriority::NORMAL,
            'enabled' => true
        ];

        foreach ($listeners as $name => $listener) {
            if ($listener instanceof ClassMeta || is_string($listener) || is_callable($listener)) {
                $listener = ['class' => $listener];
            }

            $listener = array_merge($defaultOptions, (array) $listener);

            if (!$listener['enabled']) {
                continue;
            }

            if (!is_numeric($name) && is_callable($listener['class'])) {
                $dispatcher->listen($name, $listener['class']);
            } elseif (class_exists($listener['class'])) {
                $dispatcher->addListener($this->container->newInstance($listener['class']), $listener['priority']);
            }
        }
    }

    /**
     * Register commands to console.
     *
     * @param Console $console Windwalker console object.
     *
     * @return  void
     */
    public function registerCommands(Console $console)
    {
        $commands = (array) $this->get('console.commands');

        foreach ($commands as $class) {
            if (class_exists($class) && is_subclass_of($class, Command::class)) {
                $console->addCommand($this->container->createObject($class));
            }
        }
    }

    /**
     * registerMiddlewares
     */
    protected function registerMiddlewares()
    {
        // init middlewares
        $middlewares = (array) $this->get('middlewares', []);

        foreach ($middlewares as $k => $middleware) {
            $this->addMiddleware($middleware, is_numeric($k) ? $k : PriorityQueue::NORMAL);
        }

        // Remove closures
        $this->set('middlewares', null);
    }

    /**
     * addMiddleware
     *
     * @param callable $middleware
     * @param int      $priority
     *
     * @return static
     */
    public function addMiddleware($middleware, $priority = PriorityQueue::NORMAL)
    {
        if (is_array($middleware) && isset($middleware['priority'])) {
            $priority = $middleware['priority'];
        }

        $this->getMiddlewares()->insert($middleware, $priority);

        return $this;
    }

    /**
     * getMiddlewareChain
     *
     * @return  Psr7ChainBuilder
     * @throws \ReflectionException
     * @throws \Windwalker\DI\Exception\DependencyResolutionException
     */
    public function getMiddlewareChain()
    {
        $middlewares = array_reverse(iterator_to_array(clone $this->getMiddlewares()));

        $chain = new Psr7ChainBuilder();

        foreach ($middlewares as $middleware) {
            $data = [];

            if (is_array($middleware)) {
                $data = $middleware;
                $middleware = $data['middleware'];

                unset($data['middleware']);
            }

            if ($middleware instanceof ClassMeta
                || (is_string($middleware) && is_subclass_of($middleware, AbstractWebMiddleware::class))) {
                $middleware = new Psr7Middleware($this->container->newInstance($middleware, $data));
            } elseif ($middleware instanceof \Closure) {
                $middleware->bindTo($this);
            } elseif ($middleware === false) {
                continue;
            }

            $chain->add($middleware);
        }

        return $chain;
    }

    /**
     * Method to get property Middlewares
     *
     * @return  PriorityQueue
     */
    public function getMiddlewares()
    {
        if (!$this->middlewares) {
            $this->middlewares = new PriorityQueue();
        }

        return $this->middlewares;
    }

    /**
     * Method to set property middlewares
     *
     * @param   PriorityQueue $middlewares
     *
     * @return  static  Return self to support chaining.
     */
    public function setMiddlewares(PriorityQueue $middlewares)
    {
        $this->middlewares = $middlewares;

        return $this;
    }

    /**
     * loadConfiguration
     *
     * @param   Structure $config
     *
     * @return  static
     * @throws \ReflectionException
     */
    public function loadConfig(Structure $config)
    {
        $file = $this->getDir() . '/Resources/config/config.dist.php';

        if (is_file($file)) {
            $config->loadFile($file, 'php', ['load_raw' => true]);
        }

        // Override
        $file = $this->getContainer()->get('config')->get('path.etc') . '/package/' . $this->name . '.php';

        if (is_file($file)) {
            $config->loadFile($file, 'php', ['load_raw' => true]);
        }

        return $this;
    }

    /**
     * loadRouting
     *
     * @param MainRouter $router
     * @param string     $group
     *
     * @return MainRouter
     */
    public function loadRouting(MainRouter $router, $group = null)
    {
        $routing = (array) $this->get('routing.files');

        $router->group($group, function (MainRouter $router) use ($routing) {
            $router->addRouteFromFiles($routing, $this);
        });

        return $router;
    }

    /**
     * registerRoutes
     *
     * @param RouteCreator $router
     * @param string       $prefix
     *
     * @return  RouteCreator
     *
     * @since  3.5
     * @throws \ReflectionException
     */
    public function registerRoutes(RouteCreator $router, string $prefix): RouteCreator
    {
        $files = (array) $this->get('routing.files');

        $files['main'] = static::dir() . '/routing.php';

        $router->group($this->getName())
            ->package($this->getName())
            ->prefix($prefix)
            ->register(function (RouteCreator $router) use ($files) {
                foreach ($files as $file) {
                    if (File::getExtension($file) === 'php') {
                        $router->load($file);
                    } else {
                        $this->router->getRouter()
                            ->group($this->getName(), function (MainRouter $router) use ($file) {
                                $router->addRouteFromFile($file, $this);
                            });
                    }
                }
            });

        return $router;
    }

    /**
     * getMvcResolver
     *
     * @return  MvcResolver
     */
    public function getMvcResolver()
    {
        return $this->getContainer()->get('mvc.resolver');
    }

    /**
     * Get bundle name.
     *
     * @return  string  Bundle ame.
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Method to set property name
     *
     * @param   string $name
     *
     * @return  static  Return self to support chaining.
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * get
     *
     * @param string $name
     * @param mixed  $default
     *
     * @return  mixed
     */
    public function get($name, $default = null)
    {
        return $this->config->get($name, $default);
    }

    /**
     * set
     *
     * @param string $name
     * @param mixed  $value
     *
     * @return  static
     */
    public function set($name, $value)
    {
        $this->config->set($name, $value);

        return $this;
    }

    /**
     * getFile
     *
     * @return  string
     * @throws \ReflectionException
     */
    public function getFile()
    {
        $ref = new \ReflectionClass(static::class);

        return $ref->getFileName();
    }

    /**
     * getDir
     *
     * @return  string
     * @throws \ReflectionException
     */
    public function getDir()
    {
        return dirname($this->getFile());
    }

    /**
     * dir
     *
     * @return  string
     *
     * @throws \ReflectionException
     *
     * @since  3.5
     */
    public static function dir(): string
    {
        return dirname(static::file());
    }

    /**
     * file
     *
     * @return  string
     *
     * @throws \ReflectionException
     *
     * @since  3.5
     */
    public static function file(): string
    {
        return (new \ReflectionClass(static::class))->getFileName();
    }

    /**
     * getNamespace
     *
     * @return  string
     *
     * @since  3.1
     */
    public function getNamespace()
    {
        return ReflectionHelper::getNamespaceName($this);
    }

    /**
     * enable
     *
     * @return  static
     */
    public function enable()
    {
        $this->isEnabled = true;

        return $this;
    }

    /**
     * disable
     *
     * @return  static
     */
    public function disable()
    {
        $this->isEnabled = false;

        return $this;
    }

    /**
     * isEnabled
     *
     * @return  bool
     */
    public function isEnabled()
    {
        return $this->isEnabled;
    }

    /**
     * Method to get property Task
     *
     * @return  string
     *
     * @since   2.1
     */
    public function getTask()
    {
        return $this->task;
    }

    /**
     * Method to set property task
     *
     * @param   string $task
     *
     * @return  static  Return self to support chaining.
     *
     * @since   2.1
     */
    public function setTask($task)
    {
        $this->task = $task;

        return $this;
    }

    /**
     * getDispatcher
     *
     * @return  DispatcherInterface
     */
    public function getDispatcher()
    {
        return $this->getContainer()->get('dispatcher');
    }

    /**
     * setDispatcher
     *
     * @param   DispatcherInterface $dispatcher
     *
     * @return  static  Return self to support chaining.
     */
    public function setDispatcher(DispatcherInterface $dispatcher)
    {
        $this->getContainer()->set('dispatcher', $dispatcher);

        return $this;
    }

    /**
     * Trigger an event.
     *
     * @param EventInterface|string $event The event object or name.
     * @param array                 $args  The arguments to set in event.
     *
     * @return  EventInterface  The event after being passed through all listeners.
     *
     * @since   2.0
     */
    public function triggerEvent($event, $args = [])
    {
        return $this->getDispatcher()->triggerEvent($event, $args);
    }

    /**
     * Add a listener to this dispatcher, only if not already registered to these events.
     * If no events are specified, it will be registered to all events matching it's methods name.
     * In the case of a closure, you must specify at least one event name.
     *
     * @param object|\Closure $listener       The listener
     * @param array|integer   $priorities     An associative array of event names as keys
     *                                        and the corresponding listener priority as values.
     *
     * @return  static  This method is chainable.
     *
     * @throws  \InvalidArgumentException
     *
     * @since   2.0
     */
    public function addListener($listener, $priorities = [])
    {
        $this->getDispatcher()->addListener($listener, $priorities);

        return $this;
    }

    /**
     * Add single listener.
     *
     * @param string   $event
     * @param callable $callable
     * @param int      $priority
     *
     * @return  static
     *
     * @since   3.0
     */
    public function listen($event, $callable, $priority = ListenerPriority::NORMAL)
    {
        $this->getDispatcher()->listen($event, $callable, $priority);

        return $this;
    }

    /**
     * Method to get property Config
     *
     * @return  Structure
     *
     * @since   2.1
     * @throws \ReflectionException
     */
    public function getConfig()
    {
        if (!$this->config) {
            $this->config = new Structure();

            $this->loadConfig($this->config);
        }

        return $this->config;
    }

    /**
     * Method to set property config
     *
     * @param   Structure $config
     *
     * @return  static  Return self to support chaining.
     *
     * @since   2.1
     */
    public function setConfig($config)
    {
        $this->config = $config;

        return $this;
    }

    /**
     * Method to get property CurrentController
     *
     * @return  AbstractController
     */
    public function getCurrentController()
    {
        return $this->currentController;
    }

    /**
     * __get
     *
     * @param string $name
     *
     * @return  mixed
     * @throws \ReflectionException
     */
    public function __get($name)
    {
        $diMapping = [
            'app' => 'application',
            'input' => 'input',
            'dispatcher' => 'dispatcher',
            'csrf' => 'security.csrf',
            'router' => 'router'
        ];

        if (isset($diMapping[$name])) {
            return $this->getContainer()->get($diMapping[$name]);
        }

        if ($name === 'container') {
            return $this->getContainer();
        }

        if ($name === 'config') {
            return $this->getConfig();
        }

        if ($name === 'name') {
            return $this->getName();
        }

        return null;
    }
}
