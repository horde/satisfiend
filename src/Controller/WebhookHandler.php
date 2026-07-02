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

namespace Horde\Satisfiend\Controller;

use Horde\Satisfiend\EndpointLookupInterface;
use Horde\Satisfiend\Event\WebhookReceivedEvent;
use Horde\Satisfiend\PayloadExtractor;
use Horde\Satisfiend\WebhookVerifierFactory;
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
        // Route params from RampageBootstrap live under the `route`
        // attribute (the raw $matchDict), not as top-level request
        // attributes. Read the slug from there.
        $routeParams = $request->getAttribute('route', []);
        $slug = is_array($routeParams) ? (string) ($routeParams['slug'] ?? '') : '';
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
            repository: PayloadExtractor::repository($payload),
            actor: PayloadExtractor::actor($payload),
            nodeId: PayloadExtractor::nodeId($payload),
            deliveryId: $request->getHeaderLine('X-GitHub-Delivery'),
            payload: $body,
        ));

        return $this->responseFactory->createResponse(202);
    }
}
