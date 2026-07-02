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

/**
 * Immutable value object describing a webhook delivery that has passed
 * signature verification and been accepted by the receiver.
 *
 * Dispatched by {@see \Horde\Satisfiend\Controller\WebhookHandler} for every valid
 * HTTP delivery and by
 * {@see \Horde\Satisfiend\DebugInjectionService} for in-process debug
 * injections. Consumers should not assume dispatch origin from any
 * field other than `$debug`.
 */
final readonly class WebhookReceivedEvent
{
    /**
     * @param string $slug         Endpoint slug that received the webhook.
     * @param string $providerType Provider identifier (github, gitea, gitlab).
     * @param string $eventType    Provider event name (push, pull_request, ...).
     * @param string $action       Sub-action (opened, closed, ...); empty when N/A.
     * @param string $repository   Full repository name (owner/repo); empty if unavailable.
     * @param string $actor        Username that triggered the event; empty if unavailable.
     * @param string $nodeId       Provider entity node id for idempotent dedupe.
     * @param string $deliveryId   Provider delivery id for idempotent dedupe.
     * @param string $payload      Raw JSON payload as received.
     * @param bool   $debug        True only when the event was synthesised
     *                             in-process by the debug injector. HTTP
     *                             deliveries - including `debug send` - are
     *                             always false.
     */
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
        public bool $debug = false,
    ) {}
}
