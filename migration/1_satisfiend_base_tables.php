<?php

declare(strict_types=1);

class SatisfiendBaseTables extends Horde_Db_Migration_Base
{
    public function up()
    {
        $tableList = $this->tables();

        if (!in_array('satisfiend_endpoints', $tableList)) {
            $t = $this->createTable('satisfiend_endpoints', ['autoincrementKey' => false]);
            $t->column('slug', 'string', ['limit' => 64, 'null' => false]);
            $t->column('provider_type', 'string', ['limit' => 32, 'null' => false]);
            $t->column('secret', 'string', ['limit' => 255, 'null' => false]);
            $t->column('active', 'integer', ['limit' => 1, 'null' => false, 'default' => 1]);
            $t->column('created_by', 'string', ['limit' => 255, 'null' => false]);
            $t->column('config_json', 'text', ['null' => true]);
            $t->column('created_at', 'datetime', ['null' => false]);
            $t->column('updated_at', 'datetime', ['null' => false]);
            $t->primaryKey(['slug']);
            $t->end();

            $this->addIndex('satisfiend_endpoints', ['provider_type'], ['name' => 'satisfiend_endpoints_provider']);
            $this->addIndex('satisfiend_endpoints', ['active'], ['name' => 'satisfiend_endpoints_active']);
        }

        if (!in_array('satisfiend_events', $tableList)) {
            $t = $this->createTable('satisfiend_events', ['autoincrementKey' => 'event_id']);
            $t->column('slug', 'string', ['limit' => 64, 'null' => false]);
            $t->column('node_id', 'string', ['limit' => 255, 'null' => true]);
            $t->column('delivery_id', 'string', ['limit' => 64, 'null' => true]);
            $t->column('event_type', 'string', ['limit' => 64, 'null' => false]);
            $t->column('action', 'string', ['limit' => 64, 'null' => true]);
            $t->column('repository', 'string', ['limit' => 255, 'null' => true]);
            $t->column('ref', 'string', ['limit' => 255, 'null' => true]);
            $t->column('sha', 'string', ['limit' => 64, 'null' => true]);
            $t->column('actor', 'string', ['limit' => 255, 'null' => true]);
            $t->column('payload', 'text', ['null' => false]);
            $t->column('status', 'string', ['limit' => 16, 'null' => false, 'default' => 'pending']);
            $t->column('retry_count', 'integer', ['null' => false, 'default' => 0, 'unsigned' => true]);
            $t->column('received_at', 'datetime', ['null' => false]);
            $t->column('processed_at', 'datetime', ['null' => true]);
            $t->end();

            $this->addIndex('satisfiend_events', ['slug'], ['name' => 'satisfiend_events_slug']);
            $this->addIndex('satisfiend_events', ['node_id'], ['name' => 'satisfiend_events_node_id']);
            $this->addIndex('satisfiend_events', ['delivery_id'], ['name' => 'satisfiend_events_delivery_id', 'unique' => true]);
            $this->addIndex('satisfiend_events', ['event_type'], ['name' => 'satisfiend_events_type']);
            $this->addIndex('satisfiend_events', ['repository'], ['name' => 'satisfiend_events_repo']);
            $this->addIndex('satisfiend_events', ['status'], ['name' => 'satisfiend_events_status']);
            $this->addIndex('satisfiend_events', ['received_at'], ['name' => 'satisfiend_events_received']);
        }
    }

    public function down()
    {
        $this->dropTable('satisfiend_events');
        $this->dropTable('satisfiend_endpoints');
    }
}
