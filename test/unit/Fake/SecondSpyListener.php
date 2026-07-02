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

namespace Horde\Satisfiend\Test\Unit\Fake;

use Horde\Satisfiend\Event\WebhookReceivedEvent;

/**
 * Second callable listener used by ListenerLoaderTest to verify that
 * multiple named classes register in declared order.
 */
class SecondSpyListener
{
    /** @var list<WebhookReceivedEvent> */
    public array $received = [];

    public function __invoke(WebhookReceivedEvent $event): void
    {
        $this->received[] = $event;
    }
}
