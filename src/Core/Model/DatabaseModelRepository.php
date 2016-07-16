<?php
/**
 * Part of Windwalker project.
 *
 * @copyright  Copyright (C) 2016 {ORGANIZATION}. All rights reserved.
 * @license    GNU General Public License version 2 or later.
 */

namespace Windwalker\Core\Model;

use Windwalker\Core\Model\Traits\DatabaseRepositoryTrait;

/**
 * The ModelRepository class.
 *
 * @since  {DEPLOY_VERSION}
 */
class DatabaseModelRepository extends ModelRepository implements DatabaseRepositoryInterface
{
	use DatabaseRepositoryTrait;
}
