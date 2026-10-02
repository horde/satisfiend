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

use DateTimeImmutable;
use Exception;
use Horde\GithubApiClient\GithubRepository;
use InvalidArgumentException;

/**
 * Shared helpers for the `*EventConverter` classes that turn a decoded
 * `satisfiend_events.payload` into a snapshot row for one of the
 * `satisfiend_gh_*` tables.
 *
 * Factored out of {@see ReleaseEventConverter} once
 * {@see IssueEventConverter}, {@see PullRequestEventConverter} and
 * {@see RepositoryEventConverter} needed the exact same two bits of
 * logic: pulling `owner`/`name`/`node_id` out of a webhook payload's
 * top-level `repository` object, and normalizing GitHub's ISO 8601
 * timestamps into the `Y-m-d H:i:s` form the schema's `datetime`
 * columns require.
 */
trait GithubWebhookSnapshotHelpers
{
    /**
     * @return array{0:string,1:string,2:string} [owner, name, repository_node_id]
     */
    private function extractRepository(object $decoded): array
    {
        if (!isset($decoded->repository) || !is_object($decoded->repository)) {
            return ['', '', ''];
        }

        $repoData = $decoded->repository;
        $nodeId = (string) ($repoData->node_id ?? '');

        try {
            $repo = GithubRepository::fromApiArray((array) $repoData);

            return [$repo->owner, $repo->name, $nodeId];
        } catch (InvalidArgumentException) {
            // Some webhook payloads omit clone_url (which
            // fromApiArray() requires); fall back to splitting
            // full_name so we still capture owner/name.
            $fullName = (string) ($repoData->full_name ?? '');
            $parts = $fullName !== '' ? explode('/', $fullName, 2) : [];

            return [$parts[0] ?? '', $parts[1] ?? '', $nodeId];
        }
    }

    /**
     * Normalize a GitHub ISO 8601 timestamp ('2026-07-04T06:09:18Z') to
     * the 'Y-m-d H:i:s' form the schema's `datetime` columns expect.
     * Returns null for an empty or unparseable input.
     */
    private function toDatetime(int|string|null $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        try {
            // The `push` webhook event (and some other legacy payload
            // shapes) represents repository.pushed_at as a Unix
            // timestamp integer rather than an ISO 8601 string.
            $dt = is_int($iso) ? new DateTimeImmutable('@' . $iso) : new DateTimeImmutable($iso);

            return $dt->format('Y-m-d H:i:s');
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Whether $updatedAt represents object state not yet captured by
     * $latest. Used by tables carrying a `gh_updated_at` column
     * (issues, pull requests, repositories): GitHub bumps that
     * timestamp on essentially any state change, so comparing it is a
     * cheap and reliable stand-in for a full body comparison, and it
     * is exactly the identity pair - node_id + updated_at - the caller
     * already looked `$latest` up by.
     *
     * @param array<string,mixed>|null $latest The most recently
     *                                          recorded snapshot row,
     *                                          or null if none exists.
     */
    private function isNewByUpdatedAt(?array $latest, string $updatedAt): bool
    {
        if ($latest === null) {
            return true;
        }

        return (string) ($latest['gh_updated_at'] ?? '') !== $updatedAt;
    }

    /**
     * Whether $bodyJson represents object state not yet captured by
     * $latest. Fallback for tables with no `gh_updated_at` column
     * (currently only releases): compares a normalized (key-sorted)
     * hash of the decoded payload rather than the raw JSON string, so
     * harmless differences in key order between two observations of an
     * otherwise-identical object do not produce spurious "changed"
     * snapshots.
     *
     * @param array<string,mixed>|null $latest The most recently
     *                                          recorded snapshot row,
     *                                          or null if none exists.
     */
    private function isNewByBodyHash(?array $latest, string $bodyJson): bool
    {
        if ($latest === null) {
            return true;
        }

        return $this->normalizedBodyHash((string) ($latest['body_json'] ?? ''))
            !== $this->normalizedBodyHash($bodyJson);
    }

    private function normalizedBodyHash(string $json): string
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return hash('sha256', $json);
        }

        $this->ksortRecursive($decoded);

        return hash('sha256', (string) json_encode($decoded));
    }

    /**
     * @param array<array-key,mixed> $data
     */
    private function ksortRecursive(array &$data): void
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }
}
