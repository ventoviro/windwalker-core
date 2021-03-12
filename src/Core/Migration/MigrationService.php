<?php

/**
 * Part of starter project.
 *
 * @copyright  Copyright (C) 2021 __ORGANIZATION__.
 * @license    __LICENSE__
 */

declare(strict_types=1);

namespace Windwalker\Core\Migration;

use Monolog\DateTimeImmutable;
use Windwalker\Core\Application\ApplicationInterface;
use Windwalker\Core\Console\IOInterface;
use Windwalker\Core\IO\IOSocketInterface;
use Windwalker\Core\IO\IOSocketTrait;
use Windwalker\Database\DatabaseAdapter;
use Windwalker\Database\Event\QueryEndEvent;
use Windwalker\Database\Event\QueryFailedEvent;
use Windwalker\Database\Schema\Schema;
use Windwalker\Event\EventAwareTrait;
use Windwalker\Filesystem\FileObject;
use Windwalker\Filesystem\Filesystem;
use Windwalker\Stream\Stream;
use Windwalker\Utilities\SimpleTemplate;

/**
 * The MigrationService class.
 */
class MigrationService implements IOSocketInterface
{
    use EventAwareTrait;
    use IOSocketTrait;

    /**
     * MigrationService constructor.
     *
     * @param  ApplicationInterface  $app
     * @param  DatabaseAdapter       $db
     */
    public function __construct(protected ApplicationInterface $app, protected DatabaseAdapter $db)
    {
        $this->db->getDispatcher()->registerDealer($this->getDispatcher());
    }

    /**
     * migrate
     *
     * @param  string       $path
     * @param  string|null  $targetVersion
     * @param  string|null  $logFile
     *
     * @return  void
     */
    public function migrate(string $path, ?string $targetVersion, ?string $logFile = null): void
    {
        $migrations     = $this->getMigrations($path);
        $versions       = $this->getVersions();
        $currentVersion = $this->getCurrentVersion();

        if ($migrations === []) {
            throw new \RuntimeException('No migrations found.');
        }

        if ($targetVersion === null) {
            $targetVersion = max(array_merge($versions, array_keys($migrations)));
        } elseif ($targetVersion !== '0' && empty($migrations[$targetVersion])) {
            throw new \RuntimeException('Version is not valid.');
        }

        if ($logFile) {
            if (is_file($logFile)) {
                Filesystem::delete($logFile);
            }

            Filesystem::mkdir(dirname($logFile));

            $logStream = new Stream($logFile, Stream::MODE_WRITE_ONLY_FROM_END);

            // Log query
            $this->on(
                QueryEndEvent::class,
                function (QueryEndEvent $event) use ($logStream) {
                    $logStream->write($event->getDebugQueryString() . "\n\n");
                }
            );

            // Log failed
            $this->on(
                QueryFailedEvent::class,
                function (QueryFailedEvent $event) use ($logStream) {
                    $e = $event->getException();
                    $logStream->write("-- ERROR: {$e->getMessage()}\n{$event->getDebugQueryString()}\n\n");
                }
            );
        }

        $count     = 0;
        $direction = ($targetVersion > $currentVersion) ? Migration::UP : Migration::DOWN;

        if ($direction === Migration::DOWN) {
            krsort($migrations);

            foreach ($migrations as $migration) {
                if ($migration->version <= $targetVersion) {
                    break;
                }

                if (in_array((string) $migration->version, $versions, true)) {
                    $this->executeMigration($migration, Migration::DOWN);
                }

                $count++;
            }
        } else {
            ksort($migrations);

            foreach ($migrations as $migration) {
                if ($migration->version > $targetVersion) {
                    break;
                }

                if (!in_array((string) $migration->version, $versions, true)) {
                    $this->executeMigration($migration, Migration::UP);
                }

                $count++;
            }
        }

        if (!$count) {
            $this->useIO(fn (IOInterface $io) => $io->writeln('No changes.'));
        }
    }

    public function executeMigration(Migration $migration, string $direction = Migration::UP): void
    {
        $mig = $migration;

        include $migration->file->getPathname();

        $handler = $migration->get($direction);

        if (!$handler) {
            return;
        }

        $start = new \DateTimeImmutable();

        $this->useIO(function (IOInterface $io) use ($direction, $migration) {
            $io->write(
                sprintf(
                    '<fg=gray>%s</> <info>%s</info> <fg=%s>%s</>... ',
                    $migration->version,
                    $migration->name,
                    $direction === Migration::UP
                        ? 'bright-cyan'
                        : 'magenta',
                    strtoupper($direction)
                )
            );
        });

        $this->app->call($handler);

        $this->useIO(function (IOInterface $io) use ($direction, $migration) {
            $io->writeln(
                '<fg=bright-green>Success</>'
            );
        });

        $end = new \DateTimeImmutable();

        // $this['log.' . $versionInfo['id']] = [
        //     'id' => $versionInfo['id'],
        //     'direction' => $direction,
        //     'name' => $versionInfo['name'],
        // ];

        $this->storeVersion($migration, $direction, $start, $end);
    }

    /**
     * getMigrations
     *
     * @param  string  $path
     *
     * @return  array<string, Migration>
     */
    public function getMigrations(string $path): array
    {
        $files = Filesystem::files($path);

        $migrations = [];

        foreach ($files as $file) {
            $ext = $file->getExtension();

            if ($ext !== 'php') {
                continue;
            }

            $mig = new Migration($file, $this->db);

            $migrations[$mig->version] = $mig;
        }

        return $migrations;
    }

    /**
     * getVersions
     *
     * @return  array
     */
    public function getVersions(): array
    {
        $this->initLogTable();

        return $this->db->select('version')
            ->from($this->getLogTable())
            ->order('version', 'ASC')
            ->loadColumn()
            ->dump();
    }

    /**
     * getCurrentVersion
     *
     * @return  string
     */
    public function getCurrentVersion(): string
    {
        $versions = $this->getVersions();

        return (string) ($versions[array_key_last($versions)] ?? '0');
    }

    public function storeVersion(
        Migration $migration,
        string $direction,
        \DateTimeInterface $start,
        \DateTimeInterface $end
    ): void {
        if ($direction === Migration::UP) {
            $data['version']    = $migration->version;
            $data['name']       = $migration->name;
            $data['start_time'] = $start->format($this->db->getDateFormat());
            $data['end_time']   = $end->format($this->db->getDateFormat());

            $this->db->getWriter()->insertOne($this->getLogTable(), $data);
        } else {
            $this->db->delete($this->getLogTable())
                ->where('version', $migration->version)
                ->execute();
        }
    }

    /**
     * initLogTable
     *
     * @return  void
     */
    public function initLogTable(): void
    {
        $table = $this->db->getTable($this->getLogTable());

        if ($table->exists()) {
            if (!$table->hasColumn('name')) {
                $table->update(
                    function (Schema $schema) {
                        $schema->varchar('name');
                    }
                );
            }

            return;
        }

        $table->create(
            function (Schema $schema) {
                $schema->varchar('version');
                $schema->varchar('name');

                // Use exists time as timestamp before framework supports CURRENT_TIMESTAMP
                $schema->timestamp('start_time')->defaultCurrent();
                $schema->timestamp('end_time')->defaultCurrent();

                $schema->addPrimaryKey(['version', 'name']);
            }
        );
    }

    public function getLogTable(): string
    {
        return $this->app->config('db.migration.table_name') ?: 'migration_log';
    }

    /**
     * copyMigrationFile
     *
     * @param  string  $dir
     * @param  string  $name
     * @param  string  $source
     *
     * @return  FileObject
     */
    public function copyMigrationFile(string $dir, string $name, string $source): FileObject
    {
        $migrations = $this->getMigrations($dir);

        // Check name not exists
        foreach ($migrations as $migration) {
            if (strtolower($name) === strtolower($migration->name)) {
                throw new \RuntimeException(
                    'Migration: <info>' . $name . "</info> has exists. \nFile at: <info>" .
                    $migration->file->getPathname() . '</info>'
                );
            }
        }

        $date = new \DateTimeImmutable('now');

        $version = $date->format('YmdHis');
        $year = $date->format('Y');

        $file = $version . '_' . ucfirst($name) . '.php';

        $tmpl = file_get_contents($source);

        $tmpl = SimpleTemplate::create($tmpl)(
            compact('year', 'version', 'name')
        );

        $filePath = $dir . '/' . $file;

        if (is_file($filePath)) {
            throw new \RuntimeException(sprintf('File already exists: %s', $filePath));
        }

        return Filesystem::write($filePath, $tmpl);
    }
}
