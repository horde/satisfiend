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

use Horde\Db\Adapter;

/**
 * Query layer over `satisfiend_events`. Shared by:
 *
 *   - {@see Controller\EventListController} - HTML
 *     table + filter form.
 *   - {@see Controller\EventDetailController} - HTML
 *     detail view with rendered payload.
 *   - {@see Controller\Api\EventListController}
 *     and siblings - JSON API (ticket 3).
 *
 * All filter fields are optional. Callers pass an
 * {@see EventFilter} value object; unset fields are ignored. `q` is a
 * free-text search that ORs across repository, actor, delivery_id, and
 * node_id (LIKE-based).
 *
 * Every method is read-only. Writes happen through
 * {@see Listener\PersistEventListener} on the event
 * dispatch path.
 */
class EventRepository
{
    public function __construct(
        private readonly Adapter $db,
    ) {}

    /**
     * Return one event row by delivery id (the provider-supplied unique
     * identifier used for dedupe, and the external identifier in URLs
     * and the API). Returns null if not found.
     *
     * All columns are returned including the raw payload.
     *
     * @return array<string,mixed>|null
     */
    public function findByDeliveryId(string $deliveryId): ?array
    {
        if ($deliveryId === '') {
            return null;
        }

        $row = $this->db->selectOne(
            'SELECT event_id, slug, node_id, delivery_id, event_type, action,'
                . ' repository, ref, sha, actor, payload, status, retry_count,'
                . ' received_at, processed_at, debug'
                . ' FROM satisfiend_events WHERE delivery_id = ?',
            [$deliveryId]
        );

        return $row === false || $row === null ? null : $row;
    }

    /**
     * Return a paginated slice of events matching the filter, ordered
     * most-recent-first.
     *
     * @return list<array<string,mixed>>
     */
    public function list(EventFilter $filter, int $page = 1, int $perPage = 50): array
    {
        $perPage = max(1, min(100, $perPage));
        $offset = max(0, ($page - 1) * $perPage);

        [$where, $bind] = $this->buildWhere($filter);

        $sql = 'SELECT event_id, slug, delivery_id, event_type, action,'
            . ' repository, actor, status, received_at, debug'
            . ' FROM satisfiend_events';
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $sql .= ' ORDER BY event_id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;

        return $this->db->selectAll($sql, $bind);
    }

    /**
     * Total row count for the filter - used by the list view to compute
     * pagination metadata.
     */
    public function count(EventFilter $filter): int
    {
        [$where, $bind] = $this->buildWhere($filter);

        $sql = 'SELECT COUNT(*) FROM satisfiend_events';
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        return (int) $this->db->selectValue($sql, $bind);
    }

    /**
     * @return array{0:string,1:list<mixed>} SQL fragment (without the
     *         leading WHERE) and the parameter list. Empty string when
     *         the filter is empty.
     */
    private function buildWhere(EventFilter $filter): array
    {
        $clauses = [];
        $bind = [];

        if ($filter->slug !== null) {
            $clauses[] = 'slug = ?';
            $bind[] = $filter->slug;
        }
        if ($filter->eventType !== null) {
            $clauses[] = 'event_type = ?';
            $bind[] = $filter->eventType;
        }
        if ($filter->action !== null) {
            $clauses[] = 'action = ?';
            $bind[] = $filter->action;
        }
        if ($filter->repository !== null) {
            $clauses[] = 'repository = ?';
            $bind[] = $filter->repository;
        }
        if ($filter->actor !== null) {
            $clauses[] = 'actor = ?';
            $bind[] = $filter->actor;
        }
        if ($filter->status !== null) {
            $clauses[] = 'status = ?';
            $bind[] = $filter->status;
        }
        if ($filter->debug !== null) {
            $clauses[] = 'debug = ?';
            $bind[] = $filter->debug ? 1 : 0;
        }
        if ($filter->since !== null) {
            $clauses[] = 'received_at >= ?';
            $bind[] = $filter->since;
        }
        if ($filter->until !== null) {
            $clauses[] = 'received_at <= ?';
            $bind[] = $filter->until;
        }
        if ($filter->q !== null && $filter->q !== '') {
            // Free-text search across the four "identity" columns.
            $like = '%' . $filter->q . '%';
            $clauses[] = '(repository LIKE ? OR actor LIKE ? OR delivery_id LIKE ? OR node_id LIKE ?)';
            $bind[] = $like;
            $bind[] = $like;
            $bind[] = $like;
            $bind[] = $like;
        }

        return [implode(' AND ', $clauses), $bind];
    }
}
