<?php
/**
 * Part of Windwalker project.
 *
 * @copyright  Copyright (C) 2016 {ORGANIZATION}. All rights reserved.
 * @license    GNU General Public License version 2 or later.
 */

namespace Windwalker\Core\View;

use Windwalker\Registry\Registry;

/**
 * The StructureView class.
 *
 * @since  3.0
 */
class StructureView extends AbstractView implements \JsonSerializable
{
	const FORMAT_JSON = 'json';
	const FORMAT_XML  = 'xml';
	const FORMAT_YAML = 'yaml';
	const FORMAT_INI  = 'ini';
	const FORMAT_PHP  = 'php';

	/**
	 * Property data.
	 *
	 * @var  array|Registry
	 */
	protected $data = [];

	/**
	 * Property format.
	 *
	 * @var  string
	 */
	protected $format = self::FORMAT_JSON;

	/**
	 * Method to instantiate the view.
	 *
	 * @param   array  $data     The data array.
	 * @param   array  $config  The options array.
	 */
	public function __construct(array $data = [], $config = null)
	{
		parent::__construct($data, $config);

		// Init registry object.
		$this->data = new Registry($data);
	}

	/**
	 * prepareData
	 *
	 * @param Registry $registry
	 *
	 * @return  void
	 */
	protected function prepareData($registry)
	{
	}

	/**
	 * doRender
	 *
	 * @param  Registry $registry
	 *
	 * @return string
	 */
	protected function doRender($registry)
	{
		if ($registry instanceof Registry)
		{
			return $registry->toString($this->format, (array) $this->config->get('options', []));
		}
	}

	/**
	 * getData
	 *
	 * @return  Registry
	 */
	public function getData()
	{
		if (!$this->data)
		{
			$this->data = new Registry;
		}

		return $this->data;
	}

	/**
	 * setData
	 *
	 * @param   array|Registry  $data
	 *
	 * @return  static  Return self to support chaining.
	 */
	public function setData($data)
	{
		$this->data = $data instanceof Registry ? $data : new Registry($data);

		return $this;
	}

	/**
	 * Method to get property Format
	 *
	 * @return  string
	 */
	public function getFormat()
	{
		return $this->format;
	}

	/**
	 * Method to set property format
	 *
	 * @param   string $format
	 *
	 * @return  static  Return self to support chaining.
	 */
	public function setFormat($format)
	{
		$this->format = $format;

		return $this;
	}

	/**
	 * Return data which should be serialized by json_encode().
	 *
	 * @return  string
	 *
	 * @throws \RuntimeException
	 */
	public function jsonSerialize()
	{
		$format = $this->format;

		$result = $this->setFormat(static::FORMAT_JSON)->render();

		$this->format = $format;

		return $result;
	}
}