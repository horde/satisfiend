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

use Horde\GithubApiClient\GithubRelease;
use Horde\GithubApiClient\GithubReleaseAsset;

/**
 * Converts a single `satisfiend_events` row carrying a top-level
 * `release` payload key into a `satisfiend_github_releases` snapshot.
 *
 * Used by `satisfiend-cli convert-events` to backfill the release
 * snapshot table from event history already stored by
 * {@see Listener\PersistEventListener}. The table stores a full history
 * of observations, so every event whose release payload actually
 * changed since the last recorded snapshot (matched by node_id, or by
 * owner/name/tag when the node_id is missing) produces a new row;
 * repeats of an already-recorded state are skipped.
 *
 * Payload objects are decoded with `json_decode($json)` (object mode)
 * and handed to {@see GithubRelease::fromApiResponse()} rather than
 * picked apart by hand. That buys typed, named properties
 * (`$release->tagName`, `$release->author->login`, …) with GitHub's own
 * field-default handling, instead of repeating
 * `$decoded->release->tag_name ?? ''` throughout this class.
 */
final class ReleaseEventConverter
{
    use GithubWebhookSnapshotHelpers;

    public function __construct(
        private readonly GithubReleaseRepository $releases,
    ) {}

    /**
     * @param array<string,mixed> $event A row from `satisfiend_events`
     *                                   (needs at least payload,
     *                                   event_type, action, received_at).
     * @return bool True if a new release snapshot was inserted; false if
     *              the event carried no `release` key or nothing changed
     *              since the last recorded snapshot for that release.
     */
    public function convert(array $event): bool
    {
        $decoded = json_decode((string) ($event['payload'] ?? ''));
        if (!is_object($decoded) || !isset($decoded->release) || !is_object($decoded->release)) {
            return false;
        }

        $release = GithubRelease::fromApiResponse($decoded->release);
        [$owner, $name, $repositoryNodeId] = $this->extractRepository($decoded);

        $bodyJson = json_encode($decoded->release);
        $latest = $this->findLatest($release, $owner, $name);
        // Releases have no gh_updated_at column, so change detection
        // falls back to a normalized body hash comparison.
        if (!$this->isNewByBodyHash($latest, $bodyJson)) {
            return false;
        }

        $this->releases->insert([
            'observed_at' => (string) ($event['received_at'] ?? ''),
            'origin' => 'webhook',
            'webhook_event' => (string) ($event['event_type'] ?? 'release'),
            'webhook_action' => (string) ($event['action'] ?? ''),
            'node_id' => $release->nodeId,
            'database_id' => $release->id,
            'repository_node_id' => $repositoryNodeId,
            'repository_owner' => $owner,
            'repository_name' => $name,
            'tag_name' => $release->tagName,
            'name' => $release->name,
            'is_draft' => $release->draft ? 1 : 0,
            'is_prerelease' => $release->prerelease ? 1 : 0,
            'is_latest' => null,
            'target_commitish' => $decoded->release->target_commitish ?? null,
            'author_login' => $release->author->login,
            'author_node_id' => $release->author->nodeId,
            'assets_count' => count($release->assets),
            'download_count' => array_sum(array_map(
                static fn(GithubReleaseAsset $asset): int => $asset->downloadCount,
                $release->assets,
            )),
            // gh_created_at is NOT NULL, so fall back to the event's
            // own received_at if GitHub's value is somehow unparseable
            // (see GithubWebhookSnapshotHelpers::toDatetime()).
            'gh_created_at' => $this->toDatetime($release->createdAt)
                ?? (string) ($event['received_at'] ?? ''),
            'gh_published_at' => $this->toDatetime($release->publishedAt),
            'body_json' => $bodyJson,
        ]);

        return true;
    }

    /**
     * @return array<string,mixed>|null The most recently recorded
     *                                   snapshot for this release, or
     *                                   null if none exists yet.
     */
    private function findLatest(GithubRelease $release, string $owner, string $name): ?array
    {
        if ($release->nodeId !== '') {
            return $this->releases->findLatestByNodeId($release->nodeId);
        }

        if ($owner !== '' && $name !== '' && $release->tagName !== '') {
            return $this->releases->findLatestByTag($owner, $name, $release->tagName);
        }

        // No stable identifier at all - treat as unknown so we do not
        // silently drop it.
        return null;
    }
}
