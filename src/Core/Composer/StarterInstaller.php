<?php
/**
 * Part of Windwalker project.
 *
 * @copyright  Copyright (C) 2014 - 2015 LYRASOFT. All rights reserved.
 * @license    GNU Lesser General Public License version 3 or later.
 */

namespace Windwalker\Core\Composer;

use Composer\IO\IOInterface;
use Composer\Script\Event;

use function Windwalker\uid;

/**
 * The StarterInstaller class.
 *
 * @since  2.1.1
 */
class StarterInstaller
{
    /**
     * Do install.
     *
     * @param Event $event The command event.
     *
     * @return  void
     */
    public static function rootInstall(Event $event): void
    {
        include getcwd() . '/vendor/autoload.php';

        $io = $event->getIO();

        static::genSecretCode($event);

        static::genEnv($event);

        // Complete
        $io->write('Install complete.');
    }

    /**
     * Generate secret code.
     *
     * @param IOInterface $io
     *
     * @return  void
     */
    public static function genSecretCode(Event $event): void
    {
        $io = $event->getIO();
        $file = getcwd() . '/etc/conf/app.php';

        $config = file_get_contents($file);

        $config = str_replace('{{ REPLACE THIS AS RANDOM SECRET CODE }}', uid(), $config);

        file_put_contents($file, $config);

        $io->write('Auto created secret key.');
    }

    /**
     * Generate database config. will store in: etc/secret.yml.
     *
     * @param IOInterface $io
     *
     * @return  void
     */
    public static function genEnv(Event $event): void
    {
        include getcwd() . '/vendor/autoload.php';

        $io = $event->getIO();

        $dist = getcwd() . '/.env.dist';
        $dest = getcwd() . '/.env';

        if (is_file($dest)) {
            $io->write('.env file already exists.');

            return;
        }

        $env = file_get_contents($dist);

        if ($io->askConfirmation("\nDo you want to use database? [Y/n]: ", true)) {
            $vars = [];

            $supportedDrivers = [
                'pdo_mysql',
                'mysqli',
                'pdo_pgsql',
                'pgsql',
                'pdo_sqlsrv',
                'sqlsrv',
                'pdo_sqlite',
            ];

            $io->write('Please select database drivers: ');

            foreach ($supportedDrivers as $i => $driver) {
                $io->write("  [$i] $driver");
            }

            $k = $io->ask('> ');

            $driver = $supportedDrivers[$k] ?? 'pdo_mysql';

            $io->write('Selected driver: ' . $driver);

            $vars['DATABASE_DRIVER']   = $driver;
            $vars['DATABASE_HOST']     = $io->ask('Database host [localhost]: ', 'localhost');
            $vars['DATABASE_NAME']     = $io->ask('Database name [acme]: ', 'acme');
            $vars['DATABASE_USER']     = $io->ask('Database user [root]: ', 'root');
            $vars['DATABASE_PASSWORD'] = $io->askAndHideAnswer('Database password: ');

            foreach ($vars as $key => $value) {
                $env = preg_replace('/' . $key . '=(.*)/', $key . '=' . $value, $env);
            }
        }

        file_put_contents($dest, $env);

        $io->write('');
        $io->write('Database config setting complete.');
        $io->write('');
    }
}
