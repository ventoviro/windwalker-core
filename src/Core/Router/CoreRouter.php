<?php
/**
 * Part of Windwalker project.
 *
 * @copyright  Copyright (C) 2014 - 2016 LYRASOFT. All rights reserved.
 * @license    GNU Lesser General Public License version 3 or later.
 */

namespace Windwalker\Core\Router;

use Windwalker\Cache\Cache;
use Windwalker\Cache\DataHandler\RawDataHandler;
use Windwalker\Cache\Storage\RuntimeStorage;
use Windwalker\Core\Package\AbstractPackage;
use Windwalker\Core\Package\PackageHelper;
use Windwalker\Core\Package\PackageResolver;
use Windwalker\Event\DispatcherAwareInterface;
use Windwalker\Event\DispatcherAwareTrait;
use Windwalker\Event\DispatcherInterface;
use Windwalker\Event\ListenerPriority;
use Windwalker\Registry\Registry;
use Windwalker\Registry\RegistryHelper;
use Windwalker\Router\Exception\RouteNotFoundException;
use Windwalker\Router\Matcher\MatcherInterface;
use Windwalker\Router\Route;
use Windwalker\Router\Router;
use Windwalker\Uri\UriData;
use Windwalker\Utilities\ArrayHelper;

/**
 * The Router class.
 *
 * @since  2.0
 */
class CoreRouter extends Router implements DispatcherAwareInterface, DispatcherInterface
{
	use DispatcherAwareTrait;
	
	const TYPE_RAW = 'raw';
	const TYPE_PATH = 'path';
	const TYPE_FULL = 'full';

	/**
	 * An array of HTTP Method => controller suffix pairs for routing the request.
	 *
	 * @var  array
	 */
	protected $suffixMap = array(
		'GET'     => 'GetController',
		'POST'    => 'SaveController',
		'PUT'     => 'SaveController',
		'PATCH'   => 'SaveController',
		'DELETE'  => 'DeleteController',
		'HEAD'    => 'HeadController',
		'OPTIONS' => 'OptionsController'
	);

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
	 * Class init.
	 *
	 * @param array            $routes
	 * @param MatcherInterface $matcher
	 */
	public function __construct(array $routes, MatcherInterface $matcher)
	{
		parent::__construct($routes, $matcher);

		$this->cache = new Cache(new RuntimeStorage, new RawDataHandler);
	}

	/**
	 * build
	 *
	 * @param string $route
	 * @param array  $queries
	 * @param string $type
	 * @param bool   $xhtml
	 *
	 * @return  string
	 */
	public function build($route, $queries = array(), $type = CoreRouter::TYPE_RAW, $xhtml = false)
	{
		$route = str_replace(':', '@', $route);

		if (!array_key_exists($route, $this->routes))
		{
			throw new \OutOfRangeException('Route: ' . $route . ' not found.');
		}

		// Hook
		$extra = $this->routes[$route]->getExtra();

		if (isset($extra['hook']['build']))
		{
			if (!is_callable($extra['hook']['build']))
			{
				throw new \LogicException(sprintf('The build hook: "%s" of route: "%s" not found', implode('::', (array) $extra['hook']['build']), $route));
			}

			call_user_func($extra['hook']['build'], $this, $route, $queries, $type, $xhtml);
		}

		$this->triggerEvent('onRouterBeforeRouteBuild', array(
			'route'   => &$route,
			'queries' => &$queries,
			'type'    => &$type,
			'xhtml'   => &$xhtml,
			'router'  => $this
		));

		$key = $this->getCacheKey(array($route, $queries, $type, $xhtml));

		if ($this->cache->exists($key))
		{
			return $this->cache->get($key);
		}

		// Build
		$url = parent::build($route, $queries);

		$this->triggerEvent('onRouterAfterRouteBuild', array(
			'url'     => &$url,
			'route'   => &$route,
			'queries' => &$queries,
			'type'    => &$type,
			'xhtml'   => &$xhtml,
			'router'  => $this
		));

		$uri = $this->getUri();

		$script = $uri->script;
		$script = $script ? $script . '/' : null;

		if ($type == static::TYPE_PATH)
		{
			$url = $uri->root . $script . ltrim($url, '/');
		}
		elseif ($type == static::TYPE_FULL)
		{
			$url = $uri->path . $script . $url;
		}

		if ($xhtml)
		{
			$url = htmlspecialchars($url);
		}

		$this->cache->set($key, $url);

		return $url;
	}

	/**
	 * buildHtml
	 *
	 * @param string  $route
	 * @param array   $queries
	 * @param string  $type
	 *
	 * @return  string
	 */
	public function html($route, $queries = array(), $type = CoreRouter::TYPE_PATH)
	{
		return $this->build($route, $queries, $type, true);
	}

	/**
	 * buildHttp
	 *
	 * @param string  $route
	 * @param array   $queries
	 * @param string  $type
	 *
	 * @return  string
	 */
	public function http($route, $queries = array(), $type = CoreRouter::TYPE_PATH)
	{
		return $this->build($route, $queries, $type, false);
	}

	/**
	 * buildHtml
	 *
	 * @param string  $route
	 * @param array   $queries
	 * @param string  $type
	 *
	 * @return  string
	 */
	public function buildHtml($route, $queries = array(), $type = CoreRouter::TYPE_PATH)
	{
		return $this->html($route, $queries, $type);
	}

	/**
	 * buildHttp
	 *
	 * @param string  $route
	 * @param array   $queries
	 * @param string  $type
	 *
	 * @return  string
	 */
	public function buildHttp($route, $queries = array(), $type = CoreRouter::TYPE_PATH)
	{
		return $this->http($route, $queries, $type);
	}

	/**
	 * match
	 *
	 * @param string $rawRoute
	 * @param string $method
	 * @param array  $options
	 *
	 * @throws  \UnexpectedValueException
	 * @return  Route
	 */
	public function match($rawRoute, $method = 'GET', $options = array())
	{
		$this->triggerEvent('onRouterBeforeRouteMatch', array(
			'route'   => &$rawRoute,
			'method'  => &$method,
			'options' => &$options,
			'router'  => $this
		));

		$route = parent::match($rawRoute, $method, $options);

		$this->triggerEvent('onRouterAfterRouteMatch', array(
			'route'   => &$rawRoute,
			'matched' => $route,
			'method'  => &$method,
			'options' => &$options,
			'router'  => $this
		));

		$extra = $route->getExtra();

		$controller = ArrayHelper::getValue($extra, 'controller');

		if (!$controller)
		{
			throw new \UnexpectedValueException('Route profile should have "controller" element, the matched route: ' . $route->getName());
		}

		// Suffix
		$suffix = $this->fetchControllerSuffix($method, ArrayHelper::getValue($extra, 'action', array()));

		if ($suffix[0] != ':')
		{
			$suffix = '\\' . $suffix;
		}

		$controller = trim($controller, '\\') . $suffix;

		$extra['controller'] = $this->controller = $controller;

		$route->setExtra($extra);

		// Hooks
		if (isset($extra['hook']['match']))
		{
			if (!is_callable($extra['hook']['match']))
			{
				throw new \LogicException(sprintf('The match hook: "%s" of route: "%s" not found', implode('::', (array) $extra['hook']['match']), $route->getName()));
			}

			call_user_func($extra['hook']['match'], $this, $route, $method, $options);
		}

		$this->matched = $route;

		return $route;
	}

	/**
	 * Get the controller class suffix string.
	 *
	 * @param string $method
	 * @param array  $customSuffix
	 *
	 * @throws \RuntimeException
	 * @return  string
	 *
	 * @since   2.0
	 */
	public function fetchControllerSuffix($method = 'GET', $customSuffix = array())
	{
		$method = strtoupper($method);

		// Validate that we have a map to handle the given HTTP method.
		if (!isset($this->suffixMap[$method]))
		{
			// throw new \RuntimeException(sprintf('Unable to support the HTTP method `%s`.', $method), 404);
		}

		if (isset($customSuffix['*']))
		{
			return $customSuffix['*'];
		}

		$customSuffix = array_change_key_case($customSuffix, CASE_UPPER);

		// Split GET|POST|PUT format
		foreach ($customSuffix as $key => $value)
		{
			$keyArray = explode('|', $key);

			if (count($keyArray) <= 1)
			{
				continue;
			}

			foreach ($keyArray as $splitedMethod)
			{
				$customSuffix[$splitedMethod] = $value;
			}

			unset($customSuffix[$key]);
		}

		$suffix = array_merge($this->suffixMap, $customSuffix);

		if (!isset($suffix[$method]))
		{
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
		if ($package)
		{
			if (!$package instanceof AbstractPackage)
			{
				$package = PackageHelper::getPackage($package);
			}

			$pattern = ArrayHelper::getValue($route, 'pattern');

			$route['pattern'] = rtrim($prefix, '/ ') . $pattern;

			$route['pattern'] = '/' . ltrim($route['pattern'], '/ ');

			$route['extra']['package'] = $package->getName();

			$name = $package->getName() . '@' . $name;
		}

		$pattern = ArrayHelper::getValue($route, 'pattern');
		$variables = ArrayHelper::getValue($route, 'variables', array());
		$allowMethods = ArrayHelper::getValue($route, 'method', array());

		if (isset($route['controller']))
		{
			$route['extra']['controller'] = $route['controller'];
		}

		if (isset($route['action']))
		{
			$route['extra']['action'] = $route['action'];
		}

		if (isset($route['hook']))
		{
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
	public static function loadRoutingFromFiles(array $files)
	{
		$routing = new Registry;

		foreach ($files as $file)
		{
			$routing = $routing->loadFile($file, pathinfo($file, PATHINFO_EXTENSION));
		}

		return $routing->toArray();
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
		foreach ($routes as $key => &$route)
		{
			// Package
			if (isset($route['package']))
			{
				if (!isset($route['pattern']))
				{
					throw new \InvalidArgumentException(sprintf('Route need pattern: %s', print_r($route, true)));
				}

				if (!$resolver->exists($route['package']))
				{
					throw new \InvalidArgumentException(sprintf('Package %s not exists.', $route['package']));
				}

				$package = $resolver->getPackage($route['package']);

				$this->group($route['pattern'], function (CoreRouter $router) use ($package)
				{
					$router->addRouteByConfigs($package->loadRouting(), $package->getName());
				});

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
	public function addRouteByConfigs($routes, $package = null)
	{
		foreach ($routes as $key => $route)
		{
			$this->addRouteByConfig($key, $route, $package);
		}

		return $this;
	}

	/**
	 * Set a controller class suffix for a given HTTP method.
	 *
	 * @param   string  $method  The HTTP method for which to set the class suffix.
	 * @param   string  $suffix  The class suffix to use when fetching the controller name for a given request.
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
	 * @param   array $suffixMap
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
		if (!$this->uri)
		{
			throw new \LogicException('No uri object set to Router.');
		}

		return $this->uri;
	}

	/**
	 * Method to set property uri
	 *
	 * @param   UriData $uri
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
	 * @param   mixed  $data
	 *
	 * @return  string
	 */
	protected function getCacheKey($data)
	{
		ksort($data);

		return md5(json_encode($data));
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
	 * @param   Route $matched
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
	 * @param   object|\Closure $listener     The listener
	 * @param   array|integer   $priorities   An associative array of event names as keys
	 *                                        and the corresponding listener priority as values.
	 *
	 * @return  static
	 *
	 * @throws  \InvalidArgumentException
	 *
	 * @since   2.0
	 */
	public function addListener($listener, $priorities = array())
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
		$this->addListener($callable, array($event => $priority));

		return $this;
	}
}