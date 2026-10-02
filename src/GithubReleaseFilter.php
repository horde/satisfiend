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
 * Immutable filter passed to {@see GithubReleaseRepository::list()} and
 * {@see GithubReleaseRepository::count()}.
 *
 * Every field is optional. Null (or empty string on scalar fields) means
 * "no constraint on this column". The repository skips the clause
 * entirely. `q` is a free-text search that ORs across tag_name, name, and
 * author_login (LIKE-based).
 */
final readonly class GithubReleaseFilter
{
    public function __construct(
        public ?string $repositoryOwner = null,
        public ?string $repositoryName = null,
        public ?string $tagName = null,
        public ?bool $isDraft = null,
        public ?bool $isPrerelease = null,
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
            repositoryOwner: $trim($params['repository_owner'] ?? null),
            repositoryName: $trim($params['repository_name'] ?? null),
            tagName: $trim($params['tag_name'] ?? null),
            isDraft: $bool($params['is_draft'] ?? null),
            isPrerelease: $bool($params['is_prerelease'] ?? null),
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
        if ($this->repositoryOwner !== null) {
            $out['repository_owner'] = $this->repositoryOwner;
        }
        if ($this->repositoryName !== null) {
            $out['repository_name'] = $this->repositoryName;
        }
        if ($this->tagName !== null) {
            $out['tag_name'] = $this->tagName;
        }
        if ($this->isDraft !== null) {
            $out['is_draft'] = $this->isDraft ? '1' : '0';
        }
        if ($this->isPrerelease !== null) {
            $out['is_prerelease'] = $this->isPrerelease ? '1' : '0';
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
