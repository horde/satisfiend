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

use Horde\Satisfiend\Event\WebhookReceivedEvent;
use InvalidArgumentException;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

/**
 * Synthesises a {@see WebhookReceivedEvent} from a fixture file and
 * dispatches it through the shared event dispatcher.
 *
 * Bypasses HTTP, routing, and HMAC verification. Every event created
 * this way is tagged `debug=true` so that synthetic events never
 * contaminate wire-delivery data in downstream queries.
 *
 * Real HTTP deliveries - including those posted by
 * {@see DebugHttpSender} - go through {@see WebhookHandler} and land
 * with `debug=false`; this class is not part of that path.
 */
class DebugInjectionService
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
    ) {}

    /**
     * @param string      $slug        Endpoint slug the synthetic event will be attributed to.
     *                                 Does not need to exist in `satisfiend_endpoints`;
     *                                 in-process injection never consults the endpoint table.
     * @param string      $providerType Provider identifier (github, gitea, gitlab, ...).
     * @param string      $eventType   Provider event name to record (e.g. "push").
     * @param string      $fixturePath Absolute path to a JSON payload file.
     * @param string|null $actionOverride Optional action to record on the event; when null the
     *                                 action is read from the fixture's top-level `action` field.
     * @param string      $deliveryId  Delivery id to attach to the event. Defaults to a
     *                                 timestamp-based synthetic id so repeated invocations
     *                                 do not collide on the unique index.
     */
    public function inject(
        string $slug,
        string $providerType,
        string $eventType,
        string $fixturePath,
        ?string $actionOverride = null,
        string $deliveryId = '',
    ): WebhookReceivedEvent {
        if (!is_file($fixturePath) || !is_readable($fixturePath)) {
            throw new InvalidArgumentException(
                sprintf('Fixture not readable: %s', $fixturePath)
            );
        }

        $body = file_get_contents($fixturePath);
        if ($body === false || $body === '') {
            throw new RuntimeException(
                sprintf('Fixture empty or unreadable: %s', $fixturePath)
            );
        }

        $payload = json_decode($body);
        if (!is_object($payload)) {
            throw new InvalidArgumentException(
                sprintf('Fixture is not a JSON object: %s', $fixturePath)
            );
        }

        $event = new WebhookReceivedEvent(
            slug: $slug,
            providerType: $providerType,
            eventType: $eventType,
            action: $actionOverride ?? ($payload->action ?? ''),
            repository: PayloadExtractor::repository($payload),
            actor: PayloadExtractor::actor($payload),
            nodeId: PayloadExtractor::nodeId($payload),
            deliveryId: $deliveryId !== '' ? $deliveryId : $this->syntheticDeliveryId(),
            payload: $body,
            debug: true,
        );

        $this->dispatcher->dispatch($event);

        return $event;
    }

    private function syntheticDeliveryId(): string
    {
        return 'debug-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    }
}
