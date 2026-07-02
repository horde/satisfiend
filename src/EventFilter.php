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
 * Immutable filter passed to {@see EventRepository::list()} and
 * {@see EventRepository::count()}.
 *
 * Every field is optional. Null (or empty string on scalar fields)
 * means "no constraint on this column"; the repository skips the
 * clause entirely.
 *
 * {@see fromQueryParams()} is the canonical adapter from PSR-7 query
 * params (`$request->getQueryParams()`) to an instance of this class;
 * the web controllers and JSON API both go through it so they honour
 * exactly the same filter grammar.
 */
final readonly class EventFilter
{
    public function __construct(
        public ?string $slug = null,
        public ?string $eventType = null,
        public ?string $action = null,
        public ?string $repository = null,
        public ?string $actor = null,
        public ?string $status = null,
        public ?bool $debug = null,
        public ?string $since = null,
        public ?string $until = null,
        public ?string $q = null,
    ) {}

    /**
     * Build a filter from a PSR-7 query-param array. Empty strings and
     * missing keys both mean "no constraint". `debug` accepts
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

        $debug = null;
        if (isset($params['debug']) && is_string($params['debug'])) {
            $raw = strtolower(trim($params['debug']));
            if (in_array($raw, ['1', 'true', 'yes'], true)) {
                $debug = true;
            } elseif (in_array($raw, ['0', 'false', 'no'], true)) {
                $debug = false;
            }
        }

        return new self(
            slug: $trim($params['slug'] ?? null),
            eventType: $trim($params['event_type'] ?? null),
            action: $trim($params['action'] ?? null),
            repository: $trim($params['repository'] ?? null),
            actor: $trim($params['actor'] ?? null),
            status: $trim($params['status'] ?? null),
            debug: $debug,
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
        if ($this->slug !== null) {
            $out['slug'] = $this->slug;
        }
        if ($this->eventType !== null) {
            $out['event_type'] = $this->eventType;
        }
        if ($this->action !== null) {
            $out['action'] = $this->action;
        }
        if ($this->repository !== null) {
            $out['repository'] = $this->repository;
        }
        if ($this->actor !== null) {
            $out['actor'] = $this->actor;
        }
        if ($this->status !== null) {
            $out['status'] = $this->status;
        }
        if ($this->debug !== null) {
            $out['debug'] = $this->debug ? '1' : '0';
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
