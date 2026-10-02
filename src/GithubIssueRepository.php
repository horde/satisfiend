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
 * Query layer over `satisfiend_gh_issues`.
 *
 * Each row is an immutable point-in-time snapshot of a GitHub issue coming from
 * webhook, REST or GraphQL (see the `origin` column). Rows
 * are never updated or deleted by this repository. Only distinct versions are kept.
 *
 * All filter fields on {@see GithubIssueFilter} are optional; unset
 * fields are ignored. `q` is a free-text search that ORs across title
 * and author_login (LIKE-based).
 */
class GithubIssueRepository
{
    private const TABLE = 'satisfiend_gh_issues';

    public function __construct(
        private readonly Adapter $db,
    ) {}

    /**
     * Insert a new issue snapshot row and return its generated
     * `snapshot_id`.
     *
     * @param array<string,mixed> $row Column => value pairs, matching the
     *                                 `satisfiend_gh_issues` schema.
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
     * Return the most recently observed snapshot for a given issue
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
     * Return the most recently observed snapshot for a repo-scoped
     * issue number or null.
     *
     * @return array<string,mixed>|null
     */
    public function findLatestByNumber(string $owner, string $name, int $number): ?array
    {
        $query = (new SelectBuilder($this->db))
            ->from(self::TABLE)
            ->where('repository_owner', '=', $owner)
            ->where('repository_name', '=', $name)
            ->where('number', '=', $number)
            ->orderBy('observed_at', 'DESC')
            ->limit(1)
            ->build();

        $row = $this->db->selectOne($query->sql, $query->params);

        return $row === false || $row === null ? null : $row;
    }

    /**
     * Return a paginated slice of issue snapshots matching the filter,
     * ordered most-recent-first.
     *
     * @return list<array<string,mixed>>
     */
    public function list(GithubIssueFilter $filter, int $page = 1, int $perPage = 50): array
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
     * Total row count for the filter - used by the list view to compute
     * pagination metadata.
     */
    public function count(GithubIssueFilter $filter): int
    {
        $query = $this->applyFilter(new SelectBuilder($this->db), $filter)
            ->from(self::TABLE)
            ->columns(Expr::count())
            ->build();

        return (int) $this->db->selectValue($query->sql, $query->params);
    }

    private function applyFilter(SelectBuilder $builder, GithubIssueFilter $filter): SelectBuilder
    {
        if ($filter->repositoryOwner !== null) {
            $builder = $builder->where('repository_owner', '=', $filter->repositoryOwner);
        }
        if ($filter->repositoryName !== null) {
            $builder = $builder->where('repository_name', '=', $filter->repositoryName);
        }
        if ($filter->number !== null) {
            $builder = $builder->where('number', '=', $filter->number);
        }
        if ($filter->state !== null) {
            $builder = $builder->where('state', '=', $filter->state);
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
            // Free-text search across the two "identity" columns.
            $like = '%' . $filter->q . '%';
            $builder = $builder->where(function ($clause) use ($like) {
                $clause->where('title', 'LIKE', $like)
                    ->orWhere('author_login', 'LIKE', $like);
            });
        }

        return $builder;
    }
}
