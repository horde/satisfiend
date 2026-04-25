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

namespace Horde\Satisfiend\Listener;

use Horde\Satisfiend\Event\WebhookReceivedEvent;
use Horde_Db_Adapter;

class PersistEventListener
{
    public function __construct(
        private readonly Horde_Db_Adapter $db,
    ) {}

    public function __invoke(WebhookReceivedEvent $event): void
    {
        if ($event->deliveryId !== '') {
            $exists = $this->db->selectValue(
                'SELECT 1 FROM satisfiend_events WHERE delivery_id = ?',
                [$event->deliveryId]
            );
            if ($exists) {
                return;
            }
        }

        $this->db->insert(
            'INSERT INTO satisfiend_events'
                . ' (slug, node_id, delivery_id, event_type, action,'
                . ' repository, actor, payload, status, retry_count, received_at)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $event->slug,
                $event->nodeId !== '' ? $event->nodeId : null,
                $event->deliveryId !== '' ? $event->deliveryId : null,
                $event->eventType,
                $event->action !== '' ? $event->action : null,
                $event->repository !== '' ? $event->repository : null,
                $event->actor !== '' ? $event->actor : null,
                $event->payload,
                'pending',
                0,
                date('Y-m-d H:i:s'),
            ]
        );
    }
}
