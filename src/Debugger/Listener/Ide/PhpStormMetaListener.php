<?php
/**
 * Part of Windwalker project.
 *
 * @copyright  Copyright (C) 2016 {ORGANIZATION}. All rights reserved.
 * @license    GNU General Public License version 2 or later.
 */

namespace Windwalker\Debugger\Listener\Ide;

use Windwalker\Event\Event;
use Windwalker\Filesystem\File;
use Windwalker\Filesystem\Filesystem;
use Windwalker\Filesystem\Path\PathCollection;
use Windwalker\Filesystem\Path\PathLocator;
use Windwalker\Structure\Structure;
use Windwalker\String\StringHelper;

/**
 * The PhpStormListener class.
 *
 * @since  {DEPLOY_VERSION}
 */
class PhpStormMetaListener
{
	protected $tmpl = <<<TMPL
<?php
	
namespace PHPSTORM_META
{
	\$STATIC_METHOD_TYPES = [
		\Windwalker\Application\AbstractApplication::get('') => [
			%s
		]
	];
}
TMPL;

	/**
	 * onAfterExecute
	 *
	 * @param Event $event
	 *
	 * @return  void
	 */
	public function onAfterInitialise(Event $event)
	{
		$config = new Structure;

		$files = new PathCollection([WINDWALKER_ETC, WINDWALKER_VENDOR . '/windwalker/core/config']);

		/** @var PathLocator $file */
		foreach ($files->find('.*\.[php|json|yml|yaml]', true) as $file)
		{
			if (!in_array($file->getExtension(), ['php', 'json', 'yml', 'yaml']) || $file->getBasename() == 'define.php')
			{
				continue;
			}

			$config->loadFile($file->getPathname(), $file->getExtension());
		}

		$array = $config->flatten();
		
		$keys = array_map(function ($value)
		{
		    return StringHelper::quote($value) . ' instanceof mixed';
		}, array_keys($array));

		$data = sprintf($this->tmpl, implode(",\n", $keys));

		File::write(WINDWALKER_TEMP . '/ide/.phpstorm.meta.php', $data);
	}
}
