<?php

/**
 * Part of starter project.
 *
 * @copyright  Copyright (C) 2020 __ORGANIZATION__.
 * @license    __LICENSE__
 */

declare(strict_types=1);

namespace Windwalker\Core\Manager;

use Windwalker\Core\Runtime\Config;
use Windwalker\DI\Container;
use Windwalker\DI\Definition\DefinitionInterface;
use Windwalker\Utilities\Assert\ArgumentsAssert;
use Windwalker\Utilities\Cache\InstanceCacheTrait;
use Windwalker\Utilities\Classes\ObjectBuilderAwareTrait;
use Windwalker\Utilities\Classes\OptionAccessTrait;

/**
 * The AnstractManager class.
 */
abstract class AbstractManager
{
    use InstanceCacheTrait;

    /**
     * @var Config
     */
    protected Config $config;

    /**
     * @var Container
     */
    protected Container $container;

    /**
     * AbstractManager constructor.
     *
     * @param  Config     $config
     * @param  Container  $container
     */
    public function __construct(Config $config, Container $container)
    {
        $this->config = $config->proxy($this->getConfigPrefix());
        $this->container = $container;
    }

    public function getDefaultName(): ?string
    {
        return $this->config->getDeep('default');
    }

    public function create(?string $name = null, ...$args)
    {
        $name ??= $this->getDefaultName();

        if ($name === null) {
            throw new \InvalidArgumentException('Empty definition name.');
        }

        $args = $this->prepareArguments($name, $args);

        $define = $this->config->getDeep($this->getFactoryPath($name));

        $define ??= $this->getDefaultFactory($name, ...$args);

        if (!$define) {
            throw new \InvalidArgumentException(
                sprintf(
                    '%s::%s() definition: "%s" not found, the factory key is: %s',
                    static::class,
                    __FUNCTION__,
                    $name,
                    $this->getConfigPrefix() . '.' . $this->getFactoryPath($name)
                )
            );
        }

        return $this->container->newInstance($define, $args);
    }

    protected function prepareArguments(string $name, array $args): array
    {
        $args['_name'] = $name;

        return $args;
    }

    /**
     * getDefaultFactory
     *
     * @param  string  $name
     * @param  mixed   ...$args
     *
     * @return  mixed
     *
     * @since  __DEPLOY_VERSION__
     */
    protected function getDefaultFactory(string $name, ...$args)
    {
        return null;
    }

    public function get(?string $name = null, ...$args)
    {
        $name ??= $this->getDefaultName();

        return $this->once('instance.' . $name, fn () => $this->create($name, ...$args));
    }

    abstract  public function getConfigPrefix(): string;

    protected function getFactoryPath(string $name): string
    {
        return 'factories.instances.' . $name;
    }
}
