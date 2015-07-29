<?php
/**
 * Part of starter project. 
 *
 * @copyright  Copyright (C) 2014 - 2015 LYRASOFT. All rights reserved.
 * @license    GNU Lesser General Public License version 3 or later.
 */

namespace Windwalker\Core\Test\Mvc\Provider;

use Windwalker\DI\Container;
use Windwalker\DI\ServiceProviderInterface;

/**
 * The TestMvcProvider class.
 * 
 * @since  {DEPLOY_VERSION}
 */
class TestMvcProvider implements ServiceProviderInterface
{
	/**
	 * Registers the service provider with a DI container.
	 *
	 * @param   Container $container The DI container.
	 *
	 * @return  void
	 */
	public function register(Container $container)
	{
		$container->share('flower.sakura', 'Flower Sakura');

		$closure = function(Container $container)
		{
			return $container->get('system.config');
		};

		$container->share('mvc.config', $closure);
	}
}
