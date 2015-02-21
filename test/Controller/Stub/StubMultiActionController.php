<?php
/**
 * Part of starter project. 
 *
 * @copyright  Copyright (C) 2015 {ORGANIZATION}. All rights reserved.
 * @license    GNU General Public License version 2 or later;
 */

namespace Windwalker\Core\Test\Controller\Stub;

use Windwalker\Core\Controller\MultiActionController;

/**
 * The StubMultiActionController class.
 * 
 * @since  {DEPLOY_VERSION}
 */
class StubMultiActionController extends MultiActionController
{
	/**
	 * indexAction
	 *
	 * @return  string
	 */
	public function indexAction()
	{
		return 'index';
	}

	/**
	 * flyAction
	 *
	 * @param int $height
	 * @param int  $speed
	 *
	 * @return  string
	 */
	public function flyAction($height = null, $speed = 500)
	{
		return 'Flying on ' . $height . ' km and speed: ' . $speed;
	}
}