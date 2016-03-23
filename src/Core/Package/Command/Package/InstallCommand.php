<?php
/**
 * Part of starter project.
 *
 * @copyright  Copyright (C) 2016 {ORGANIZATION}. All rights reserved.
 * @license    GNU General Public License version 2 or later.
 */

namespace Windwalker\Core\Package\Command\Package;

use Windwalker\Console\Command\Command;
use Windwalker\Console\Prompter\BooleanPrompter;
use Windwalker\Core\Package\PackageHelper;
use Windwalker\Core\Package\PackageResolver;
use Windwalker\Filesystem\File;

/**
 * The InstallCommand class.
 *
 * @since  {DEPLOY_VERSION}
 */
class InstallCommand extends Command
{
	/**
	 * Property name.
	 *
	 * @var  string
	 */
	protected $name = 'install';

	/**
	 * Property description.
	 *
	 * @var  string
	 */
	protected $description = 'Install package config';

	/**
	 * Initialise command.
	 *
	 * @return void
	 *
	 * @since  2.0
	 */
	protected function initialise()
	{
		parent::initialise();
	}

	/**
	 * Execute this command.
	 *
	 * @return int
	 *
	 * @since  2.0
	 */
	protected function doExecute()
	{
		$env = $this->getOption('env');
		$resolver = new PackageResolver($this->app->getContainer());
		
		$packages = $env::loadPackages();

		foreach ($packages as $name => $package)
		{
			$resolver->addPackage($name, $package);
		}

		$pkgName = $this->getArgument(0);

		if (!$pkgName)
		{
			throw new \InvalidArgumentException('No package input.');
		}

		$package = $resolver->getPackage($pkgName);

		if (!$package)
		{
			throw new \InvalidArgumentException('Package: ' . $pkgName . ' not found.');
		}

		$dir = $package->getDir();

		// Config
		$targetFolder = WINDWALKER_ETC . '/package';
		$file = $dir . '/config.dist.yml';
		$target = $targetFolder . '/' . $pkgName . '.yml';

		if (is_file($file) && with(new BooleanPrompter)->ask("File: <info>config.dist.yml</info> exists,\n do you want to copy it to <info>etc/package/" . $pkgName . '.yml</info> [Y/n]: ', true))
		{
			if (is_file($target) && with(new BooleanPrompter)->ask('File exists, do you want to override it? [N/y]: ', false))
			{
				File::delete($target);
			}

			if (!is_file($target) && File::copy($file, $target . '/' . $pkgName . '.yml'))
			{
				$this->out('Copy to <info>etc/package/' . $pkgName . '.yml</info> successfully.');
			}
		}

		$file = $dir . '/secret.dist.yml';
		$target = WINDWALKER_ETC . '/secret.yml';

		if (is_file($file) && with(new BooleanPrompter)->ask("File: <info>secret.dist.yml</info> exists,\n do you want to copy content to bottom of <info>etc/secret.yml</info> [Y/n]: ", true))
		{
			$secret = file_get_contents($target);
			$new = file_get_contents($file);
			$secret = $secret . "\n\n# " . $pkgName . "\n" . $new;

			file_put_contents($target, $secret);

			$this->out('Copy to <info>etc/secret.yml</info> successfully.');
		}
		
		return true;
	}
}
