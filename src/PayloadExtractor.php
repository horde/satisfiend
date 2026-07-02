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

namespace Horde\Satisfiend;

/**
 * Small helpers that extract summary fields from a decoded webhook
 * payload. Shared by {@see WebhookHandler} (real HTTP deliveries) and
 * {@see DebugInjectionService} (in-process synthesised events) so that
 * both origins produce structurally identical
 * {@see Event\WebhookReceivedEvent} instances.
 */
final class PayloadExtractor
{
    /**
     * Return the entity-level `node_id` most likely to identify the
     * subject of the event. GitHub places the id under different keys
     * depending on the event type; we probe the standard set.
     */
    public static function nodeId(object $payload): string
    {
        foreach (['pull_request', 'issue', 'comment', 'review', 'release'] as $key) {
            if (isset($payload->$key->node_id)) {
                return (string) $payload->$key->node_id;
            }
        }

        return '';
    }

    public static function repository(object $payload): string
    {
        return isset($payload->repository->full_name)
            ? (string) $payload->repository->full_name
            : '';
    }

    public static function actor(object $payload): string
    {
        return isset($payload->sender->login)
            ? (string) $payload->sender->login
            : '';
    }
}
