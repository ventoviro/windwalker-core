<?php

/**
 * Part of starter project.
 *
 * @copyright  Copyright (C) 2021 __ORGANIZATION__.
 * @license    __LICENSE__
 */

declare(strict_types=1);

namespace Windwalker\Core\Migration\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Windwalker\Core\Console\CommandInterface;
use Windwalker\Core\Console\CommandWrapper;
use Windwalker\Core\Console\IOInterface;
use Windwalker\Core\Migration\MigrationService;
use Windwalker\DI\Attributes\Autowire;
use Windwalker\DI\Attributes\Decorator;

/**
 * The MigrationToCommand class.
 */
#[CommandWrapper(
    description: 'Migrate to specific version or latest.'
)]
class MigrateCommand extends AbstractMigrationCommand
{
    /**
     * MigrationToCommand constructor.
     *
     * @param  MigrationService  $migrationService
     */
    public function __construct(#[Autowire] protected MigrationService $migrationService)
    {
        //
    }

    /**
     * @inheritDoc
     */
    public function configure(Command $command): void
    {
        parent::configure($command);

        $command->addArgument(
            'version',
            InputArgument::OPTIONAL,
            'The target version, leave empty to migrate to last.',
            null
        );
        $command->addOption(
            'seed',
            's',
            InputOption::VALUE_OPTIONAL,
            'Also import seeds.'
        );
        $command->addOption(
            'log',
            'l',
            InputOption::VALUE_OPTIONAL,
            'Log queries, can provide a custom file.',
            false
        );
        $command->addOption(
            'no-backup',
            null,
            InputOption::VALUE_REQUIRED,
            'Do not backup database.'
        );
        $command->addOption(
            'no-create-db',
            null,
            InputOption::VALUE_REQUIRED,
            'Do not auto create database or schema.'
        );
    }

    /**
     * @inheritDoc
     */
    public function execute(IOInterface $io)
    {
        // Dev check
        if (!$this->checkEnv($io)) {
            return 255;
        }

        // Backup
        $this->backup($io);

        // Change dir or package

        $this->migrationService->setIO($io);

        try {
            $this->migrationService->migrate(
                $this->getMigrationFolder($io),
                $io->getArgument('version'),
                $this->getLogFile($io)
            );
        } catch (\Throwable $e) {
            $io->writeln('<error>An error occurred: ' . $e->getMessage() . '</error>');

            throw $e;
        }

        $io->style()->newLine();

        return 0;
    }
}
