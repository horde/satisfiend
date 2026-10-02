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

use Horde\GithubApiClient\GithubMilestone;
use Horde\GithubApiClient\GithubPullRequest;

/**
 * Converts a single `satisfiend_events` row carrying a top-level
 * `pull_request` payload key into a `satisfiend_gh_pull_requests`
 * snapshot.
 *
 * Fires for any event whose payload has a `pull_request` object at the
 * top level - the `pull_request` event itself as well as
 * `pull_request_review` and `pull_request_review_comment`, all of which
 * carry the PR's current state alongside the review activity.
 *
 * Idempotent per unchanged state: a PR whose payload is unchanged since
 * the last recorded snapshot (matched by node_id, or by owner/name/number
 * when the node_id is missing) is skipped; any actual change (state,
 * mergeability, review decision, …) produces a new snapshot row so the
 * table keeps a full history of observations.
 *
 * {@see GithubPullRequest} typed properties cover the bulk of the
 * mapping (title, author, labels, requested reviewers, branch names,
 * mergeable state, …). A handful of fields the type does not carry
 * (head/base SHA, merged_by, assignees, milestone, lock/diff/comment
 * counters) are read directly off the decoded payload instead - GitHub
 * simply has no client-side type for them yet.
 */
final class PullRequestEventConverter
{
    use GithubWebhookSnapshotHelpers;

    public function __construct(
        private readonly GithubPullRequestRepository $pullRequests,
    ) {}

    /**
     * @param array<string,mixed> $event A row from `satisfiend_events`
     *                                   (needs at least payload,
     *                                   event_type, action, received_at).
     * @return bool True if a new PR snapshot was inserted; false if the
     *              event carried no `pull_request` key or nothing
     *              changed since the last recorded snapshot.
     */
    public function convert(array $event): bool
    {
        $decoded = json_decode((string) ($event['payload'] ?? ''));
        if (!is_object($decoded) || !isset($decoded->pull_request) || !is_object($decoded->pull_request)) {
            return false;
        }

        $raw = $decoded->pull_request;
        $pr = GithubPullRequest::fromApiResponse($raw);
        [$owner, $name, $repositoryNodeId] = $this->extractRepository($decoded);

        $bodyJson = json_encode($raw);
        $receivedAt = (string) ($event['received_at'] ?? '');
        // gh_created_at / gh_updated_at are NOT NULL, so fall back to
        // the event's own received_at if GitHub's value is somehow
        // unparseable (see GithubWebhookSnapshotHelpers::toDatetime()).
        $ghUpdatedAt = $this->toDatetime($pr->updatedAt) ?? $receivedAt;

        $latest = $this->findLatest($pr, $owner, $name);
        if (!$this->isNewByUpdatedAt($latest, $ghUpdatedAt)) {
            return false;
        }

        $milestone = isset($raw->milestone) && is_object($raw->milestone)
            ? GithubMilestone::fromApiResponse($raw->milestone)
            : null;
        $mergedBy = isset($raw->merged_by) && is_object($raw->merged_by) ? $raw->merged_by : null;

        $this->pullRequests->insert([
            'observed_at' => $receivedAt,
            'origin' => 'webhook',
            'webhook_event' => (string) ($event['event_type'] ?? 'pull_request'),
            'webhook_action' => (string) ($event['action'] ?? ''),
            'node_id' => $pr->nodeId,
            'database_id' => $raw->id ?? 0,
            'repository_node_id' => $repositoryNodeId,
            'repository_owner' => $owner,
            'repository_name' => $name,
            'number' => $pr->number,
            'state' => $pr->merged ? 'MERGED' : strtoupper($pr->state),
            'title' => $pr->title,
            'is_draft' => $pr->draft ? 1 : 0,
            'merged' => $pr->merged ? 1 : 0,
            'mergeable' => $pr->mergeableState !== null ? strtoupper($pr->mergeableState) : null,
            'review_decision' => null,
            'author_login' => $pr->author->login,
            'author_node_id' => $pr->author->nodeId,
            'merged_by_login' => $mergedBy->login ?? null,
            'merged_by_node_id' => $mergedBy->node_id ?? null,
            'head_ref' => $pr->headBranch,
            'head_sha' => $raw->head->sha ?? null,
            'base_ref' => $pr->baseBranch,
            'base_sha' => $raw->base->sha ?? null,
            'assignees' => json_encode(array_map(
                static fn(object $user): array => [
                    'login' => $user->login ?? '',
                    'node_id' => $user->node_id ?? '',
                ],
                $raw->assignees ?? [],
            )),
            'labels' => json_encode(array_map(
                static fn($label): array => ['name' => $label->name, 'color' => $label->color],
                $pr->labels,
            )),
            'requested_reviewers' => json_encode(array_map(
                static fn(object $user): array => [
                    'login' => $user->login,
                    'node_id' => $user->nodeId,
                    'type' => 'User',
                ],
                $pr->requestedReviewers,
            )),
            'milestone_number' => $milestone?->number,
            'milestone_node_id' => $milestone?->nodeId,
            'milestone_title' => $milestone?->title,
            'is_locked' => ($raw->locked ?? false) ? 1 : 0,
            'maintainer_can_modify' => isset($raw->maintainer_can_modify) ? ($raw->maintainer_can_modify ? 1 : 0) : null,
            'commits_count' => $raw->commits ?? null,
            'changed_files_count' => $raw->changed_files ?? null,
            'additions' => $raw->additions ?? null,
            'deletions' => $raw->deletions ?? null,
            'comments_count' => $raw->comments ?? null,
            'review_comments_count' => $raw->review_comments ?? null,
            'reactions_count' => $raw->reactions->total_count ?? null,
            'gh_created_at' => $this->toDatetime($pr->createdAt) ?? $receivedAt,
            'gh_updated_at' => $ghUpdatedAt,
            'gh_closed_at' => $this->toDatetime($raw->closed_at ?? null),
            'gh_merged_at' => $this->toDatetime($pr->mergedAt),
            'body_json' => $bodyJson,
        ]);

        return true;
    }

    /**
     * @return array<string,mixed>|null The most recently recorded
     *                                   snapshot for this PR, or null if
     *                                   none exists yet.
     */
    private function findLatest(GithubPullRequest $pr, string $owner, string $name): ?array
    {
        if ($pr->nodeId !== '') {
            return $this->pullRequests->findLatestByNodeId($pr->nodeId);
        }

        if ($owner !== '' && $name !== '') {
            return $this->pullRequests->findLatestByNumber($owner, $name, $pr->number);
        }

        // No stable identifier at all - treat as unknown so we do not
        // silently drop it.
        return null;
    }
}
