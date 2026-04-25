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
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class WebhookHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly EndpointLookupInterface $endpointLookup,
        private readonly WebhookVerifierFactory $verifierFactory,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $slug = $request->getAttribute('slug', '');
        if ($slug === '') {
            return $this->responseFactory->createResponse(404);
        }

        $endpoint = $this->endpointLookup->findBySlug($slug);
        if ($endpoint === null || !$endpoint->active) {
            return $this->responseFactory->createResponse(404);
        }

        $verifier = $this->verifierFactory->create($endpoint->providerType, $endpoint->secret);
        if (!$verifier->verify($request)) {
            return $this->responseFactory->createResponse(403);
        }

        $body = (string) $request->getBody();
        $payload = json_decode($body);
        if ($payload === null) {
            return $this->responseFactory->createResponse(400);
        }

        $this->dispatcher->dispatch(new WebhookReceivedEvent(
            slug: $slug,
            providerType: $endpoint->providerType,
            eventType: $request->getHeaderLine('X-GitHub-Event'),
            action: $payload->action ?? '',
            repository: $payload->repository->full_name ?? '',
            actor: $payload->sender->login ?? '',
            nodeId: $this->extractNodeId($payload),
            deliveryId: $request->getHeaderLine('X-GitHub-Delivery'),
            payload: $body,
        ));

        return $this->responseFactory->createResponse(202);
    }

    private function extractNodeId(object $payload): string
    {
        foreach (['pull_request', 'issue', 'comment', 'review', 'release'] as $key) {
            if (isset($payload->$key->node_id)) {
                return $payload->$key->node_id;
            }
        }

        return '';
    }
}
