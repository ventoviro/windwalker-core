<?php
/**
 * Part of Windwalker project.
 *
 * @copyright  Copyright (C) 2017 ${ORGANIZATION}.
 * @license    __LICENSE__
 */

namespace Windwalker\Core\Queue\Job;

/**
 * The CallableJob class.
 *
 * @since  __DEPLOY_VERSION__
 */
class CallableJob implements JobInterface
{
	/**
	 * Property callable.
	 *
	 * @var  callable
	 */
	protected $callback;

	/**
	 * Property name.
	 *
	 * @var  null|string
	 */
	protected $name;

	/**
	 * CallableJob constructor.
	 *
	 * @param string   $name
	 * @param callable $callback
	 */
	public function __construct($name = null, callable $callback)
	{
		$this->callback = $callback;
		$this->name     = $name;
	}

	/**
	 * getName
	 *
	 * @return  string
	 */
	public function getName()
	{
		return $this->name;
	}

	/**
	 * handle
	 *
	 * @return  void
	 */
	public function execute()
	{
		$callback = $this->callback;

		$callback();
	}
}