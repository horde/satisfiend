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

namespace Horde\Satisfiend\Controller\Api;

use Horde\Satisfiend\EventRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * `GET /satisfiend/api/events/:delivery_id/payload` - the raw webhook
 * payload, byte-for-byte, as it arrived from the provider.
 *
 * Distinct from the detail endpoint: this route bypasses re-decoding
 * and re-encoding, so what you get is exactly what the provider sent
 * (useful for verifying signatures out-of-band, replaying against
 * other tools, or diagnosing encoding issues).
 *
 * The response uses `Content-Disposition: inline; filename=...` so
 * saving it in a browser produces a sensible name derived from the
 * delivery id.
 */
class EventPayloadController implements RequestHandlerInterface
{
    public function __construct(
        private readonly EventRepository $repository,
        private readonly ApiResponse $responder,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $routeParams = $request->getAttribute('route', []);
        $deliveryId = is_array($routeParams) ? (string) ($routeParams['delivery_id'] ?? '') : '';
        if ($deliveryId === '') {
            return $this->responder->error(404, 'not_found', 'Missing delivery id');
        }

        $row = $this->repository->findByDeliveryId($deliveryId);
        if ($row === null) {
            return $this->responder->error(404, 'not_found', 'No event with that delivery id');
        }

        $payload = (string) ($row['payload'] ?? '');

        // Sanitise the filename: only accept the delivery id chars that
        // the route regex already allows, but be defensive if the row
        // predates the regex.
        $safeName = preg_replace('/[^A-Za-z0-9\-]/', '_', $deliveryId) ?: 'payload';

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Content-Disposition', 'inline; filename="' . $safeName . '.json"')
            ->withBody($this->streamFactory->createStream($payload));
    }
}
