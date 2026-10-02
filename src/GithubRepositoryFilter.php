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

/**
 * Immutable filter passed to {@see GithubRepositoryRepository::list()} and
 * {@see GithubRepositoryRepository::count()}.
 *
 * Every field is optional. Null (or empty string on scalar fields) means
 * "no constraint on this column". The repository skips the clause
 * entirely. `q` is a free-text search that ORs across owner, name, and
 * full_name (LIKE-based).
 */
final readonly class GithubRepositoryFilter
{
    public function __construct(
        public ?string $owner = null,
        public ?string $name = null,
        public ?string $visibility = null,
        public ?bool $isPrivate = null,
        public ?bool $isArchived = null,
        public ?string $origin = null,
        public ?string $since = null,
        public ?string $until = null,
        public ?string $q = null,
    ) {}

    /**
     * Build a filter from a PSR-7 query-param array. Empty strings and
     * missing keys both mean "no constraint". Boolean fields accept
     * `0`/`1`/`true`/`false`; anything else is ignored.
     *
     * @param array<string,mixed> $params
     */
    public static function fromQueryParams(array $params): self
    {
        $trim = static function ($value): ?string {
            if (!is_string($value)) {
                return null;
            }
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        };

        $bool = static function ($value): ?bool {
            if (!is_string($value)) {
                return null;
            }
            $raw = strtolower(trim($value));
            if (in_array($raw, ['1', 'true', 'yes'], true)) {
                return true;
            }
            if (in_array($raw, ['0', 'false', 'no'], true)) {
                return false;
            }
            return null;
        };

        return new self(
            owner: $trim($params['owner'] ?? null),
            name: $trim($params['name'] ?? null),
            visibility: $trim($params['visibility'] ?? null),
            isPrivate: $bool($params['is_private'] ?? null),
            isArchived: $bool($params['is_archived'] ?? null),
            origin: $trim($params['origin'] ?? null),
            since: $trim($params['since'] ?? null),
            until: $trim($params['until'] ?? null),
            q: $trim($params['q'] ?? null),
        );
    }

    /**
     * Emit the filter as a query-param array suitable for building
     * paginated links from the current view without losing filters.
     *
     * @return array<string,string>
     */
    public function toQueryParams(): array
    {
        $out = [];
        if ($this->owner !== null) {
            $out['owner'] = $this->owner;
        }
        if ($this->name !== null) {
            $out['name'] = $this->name;
        }
        if ($this->visibility !== null) {
            $out['visibility'] = $this->visibility;
        }
        if ($this->isPrivate !== null) {
            $out['is_private'] = $this->isPrivate ? '1' : '0';
        }
        if ($this->isArchived !== null) {
            $out['is_archived'] = $this->isArchived ? '1' : '0';
        }
        if ($this->origin !== null) {
            $out['origin'] = $this->origin;
        }
        if ($this->since !== null) {
            $out['since'] = $this->since;
        }
        if ($this->until !== null) {
            $out['until'] = $this->until;
        }
        if ($this->q !== null) {
            $out['q'] = $this->q;
        }

        return $out;
    }
}
