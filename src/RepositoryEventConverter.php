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

use Horde\GithubApiClient\GithubRepository;
use InvalidArgumentException;

/**
 * Converts a single `satisfiend_events` row carrying a top-level
 * `repository` payload key into a `satisfiend_gh_repositories` snapshot.
 *
 * Fires for essentially any GitHub webhook event, since almost every
 * payload carries its repository context - which is exactly the point:
 * this is how the table discovers and keeps a history of every
 * repository satisfiend has ever seen mentioned, without needing a
 * dedicated `repository` event.
 *
 * Idempotent per unchanged state: a repository whose payload is
 * unchanged since the last recorded snapshot (matched by node_id, or by
 * full_name when the node_id is missing) is skipped; any actual change
 * (visibility, default branch, star count, …) produces a new snapshot
 * row so the table keeps a full history of observations.
 *
 * {@see GithubRepository} only models owner/name/full_name/description/
 * clone_url/node_id - the identity fields shared across the codebase's
 * other converters. Every other column this table needs (visibility,
 * archived/disabled/template flags, topics, star/fork counts, …) has no
 * client-side type yet, so it is read directly off the decoded payload.
 */
final class RepositoryEventConverter
{
    use GithubWebhookSnapshotHelpers;

    public function __construct(
        private readonly GithubRepositoryRepository $repositories,
    ) {}

    /**
     * @param array<string,mixed> $event A row from `satisfiend_events`
     *                                   (needs at least payload,
     *                                   event_type, action, received_at)
     *                                   - or a synthetic event built by
     *                                   {@see OrganizationRepositoryPoller}
     *                                   from a polled REST response.
     * @param string $origin One of the `api_origin` enum values
     *                       ('webhook', 'rest', 'graphql', 'other').
     *                       `webhook_event`/`webhook_action` are only
     *                       populated for `origin === 'webhook'`, per
     *                       the schema's design notes.
     * @return bool True if a new repository snapshot was inserted; false
     *              if the event carried no `repository` key or nothing
     *              changed since the last recorded snapshot.
     */
    public function convert(array $event, string $origin = 'webhook'): bool
    {
        $decoded = json_decode((string) ($event['payload'] ?? ''));
        if (!is_object($decoded) || !isset($decoded->repository) || !is_object($decoded->repository)) {
            return false;
        }

        $raw = $decoded->repository;
        [$owner, $name, $nodeId] = $this->identity($raw);
        if ($owner === '' && $name === '' && $nodeId === '') {
            return false;
        }

        $bodyJson = json_encode($raw);
        $receivedAt = (string) ($event['received_at'] ?? '');
        // gh_created_at / gh_updated_at are NOT NULL, so fall back to
        // the event's own received_at if GitHub's value is somehow
        // unparseable (see GithubWebhookSnapshotHelpers::toDatetime()).
        $ghUpdatedAt = $this->toDatetime($raw->updated_at ?? null) ?? $receivedAt;

        $latest = $nodeId !== ''
            ? $this->repositories->findLatestByNodeId($nodeId)
            : $this->repositories->findLatestByFullName($this->fullName($owner, $name, $raw));
        if (!$this->isNewByUpdatedAt($latest, $ghUpdatedAt)) {
            return false;
        }

        $license = isset($raw->license) && is_object($raw->license) ? $raw->license : null;

        $this->repositories->insert([
            'github_baseurl' => 'https://github.com',
            'observed_at' => $receivedAt,
            'origin' => $origin,
            'webhook_event' => $origin === 'webhook' ? (string) ($event['event_type'] ?? 'repository') : null,
            'webhook_action' => $origin === 'webhook' ? (string) ($event['action'] ?? '') : null,
            'node_id' => $nodeId,
            'database_id' => $raw->id ?? 0,
            'owner' => $owner,
            'name' => $name,
            'full_name' => $this->fullName($owner, $name, $raw),
            'visibility' => $raw->visibility ?? (($raw->private ?? false) ? 'private' : 'public'),
            'is_private' => ($raw->private ?? false) ? 1 : 0,
            'is_fork' => ($raw->fork ?? false) ? 1 : 0,
            'is_archived' => ($raw->archived ?? false) ? 1 : 0,
            'is_disabled' => ($raw->disabled ?? false) ? 1 : 0,
            'is_template' => ($raw->is_template ?? false) ? 1 : 0,
            'default_branch' => $raw->default_branch ?? null,
            'has_issues' => isset($raw->has_issues) ? ($raw->has_issues ? 1 : 0) : null,
            'has_wiki' => isset($raw->has_wiki) ? ($raw->has_wiki ? 1 : 0) : null,
            'has_projects' => isset($raw->has_projects) ? ($raw->has_projects ? 1 : 0) : null,
            'has_discussions' => isset($raw->has_discussions) ? ($raw->has_discussions ? 1 : 0) : null,
            'has_pages' => isset($raw->has_pages) ? ($raw->has_pages ? 1 : 0) : null,
            'description' => $raw->description ?? null,
            'homepage_url' => $raw->homepage ?? null,
            'primary_language' => $raw->language ?? null,
            'topics' => isset($raw->topics) ? json_encode($raw->topics) : null,
            'license_spdx_id' => $license->spdx_id ?? null,
            'stargazers_count' => $raw->stargazers_count ?? null,
            'watchers_count' => $raw->watchers_count ?? null,
            'forks_count' => $raw->forks_count ?? null,
            'open_issues_count' => $raw->open_issues_count ?? null,
            'disk_usage_kb' => $raw->size ?? null,
            'gh_created_at' => $this->toDatetime($raw->created_at ?? null) ?? $receivedAt,
            'gh_updated_at' => $ghUpdatedAt,
            'gh_pushed_at' => $this->toDatetime($raw->pushed_at ?? null),
            'body_json' => $bodyJson,
        ]);

        return true;
    }

    /**
     * @return array{0:string,1:string,2:string} [owner, name, node_id]
     */
    private function identity(object $raw): array
    {
        $nodeId = (string) ($raw->node_id ?? '');

        try {
            $repo = GithubRepository::fromApiArray((array) $raw);

            return [$repo->owner, $repo->name, $nodeId];
        } catch (InvalidArgumentException) {
            // Some webhook payloads omit clone_url (which
            // fromApiArray() requires); fall back to splitting
            // full_name so we still capture owner/name.
            $fullName = (string) ($raw->full_name ?? '');
            $parts = $fullName !== '' ? explode('/', $fullName, 2) : [];

            return [$parts[0] ?? '', $parts[1] ?? '', $nodeId];
        }
    }

    private function fullName(string $owner, string $name, object $raw): string
    {
        if (isset($raw->full_name) && is_string($raw->full_name) && $raw->full_name !== '') {
            return $raw->full_name;
        }

        return $owner !== '' && $name !== '' ? $owner . '/' . $name : '';
    }
}
