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

namespace Horde\Satisfiend\Event;

final readonly class WebhookReceivedEvent
{
    public function __construct(
        public string $slug,
        public string $providerType,
        public string $eventType,
        public string $action,
        public string $repository,
        public string $actor,
        public string $nodeId,
        public string $deliveryId,
        public string $payload,
    ) {}
}
