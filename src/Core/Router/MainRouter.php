<?php
/**
 * Part of Windwalker project.
 *
 * @copyright  Copyright (C) 2014 - 2016 LYRASOFT. All rights reserved.
 * @license    GNU Lesser General Public License version 3 or later.
 */

namespace Windwalker\Core\Router;

use Windwalker\Cache\Cache;
use Windwalker\Cache\Serializer\RawSerializer;
use Windwalker\Cache\Storage\ArrayStorage;
use Windwalker\Core\Package\AbstractPackage;
use Windwalker\Core\Package\PackageHelper;
use Windwalker\Core\Package\PackageResolver;
use Windwalker\Event\DispatcherAwareInterface;
use Windwalker\Event\DispatcherAwareTrait;
use Windwalker\Event\DispatcherInterface;
use Windwalker\Event\ListenerPriority;
use Windwalker\Filesystem\File;
use Windwalker\Router\Exception\RouteNotFoundException;
use Windwalker\Router\Matcher\MatcherInterface;
use Windwalker\Router\Route;
use Windwalker\Router\Router;
use Windwalker\Structure\Structure;
use Windwalker\Uri\UriData;
use Windwalker\Utilities\Arr;
use function Windwalker\tap;

/**
 * The Router class.
 *
 * @since  2.0
 */
class MainRouter extends Router implements RouteBuilderInterface, DispatcherAwareInterface, DispatcherInterface
{
    use DispatcherAwareTrait;
    use RouteBuilderTrait;

    /**
     * An array of HTTP Method => controller suffix pairs for routing the request.
     *
     * @var  array
     */
    protected $suffixMap = [
        'GET' => 'GetController',
        'POST' => 'SaveController',
        'PUT' => 'SaveController',
        'PATCH' => 'SaveController',
        'DELETE' => 'DeleteController',
        'HEAD' => 'HeadController',
        'OPTIONS' => 'OptionsController',
    ];

    /**
     * Property controller.
     *
     * @var  string
     */
    protected $controller = null;

    /**
     * Property uri.
     *
     * @var UriData
     */
    protected $uri;

    /**
     * Property cache.
     *
     * @var  Cache
     */
    protected $cache;

    /**
     * Property matched.
     *
     * @var  Route
     */
    protected $matched;

    /**
     * Property routeCreator.
     *
     * @var  RouteCreator
     */
    protected $routeCreator;

    /**
     * Property packageResolver.
     *
     * @var PackageResolver
     */
    protected $packageResolver;

    /**
     * Class init.
     *
     * @param MatcherInterface    $matcher
     * @param RouteCreator        $routeCreator
     * @param UriData             $uri
     * @param DispatcherInterface $dispatcher
     */
    public function __construct(
        MatcherInterface $matcher,
        RouteCreator $routeCreator,
        UriData $uri,
        DispatcherInterface $dispatcher
    ) {
        $this->cache = new Cache(new ArrayStorage(), new RawSerializer());

        $this->uri          = $uri;
        $this->dispatcher   = $dispatcher;
        $this->routeCreator = $routeCreator;

        parent::__construct([], $matcher);
    }

    /**
     * build
     *
     * @param string $route
     * @param array  $queries
     * @param array  $config
     *
     * @return  string
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function build($route, $queries = [], $config = [])
    {
        if (!$this->hasRoute($route)) {
            throw new \OutOfRangeException('Route: ' . $route . ' not found.');
        }

        if (is_string($config)) {
            $config = [
                'type' => $config
            ];
        }

        $type = $config['type'] ?? static::TYPE_PATH;

        $queries = is_scalar($queries) ? ['id' => $queries] : $queries;

        // Hook
        $extra = $this->routes[$route]->getExtraValues();

        if (isset($extra['hook']['build'])) {
            if (!is_callable($extra['hook']['build'])) {
                throw new \LogicException(sprintf(
                    'The build hook: "%s" of route: "%s" not found',
                    implode('::', (array) $extra['hook']['build']),
                    $route
                ));
            }

            $extra['hook']['build']($this, $route, $queries, $type, $config);
        }

        $this->triggerEvent('onRouterBeforeRouteBuild', [
            'route' => &$route,
            'queries' => &$queries,
            'type' => &$type,
            'config' => &$config,
            'router' => $this,
        ]);

        $key = $this->getCacheKey([$route, $queries, $type, $config]);

        if ($this->cache->exists($key)) {
            return $this->cache->get($key);
        }

        // Build
        $url = parent::build($route, $queries);

        $this->triggerEvent('onRouterAfterRouteBuild', [
            'url' => &$url,
            'route' => &$route,
            'queries' => &$queries,
            'type' => &$type,
            'config' => &$config,
            'router' => $this,
        ]);

        $uri = $this->getUri();

        $script = $uri->script;
        $script = $script ? $script . '/' : null;

        if ($type === static::TYPE_PATH) {
            $url = $uri->path . '/' . $script . ltrim($url, '/');
        } elseif ($type === static::TYPE_FULL) {
            $url = $uri->root . '/' . $script . $url;
        }

        return tap($url, function (string $url) use ($key) {
            $this->cache->set($key, $url);
        });
    }

    /**
     * match
     *
     * @param string $rawRoute
     * @param string $method
     * @param array  $options
     *
     * @return  Route
     *
     * @throws \Windwalker\Router\Exception\RouteNotFoundException
     * @throws \LogicException
     */
    public function match($rawRoute, $method = 'GET', $options = [])
    {
        $this->triggerEvent('onRouterBeforeRouteMatch', [
            'route' => &$rawRoute,
            'method' => &$method,
            'options' => &$options,
            'router' => $this,
        ]);

        $route = parent::match($rawRoute, $method, $options);

        $this->triggerEvent('onRouterAfterRouteMatch', [
            'route' => &$rawRoute,
            'matched' => $route,
            'method' => &$method,
            'options' => &$options,
            'router' => $this,
        ]);

        $extra = $route->getExtraValues();

        $controller = Arr::get($extra, 'controller');

        if ($controller === false) {
            throw new RouteNotFoundException('This route disabled.');
        }

        // Suffix
        $suffix = $this->fetchControllerSuffix($method, Arr::get($extra, 'action', []));

        if (!$controller && !$suffix) {
            throw new \LogicException(
                'Route profile should have "controller" or "action" element, the matched route: ' . $route->getName()
            );
        }

        if (!$controller instanceof \Closure) {
            if (!class_exists($controller) && !class_exists($suffix)) {
                $controller = trim($controller, '\\') . '\\' . $suffix;
            } elseif (class_exists($suffix)) {
                $controller = $suffix;
            }
        }

        if ($controller) {
            $extra['controller'] = $this->controller = $controller;
        }

        // Hooks
        if (isset($extra['hook']['match'])) {
            if (!is_callable($extra['hook']['match'])) {
                throw new \LogicException(sprintf(
                    'The match hook: "%s" of route: "%s" not found',
                    implode('::', (array) $extra['hook']['match']),
                    $route->getName()
                ));
            }

            $result = $extra['hook']['match']($this, $route, $method, $options);

            if ($result) {
                $route = $result;
            }
        }

        $route->setExtraValues($extra);

        $this->matched = $route;

        return $route;
    }

    /**
     * addBase
     *
     * @param string $uri
     * @param string $path
     *
     * @return  string
     */
    public function addBase($uri, $path = 'path')
    {
        if (strpos($uri, 'http') !== 0 && strpos($uri, '/') !== 0) {
            $uri = $this->uri->$path . $uri;
        } elseif (strpos($uri, '/') === 0 && strpos($uri, '//') !== 0) {
            if ($path === 'root') {
                $uri = $this->uri->host . $uri;
            }
        }

        return $uri;
    }

    /**
     * Get the controller class suffix string.
     *
     * @param string $method
     * @param array  $customSuffix
     *
     * @return  string
     *
     * @throws RouteNotFoundException
     * @since   2.0
     */
    public function fetchControllerSuffix($method = 'GET', $customSuffix = [])
    {
        $method = strtoupper($method);

        // Validate that we have a map to handle the given HTTP method.
        if (!isset($this->suffixMap[$method])) {
            // throw new \RuntimeException(sprintf('Unable to support the HTTP method `%s`.', $method), 404);
        }

        if (isset($customSuffix['*'])) {
            return $customSuffix['*'];
        }

        $customSuffix = array_change_key_case($customSuffix, CASE_UPPER);

        // Split GET|POST|PUT format
        foreach ($customSuffix as $key => $value) {
            $keyArray = explode('|', $key);

            if (count($keyArray) <= 1) {
                continue;
            }

            foreach ($keyArray as $splitedMethod) {
                $customSuffix[$splitedMethod] = $value;
            }

            unset($customSuffix[$key]);
        }

        $suffix = array_merge($this->suffixMap, $customSuffix);

        if (!isset($suffix[$method]) || $suffix[$method] === false) {
            throw new RouteNotFoundException(sprintf('Unable to support the HTTP method `%s`.', $method), 404);
        }

        return trim($suffix[$method], '\\');
    }

    /**
     * addRouteByConfig
     *
     * @param string                 $name
     * @param array                  $route
     * @param string|AbstractPackage $package
     * @param string                 $prefix
     *
     * @return Route
     */
    public function addRouteByConfig($name, $route, $package = null, $prefix = '/')
    {
        if ($package) {
            if (!$package instanceof AbstractPackage) {
                $package = $this->getPackageResolver()->getPackage($package);
            }

            $pattern = Arr::get($route, 'pattern');

            $route['pattern'] = rtrim($prefix, '/ ') . $pattern;

            $route['pattern'] = '/' . ltrim($route['pattern'], '/ ');

            $route['extra']['package'] = $package->getName();

            $name = $package->getName() . '@' . $name;
        }

        $pattern      = Arr::get($route, 'pattern');
        $variables    = Arr::get($route, 'variables', []);
        $allowMethods = Arr::get($route, 'method', []);

        if (isset($route['controller'])) {
            $route['extra']['controller'] = $route['controller'];
        }

        if (isset($route['action'])) {
            $route['extra']['action'] = $route['action'];
        }

        if (isset($route['hook'])) {
            $route['extra']['hook'] = $route['hook'];
        }

        $routeObject = new Route($name, $pattern, $variables, $allowMethods, $route);

        $this->addRoute($routeObject);

        return $routeObject;
    }

    /**
     * loadRoutingFromFiles
     *
     * @param array $files
     *
     * @return  array
     */
    public static function loadRoutingFiles(array $files)
    {
        $routing = new Structure();

        foreach ($files as $file) {
            if (!in_array(pathinfo($file, PATHINFO_EXTENSION), ['json', 'yml', 'yaml'])) {
                continue;
            }

            $routing = $routing->loadFile($file, pathinfo($file, PATHINFO_EXTENSION));
        }

        return $routing->toArray();
    }

    /**
     * loadRoutingFromFile
     *
     * @param string $file
     *
     * @return  array
     */
    public static function loadRoutingFile($file)
    {
        return static::loadRoutingFiles((array) $file);
    }

    /**
     * loadRawRouting
     *
     * @param array           $routes
     * @param PackageResolver $resolver
     *
     * @return  static
     */
    public function registerRawRouting(array $routes, PackageResolver $resolver)
    {
        foreach ($routes as $key => &$route) {
            // Package
            if (isset($route['package'])) {
                if (!isset($route['pattern'])) {
                    throw new \InvalidArgumentException(sprintf('Route need pattern: %s', print_r($route, true)));
                }

                if (!$resolver->exists($route['package'])) {
                    throw new \InvalidArgumentException(sprintf(
                        'Package %s not exists when register routing: %s.',
                        $route['package'],
                        $key
                    ));
                }

                $package = $resolver->getPackage($route['package']);

                $package->loadRouting($this, $route['pattern']);

                continue;
            }

            // Simple route
            $this->addRouteByConfig($key, $route);
        }

        return $this;
    }

    /**
     * addRouteByConfigs
     *
     * @param array                  $routes
     * @param string|AbstractPackage $package
     *
     * @return  static
     */
    public function addRouteByConfigs(array $routes, $package = null)
    {
        foreach ($routes as $key => $route) {
            $this->addRouteByConfig($key, $route, $package);
        }

        return $this;
    }

    /**
     * register
     *
     * @param string                      $path
     * @param array                       $data
     * @param string|AbstractPackage|null $package
     *
     * @return  $this
     *
     * @since  3.5
     */
    public function register(string $path, array $data = [], $package = null)
    {
        $packageResolver = $this->getPackageResolver();

        if ($package && !$package instanceof AbstractPackage) {
            $package = $packageResolver->getPackage($package);
        }

        // Workaround that RouteCreator should always be new one.
        $routerCreator = new RouteCreator($packageResolver);

        $routerCreator
            ->group('root')
            ->setOptions($data)
            ->register(function (RouteCreator $router) use ($path) {
                $router->load($path);
            });

        // TODO: Maybe move this handler to outside that support register() twice wothout side effect.
        foreach ($routerCreator->getRoutes() as $name => $route) {
            $route = $this->routeCreator->handleRoute($route);

            $this->addRouteByConfig(
                $route->getName(),
                $route->getOptions(),
                $package ?: $route->getOption('package')
            );
        }

        return $this;
    }

    /**
     * addRouteByFile
     *
     * @param string                 $file
     * @param string|AbstractPackage $package
     *
     * @return  MainRouter
     */
    public function addRouteFromFile($file, $package = null)
    {
        $routes = static::loadRoutingFile($file);

        if (File::getExtension($file) === 'php') {
            $this->register($file, [], $package);

            return $this;
        }

        return $this->addRouteByConfigs($routes, $package);
    }

    /**
     * addRouteByFile
     *
     * @param array                  $files
     * @param string|AbstractPackage $package
     *
     * @return  MainRouter
     */
    public function addRouteFromFiles(array $files, $package = null)
    {
        foreach ($files as $file) {
            $this->addRouteFromFile($file, $package);
        }

        return $this;
    }

    /**
     * Set a controller class suffix for a given HTTP method.
     *
     * @param string $method The HTTP method for which to set the class suffix.
     * @param string $suffix The class suffix to use when fetching the controller name for a given request.
     *
     * @return  static  Returns itself to support chaining.
     */
    public function setHttpMethodSuffix($method, $suffix)
    {
        $this->suffixMap[strtoupper((string) $method)] = (string) $suffix;

        return $this;
    }

    /**
     * Method to get property SuffixMap
     *
     * @return  array
     */
    public function getSuffixMap()
    {
        return $this->suffixMap;
    }

    /**
     * Method to set property suffixMap
     *
     * @param array $suffixMap
     *
     * @return  static  Return self to support chaining.
     */
    public function setSuffixMap($suffixMap)
    {
        $this->suffixMap = $suffixMap;

        return $this;
    }

    /**
     * Method to get property Controller
     *
     * @return  string
     */
    public function getController()
    {
        return $this->controller;
    }

    /**
     * Method to get property Uri
     *
     * @return  UriData
     */
    public function getUri()
    {
        if (!$this->uri) {
            throw new \LogicException('No uri object set to Router.');
        }

        return $this->uri;
    }

    /**
     * Method to set property uri
     *
     * @param UriData $uri
     *
     * @return  static  Return self to support chaining.
     */
    public function setUri(UriData $uri)
    {
        $this->uri = $uri;

        return $this;
    }

    /**
     * getCacheKey
     *
     * @param mixed $data
     *
     * @return  string
     */
    protected function getCacheKey($data)
    {
        ksort($data);

        return md5(json_encode($data));
    }

    /**
     * clearCache
     *
     * @return  static
     *
     * @since  3.5
     */
    public function clearCache()
    {
        $this->cache->clear();

        return $this;
    }

    /**
     * Method to get property Matched
     *
     * @return  Route
     */
    public function getMatched()
    {
        return $this->matched;
    }

    /**
     * Method to set property matched
     *
     * @param Route $matched
     *
     * @return  static  Return self to support chaining.
     */
    public function setMatched($matched)
    {
        $this->matched = $matched;

        return $this;
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
     * @return  static
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
     * on
     *
     * @param string   $event
     * @param callable $callable
     * @param int      $priority
     *
     * @return  static
     */
    public function listen($event, $callable, $priority = ListenerPriority::NORMAL)
    {
        $this->addListener($callable, [$event => $priority]);

        return $this;
    }

    /**
     * Method to get property PackageResolver
     *
     * @return  PackageResolver
     *
     * @since  3.5.2
     */
    public function getPackageResolver(): PackageResolver
    {
        return $this->packageResolver ?: PackageHelper::getInstance();
    }

    /**
     * Method to set property packageResolver
     *
     * @param PackageResolver $packageResolver
     *
     * @return  static  Return self to support chaining.
     *
     * @since  3.5.2
     */
    public function setPackageResolver(?PackageResolver $packageResolver)
    {
        $this->packageResolver = $packageResolver;

        return $this;
    }
}
