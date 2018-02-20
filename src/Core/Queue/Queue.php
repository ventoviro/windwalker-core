<?php
/**
 * Part of Windwalker project.
 *
 * @copyright  Copyright (C) 2017 ${ORGANIZATION}.
 * @license    __LICENSE__
 */

namespace Windwalker\Core\Queue;

use Windwalker\Core\Queue\Driver\QueueDriverInterface;
use Windwalker\Core\Queue\Job\CallableJob;
use Windwalker\Core\Queue\Job\JobInterface;
use Windwalker\DI\Container;

/**
 * The Queue class.
 *
 * @since  3.2
 */
class Queue
{
	/**
	 * Property driver.
	 *
	 * @var QueueDriverInterface
	 */
	protected $driver;

	/**
	 * Property container.
	 *
	 * @var  Container
	 */
	protected $container;

	/**
	 * QueueManager constructor.
	 *
	 * @param QueueDriverInterface $driver
	 * @param Container            $container
	 */
	public function __construct(QueueDriverInterface $driver, Container $container)
	{
		$this->driver = $driver;
		$this->container = $container;
	}

	/**
	 * push
	 *
	 * @param mixed  $job
	 * @param int    $delay
	 * @param string $queue
	 * @param array  $options
	 *
	 * @return  int|string
	 */
	public function push($job, $delay = 0, $queue = null, array $options = [])
	{
		$message = $this->getMessageByJob($job);
		$message->setDelay($delay);
		$message->setQueueName($queue);
		$message->setOptions($options);

		return $this->driver->push($message);
	}

	/**
	 * pushRaw
	 *
	 * @param string|array $body
	 * @param int          $delay
	 * @param null         $queue
	 * @param array        $options
	 *
	 * @return  int|string
	 */
	public function pushRaw($body, $delay = 0, $queue = null, array $options = [])
	{
		if (is_string($body))
		{
			json_decode($body, true);
		}

		$message = new QueueMessage;
		$message->setBody($body);
		$message->setDelay($delay);
		$message->setQueueName($queue);
		$message->setOptions($options);

		return $this->driver->push($message);
	}

	/**
	 * pop
	 *
	 * @param string $queue
	 *
	 * @return  QueueMessage
	 */
	public function pop($queue = null)
	{
		return $this->driver->pop($queue);
	}

	/**
	 * delete
	 *
	 * @param QueueMessage|mixed $message
	 *
	 * @return  void
	 */
	public function delete($message)
	{
		if (!$message instanceof QueueMessage)
		{
			$msg = new QueueMessage;
			$msg->setId($message);
		}

		$this->driver->delete($message);

		$message->isDeleted(true);
	}

	/**
	 * release
	 *
	 * @param QueueMessage|mixed $message
	 * @param int                $delay
	 *
	 * @return  void
	 */
	public function release($message, $delay = 0)
	{
		if (!$message instanceof QueueMessage)
		{
			$msg = new QueueMessage;
			$msg->setId($message);
		}

		$message->setDelay($delay);

		$this->driver->release($message);
	}

	/**
	 * runJob
	 *
	 * @param string $job
	 *
	 * @return  void
	 */
	public function runJob($job)
	{
		$job = unserialize($job);

		if (!$job instanceof JobInterface)
		{
			throw new \InvalidArgumentException('Job is not s JobInterface.');
		}

		$job->execute();
	}

	/**
	 * getMessage
	 *
	 * @param mixed $job
	 * @param array $data
	 *
	 * @return QueueMessage
	 * @throws \InvalidArgumentException
	 */
	public function getMessageByJob($job, array $data = [])
	{
		$message = new QueueMessage;

		$job = $this->createJobInstance($job);

		$data['class'] = get_class($job);

		$message->setName($job->getName());
		$message->setJob(serialize($job));
		$message->setData($data);

		return $message;
	}

	/**
	 * createJobInstance
	 *
	 * @param mixed $job
	 *
	 * @return  JobInterface
	 */
	protected function createJobInstance($job)
	{
		if ($job instanceof JobInterface)
		{
			return $job;
		}

		// Create callable
		if (is_callable($job))
		{
			$job = new CallableJob($job, md5(uniqid('', true)));
		}

		// Create by class name.
		if (is_string($job))
		{
			if (!class_exists($job) || is_subclass_of($job, JobInterface::class))
			{
				throw new \InvalidArgumentException(
					sprintf('Job should be a class which implements JobInterface, %s given', $job)
				);
			}

			$job = $this->container->newInstance($job);

			if (!$job instanceof JobInterface)
			{
				throw new \UnexpectedValueException('Job instance is not a JobInterface.');
			}
		}

		if (is_array($job))
		{
			throw new \InvalidArgumentException('Job should not be array.');
		}

		return $job;
	}

	/**
	 * Method to get property Driver
	 *
	 * @return  QueueDriverInterface
	 */
	public function getDriver()
	{
		return $this->driver;
	}

	/**
	 * Method to set property driver
	 *
	 * @param   QueueDriverInterface $driver
	 *
	 * @return  static  Return self to support chaining.
	 */
	public function setDriver(QueueDriverInterface $driver)
	{
		$this->driver = $driver;

		return $this;
	}
}