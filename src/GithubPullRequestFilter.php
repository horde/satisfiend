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
 * Immutable filter passed to {@see GithubPullRequestRepository::list()}
 * and {@see GithubPullRequestRepository::count()}.
 *
 * Every field is optional. Null (or empty string on scalar fields) means
 * "no constraint on this column"; the repository skips the clause
 * entirely. `q` is a free-text search that ORs across title and
 * author_login (LIKE-based).
 */
final readonly class GithubPullRequestFilter
{
    public function __construct(
        public ?string $repositoryOwner = null,
        public ?string $repositoryName = null,
        public ?int $number = null,
        public ?string $state = null,
        public ?bool $isDraft = null,
        public ?bool $merged = null,
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

        $number = null;
        if (isset($params['number']) && is_string($params['number']) && ctype_digit($params['number'])) {
            $number = (int) $params['number'];
        }

        return new self(
            repositoryOwner: $trim($params['repository_owner'] ?? null),
            repositoryName: $trim($params['repository_name'] ?? null),
            number: $number,
            state: $trim($params['state'] ?? null),
            isDraft: $bool($params['is_draft'] ?? null),
            merged: $bool($params['merged'] ?? null),
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
        if ($this->number !== null) {
            $out['number'] = (string) $this->number;
        }
        if ($this->state !== null) {
            $out['state'] = $this->state;
        }
        if ($this->isDraft !== null) {
            $out['is_draft'] = $this->isDraft ? '1' : '0';
        }
        if ($this->merged !== null) {
            $out['merged'] = $this->merged ? '1' : '0';
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
