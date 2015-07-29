<?php
/**
 * Part of Windwalker project. 
 *
 * @copyright  Copyright (C) 2014 - 2015 LYRASOFT. All rights reserved.
 * @license    GNU Lesser General Public License version 3 or later.
 */

namespace Windwalker\Core\Test\Mvc\Model;

use Windwalker\Core\Model\DatabaseModel;

/**
 * The StubModel class.
 * 
 * @since  {DEPLOY_VERSION}
 */
class FlowerModel extends DatabaseModel
{
	/**
	 * getSakura
	 *
	 * @return  string
	 */
	public function getSakura()
	{
		return 'Sakura';
	}

	/**
	 * getFlower
	 *
	 * @return  string
	 */
	public function getFlower()
	{
		return 'Flower';
	}
}
