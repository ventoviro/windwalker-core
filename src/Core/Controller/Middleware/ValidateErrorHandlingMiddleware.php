<?php
/**
 * Part of Windwalker project.
 *
 * @copyright  Copyright (C) 2016 {ORGANIZATION}. All rights reserved.
 * @license    GNU General Public License version 2 or later.
 */

namespace Windwalker\Core\Controller\Middleware;

use Windwalker\Core\Frontend\Bootstrap;
use Windwalker\Core\Model\Exception\ValidateFailException;
use Windwalker\Form\Validate\ValidateResult;

/**
 * The ValidateErrorHandlingMiddleware class.
 *
 * @since  {DEPLOY_VERSION}
 */
class ValidateErrorHandlingMiddleware extends AbstractControllerMiddleware
{
	/**
	 * Call next middleware.
	 *
	 * @param   ControllerData $data
	 *
	 * @return mixed
	 * @throws \Exception
	 * @throws \Throwable
	 */
	public function execute($data = null)
	{
		try
		{
			return $this->next->execute($data);
		}
		catch (ValidateFailException $e)
		{
			$messages = $e->getMessages();

			if (isset($messages[ValidateResult::STATUS_REQUIRED]))
			{
				$this->controller->addMessage((array) $messages[ValidateResult::STATUS_REQUIRED], Bootstrap::MSG_DANGER);

				unset($messages[ValidateResult::STATUS_REQUIRED]);
			}

			if (isset($messages[ValidateResult::STATUS_FAILURE]))
			{
				$this->controller->addMessage((array) $messages[ValidateResult::STATUS_FAILURE], Bootstrap::MSG_WARNING);

				unset($messages[ValidateResult::STATUS_FAILURE]);
			}

			$this->controller->addMessage($messages, Bootstrap::MSG_DANGER);

			return false;
		}
		catch (\Exception $e)
		{
			if ($this->app->get('system.debug'))
			{
				throw $e;
			}

			$this->controller->processFailure($e);

			return false;
		}
	}
}