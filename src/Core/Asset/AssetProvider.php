<?php
/**
 * Part of Windwalker project.
 *
 * @copyright  Copyright (C) 2016 {ORGANIZATION}. All rights reserved.
 * @license    GNU General Public License version 2 or later.
 */

namespace Windwalker\Core\Asset;

use Windwalker\Core\Registry\ConfigRegistry;
use Windwalker\DI\Container;
use Windwalker\DI\ServiceProviderInterface;
use Windwalker\Uri\UriData;

/**
 * The AssetProvider class.
 *
 * @since  {DEPLOY_VERSION}
 */
class AssetProvider implements ServiceProviderInterface
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
		$closure = function(Container $container)
		{
			/**
			 * @var UriData $uri
			 * @var ConfigRegistry $config
			 */
			$uri = $container->get('system.uri');
			$config = $container->get('system.config');

			$asset = new AssetManager([
				'uri_path' => rtrim($uri->path, '/') . '/' . $config->get('asset.uri', 'asset'),
				'uri_root' => rtrim($uri->root, '/') . '/' . $config->get('asset.uri', 'asset'),
				'public_sys_path' => $config->get('path.public')
			]);
			
			$asset->setDispatcher($container->get('system.dispatcher'));

			return $asset;
		};

		$container->share(AssetManager::class, $closure)
			->alias('system.asset', AssetManager::class)
			->alias('asset', AssetManager::class);
		
		// Script
		$closure = function (Container $container)
		{
			return new ScriptManager($container->get('system.asset'));
		};

		$container->share(ScriptManager::class, $closure)
			->alias('system.script.manager', ScriptManager::class)
			->alias('script.manager', ScriptManager::class);

		AbstractScript::$instance = function () use ($container)
		{
		    return $container->get('system.script.manager');
		};
	}
}