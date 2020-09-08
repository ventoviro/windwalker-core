<?php
/**
 * Part of Windwalker project.
 *
 * @copyright  Copyright (C) 2016 LYRASOFT. All rights reserved.
 * @license    GNU General Public License version 2 or later.
 */

namespace Windwalker\Core\Error;

use Windwalker\Core\Runtime\Config;
use Windwalker\Core\Service\LoggerService;
use Windwalker\Utilities\Reflection\BacktraceHelper;

/**
 * The ErrorLogHandler class.
 *
 * @since  3.0
 */
class ErrorLogHandler implements ErrorHandlerInterface
{
    /**
     * @var LoggerService
     */
    protected LoggerService $logger;

    /**
     * @var Config
     */
    protected Config $config;

    /**
     * ErrorLogHandler constructor.
     *
     * @param  LoggerService  $logger
     * @param  Config         $config
     */
    public function __construct(LoggerService $logger, Config $config)
    {
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * __invoke
     *
     * @param  \Throwable $e
     *
     * @return  void
     * @throws \Exception
     */
    public function __invoke(\Throwable $e): void
    {
        // Do not log 4xx errors
        $code = $e->getCode();

        if ($code < 400 || $code >= 500) {
            $message = sprintf(
                'Code: %s - %s - File: %s (%d)',
                $e->getCode(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );

            $traces = '';

            foreach (BacktraceHelper::normalizeBacktraces($e->getTrace(), $this->config->get('@root')) as $i => $trace) {
                $traces .= '    #' . ($i + 1) . ' - ' . $trace['function'] . ' ' . $trace['file'] . "\n";
            }

            $this->logger->error(
                $this->config->getDeep('error.log_channel'),
                $message . "\n" . $traces,
                ['exception' => $e]
            );
        }
    }
}
