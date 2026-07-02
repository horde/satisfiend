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

use Horde\Satisfiend\EventFilter;
use Horde\Satisfiend\EventRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * `GET /satisfiend/api/events` - JSON list of webhook events.
 *
 * Accepts the same query params as the HTML list view (ticket 2) via
 * {@see EventFilter::fromQueryParams()} so the JSON and HTML surfaces
 * share exactly one filter grammar. Pagination is offset-based
 * (`page`, `per_page` capped at 100) - fine while events do not get
 * renumbered post-insert; cursor pagination can layer later if the
 * table grows large enough to make skip costs matter.
 *
 * Auth: session cookie (same as the HTML dashboard). API tokens are
 * a later plan per the phase-1 decisions.
 */
class EventListController implements RequestHandlerInterface
{
    private const DEFAULT_PER_PAGE = 50;
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly EventRepository $repository,
        private readonly EventJsonPresenter $presenter,
        private readonly ApiResponse $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $filter = EventFilter::fromQueryParams($params);

        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = (int) ($params['per_page'] ?? self::DEFAULT_PER_PAGE);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        $total = $this->repository->count($filter);
        $rows = $this->repository->list($filter, $page, $perPage);

        $data = array_map(fn(array $row) => $this->presenter->summary($row), $rows);

        // Cast the applied-filter map to object when empty so JSON
        // encoding produces `{}` rather than `[]`. Consumers can then
        // rely on `filter` being an object shape at all times.
        $appliedFilter = $filter->toQueryParams();
        $filterOut = $appliedFilter === [] ? (object) [] : $appliedFilter;

        return $this->responder->json([
            'data' => $data,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
            'filter' => $filterOut,
        ]);
    }
}
