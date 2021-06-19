<?php
/**
 * Part of Windwalker project.
 *
 * @copyright  Copyright (C) {YEAR} LYRASOFT. All rights reserved.
 * @license    GNU General Public License version 2 or later;
 */

use Windwalker\Core\Migration\AbstractMigration;
use Windwalker\Database\Schema\Column;
use Windwalker\Database\Schema\DataType;
use Windwalker\Database\Schema\Schema;

/**
 * Migration class, version: {{version}}
 */
class {{className}} extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up()
    {
        $this->createTable('queue_failed_jobs', function (Schema $schema) {
            $schema->primary('id');
            $schema->varchar('connection');
            $schema->varchar('queue');
            $schema->longtext('body');
            $schema->longtext('exception');
            $schema->datetime('created');
        });
    }

    /**
     * Migrate Down.
     */
    public function down()
    {
        $this->drop('queue_fail_jobs');
    }
}
