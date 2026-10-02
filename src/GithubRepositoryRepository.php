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
use Horde\Db\Query\Expr;
use Horde\Db\Query\InsertBuilder;
use Horde\Db\Query\SelectBuilder;

/**
 * Query layer over `satisfiend_gh_repositories`.
 *
 * Each row is an immutable point-in-time snapshot of a GitHub repository via webhook, REST or GraphQL (see the `origin` column).
 * Rows are never updated or deleted by this repository. A new
 * observation of the same repository is simply a new row,
 * most-recent-first.
 *
 * All filter fields on {@see GithubRepositoryFilter} are optional. Unset
 * fields are ignored. `q` is a free-text search that ORs across owner,
 * name, and full_name (LIKE-based).
 */
class GithubRepositoryRepository
{
    private const TABLE = 'satisfiend_gh_repositories';

    public function __construct(
        private readonly Adapter $db,
    ) {}

    /**
     * Insert a new repository snapshot row and return its generated
     * `snapshot_id`.
     *
     * @param array<string,mixed> $row Column => value pairs, matching the
     *                                 `satisfiend_gh_repositories` schema.
     */
    public function insert(array $row): int
    {
        $query = (new InsertBuilder($this->db))
            ->into(self::TABLE)
            ->values($row)
            ->build();

        return (int) $this->db->insert($query->sql, $query->params, null, 'snapshot_id');
    }

    /**
     * Return the most recently observed snapshot for a given repository
     * node_id, or null if none exists.
     *
     * @return array<string,mixed>|null
     */
    public function findLatestByNodeId(string $nodeId): ?array
    {
        if ($nodeId === '') {
            return null;
        }

        $query = (new SelectBuilder($this->db))
            ->from(self::TABLE)
            ->where('node_id', '=', $nodeId)
            ->orderBy('observed_at', 'DESC')
            ->limit(1)
            ->build();

        $row = $this->db->selectOne($query->sql, $query->params);

        return $row === false || $row === null ? null : $row;
    }

    /**
     * Return the most recently observed snapshot for an `owner/name`
     * full name, or null if none exists.
     *
     * @return array<string,mixed>|null
     */
    public function findLatestByFullName(string $fullName): ?array
    {
        if ($fullName === '') {
            return null;
        }

        $query = (new SelectBuilder($this->db))
            ->from(self::TABLE)
            ->where('full_name', '=', $fullName)
            ->orderBy('observed_at', 'DESC')
            ->limit(1)
            ->build();

        $row = $this->db->selectOne($query->sql, $query->params);

        return $row === false || $row === null ? null : $row;
    }

    /**
     * Return a paginated slice of repository snapshots matching the
     * filter, ordered most-recent-first.
     *
     * @return list<array<string,mixed>>
     */
    public function list(GithubRepositoryFilter $filter, int $page = 1, int $perPage = 50): array
    {
        $perPage = max(1, min(100, $perPage));
        $offset = max(0, ($page - 1) * $perPage);

        $query = $this->applyFilter(new SelectBuilder($this->db), $filter)
            ->from(self::TABLE)
            ->orderBy('observed_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->build();

        return $this->db->selectAll($query->sql, $query->params);
    }

    /**
     * Total row count for the filter. Used by the list view to compute
     * pagination metadata.
     */
    public function count(GithubRepositoryFilter $filter): int
    {
        $query = $this->applyFilter(new SelectBuilder($this->db), $filter)
            ->from(self::TABLE)
            ->columns(Expr::count())
            ->build();

        return (int) $this->db->selectValue($query->sql, $query->params);
    }

    private function applyFilter(SelectBuilder $builder, GithubRepositoryFilter $filter): SelectBuilder
    {
        if ($filter->owner !== null) {
            $builder = $builder->where('owner', '=', $filter->owner);
        }
        if ($filter->name !== null) {
            $builder = $builder->where('name', '=', $filter->name);
        }
        if ($filter->visibility !== null) {
            $builder = $builder->where('visibility', '=', $filter->visibility);
        }
        if ($filter->isPrivate !== null) {
            $builder = $builder->where('is_private', '=', $filter->isPrivate ? 1 : 0);
        }
        if ($filter->isArchived !== null) {
            $builder = $builder->where('is_archived', '=', $filter->isArchived ? 1 : 0);
        }
        if ($filter->origin !== null) {
            $builder = $builder->where('origin', '=', $filter->origin);
        }
        if ($filter->since !== null) {
            $builder = $builder->where('observed_at', '>=', $filter->since);
        }
        if ($filter->until !== null) {
            $builder = $builder->where('observed_at', '<=', $filter->until);
        }
        if ($filter->q !== null && $filter->q !== '') {
            // Free-text search across the three "identity" columns.
            $like = '%' . $filter->q . '%';
            $builder = $builder->where(function ($clause) use ($like) {
                $clause->where('owner', 'LIKE', $like)
                    ->orWhere('name', 'LIKE', $like)
                    ->orWhere('full_name', 'LIKE', $like);
            });
        }

        return $builder;
    }
}
