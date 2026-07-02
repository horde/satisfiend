<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @license http://www.horde.org/licenses/lgpl21 LGPL
 */

/**
 * Adds a `debug` boolean flag to satisfiend_events.
 *
 * The flag is set to true only for events created by the in-process
 * debug injector (`bin/satisfiend-cli debug inject`). Events arriving
 * over HTTP - including those posted by `bin/satisfiend-cli debug send`
 * - always have debug=false because they are real signed deliveries;
 * the HTTP path never sets this flag.
 */
class SatisfiendAddDebugFlag extends Horde_Db_Migration_Base
{
    public function up()
    {
        $this->addColumn(
            'satisfiend_events',
            'debug',
            'boolean',
            ['null' => false, 'default' => false]
        );
        $this->addIndex(
            'satisfiend_events',
            ['debug'],
            ['name' => 'satisfiend_events_debug']
        );
    }

    public function down()
    {
        $this->removeIndex('satisfiend_events', ['name' => 'satisfiend_events_debug']);
        $this->removeColumn('satisfiend_events', 'debug');
    }
}
