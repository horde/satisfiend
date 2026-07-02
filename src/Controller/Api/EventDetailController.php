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
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * `GET /satisfiend/api/events/:delivery_id` - JSON detail for one event.
 *
 * Returns the full row (every indexed column) plus the payload parsed
 * back into structured JSON. Consumers that only need the payload
 * bytes should GET the `/payload` sub-resource instead - the double
 * decode there is wasted work.
 */
class EventDetailController implements RequestHandlerInterface
{
    public function __construct(
        private readonly EventRepository $repository,
        private readonly EventJsonPresenter $presenter,
        private readonly ApiResponse $responder,
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

        return $this->responder->json(['data' => $this->presenter->detail($row)]);
    }
}
