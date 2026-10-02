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
 * Query layer over `satisfiend_github_releases`.
 *
 * Each row is an immutable point-in-time snapshot of a GitHub release via webhook, REST or GraphQL (see the `origin` column). Rows
 * are never updated or deleted by this repository. Only distinct versions are kept.
 *
 * All filter fields on {@see GithubReleaseFilter} are optional. Unset
 * fields are ignored. `q` is a free-text search that ORs across
 * tag_name, name, and author_login (LIKE-based).
 */
class GithubReleaseRepository
{
    private const TABLE = 'satisfiend_github_releases';

    public function __construct(
        private readonly Adapter $db,
    ) {}

    /**
     * Insert a new release snapshot row and return its generated
     * `snapshot_id`.
     *
     * @param array<string,mixed> $row Column => value pairs, matching the
     *                                 `satisfiend_github_releases` schema.
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
     * Return the most recently observed snapshot for a given release
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
     * Return the most recently observed snapshot for a repo-scoped tag
     * name or null.
     *
     * @return array<string,mixed>|null
     */
    public function findLatestByTag(string $owner, string $name, string $tagName): ?array
    {
        $query = (new SelectBuilder($this->db))
            ->from(self::TABLE)
            ->where('repository_owner', '=', $owner)
            ->where('repository_name', '=', $name)
            ->where('tag_name', '=', $tagName)
            ->orderBy('observed_at', 'DESC')
            ->limit(1)
            ->build();

        $row = $this->db->selectOne($query->sql, $query->params);

        return $row === false || $row === null ? null : $row;
    }

    /**
     * Return a paginated slice of release snapshots matching the filter,
     * ordered most-recent-first.
     *
     * @return list<array<string,mixed>>
     */
    public function list(GithubReleaseFilter $filter, int $page = 1, int $perPage = 50): array
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
     * Total row count for the filter
     */
    public function count(GithubReleaseFilter $filter): int
    {
        $query = $this->applyFilter(new SelectBuilder($this->db), $filter)
            ->from(self::TABLE)
            ->columns(Expr::count())
            ->build();

        return (int) $this->db->selectValue($query->sql, $query->params);
    }

    private function applyFilter(SelectBuilder $builder, GithubReleaseFilter $filter): SelectBuilder
    {
        if ($filter->repositoryOwner !== null) {
            $builder = $builder->where('repository_owner', '=', $filter->repositoryOwner);
        }
        if ($filter->repositoryName !== null) {
            $builder = $builder->where('repository_name', '=', $filter->repositoryName);
        }
        if ($filter->tagName !== null) {
            $builder = $builder->where('tag_name', '=', $filter->tagName);
        }
        if ($filter->isDraft !== null) {
            $builder = $builder->where('is_draft', '=', $filter->isDraft ? 1 : 0);
        }
        if ($filter->isPrerelease !== null) {
            $builder = $builder->where('is_prerelease', '=', $filter->isPrerelease ? 1 : 0);
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
                $clause->where('tag_name', 'LIKE', $like)
                    ->orWhere('name', 'LIKE', $like)
                    ->orWhere('author_login', 'LIKE', $like);
            });
        }

        return $builder;
    }
}
