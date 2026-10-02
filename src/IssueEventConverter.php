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

use Horde\GithubApiClient\GithubIssue;
use Horde\GithubApiClient\GithubIssueFactory;
use Horde\GithubApiClient\GithubLabel;
use Horde\GithubApiClient\GithubUser;

/**
 * Converts a single `satisfiend_events` row carrying a top-level `issue`
 * payload key into a `satisfiend_gh_issues` snapshot.
 *
 * Fires for any event whose payload has a genuine issue at the top
 * level - not just the `issues` event type, but also e.g.
 * `issue_comment`, which carries the issue's current state alongside
 * the comment. Payloads whose `issue.pull_request` is set are skipped:
 * those are pull requests observed through an issue-shaped context
 * (comments, labels, …) and belong in `satisfiend_gh_pull_requests`
 * instead - {@see PullRequestEventConverter} picks them up from the
 * `pull_request` event's own top-level `pull_request` key, which
 * carries the fuller PR object this table needs (head/base refs,
 * mergeability, …).
 *
 * Idempotent per unchanged state: an issue whose payload is unchanged
 * since the last recorded snapshot (matched by node_id, or by
 * owner/name/number when the node_id is missing) is skipped; any actual
 * change (state, labels, title, …) produces a new snapshot row so the
 * table keeps a full history of observations.
 *
 * {@see GithubIssue} typed properties do most of the field mapping
 * here (author, labels, assignees, milestone, isPullRequest, …); a
 * handful of fields the type does not carry (locked/pinned/reactions)
 * are still read directly off the decoded payload.
 */
final class IssueEventConverter
{
    use GithubWebhookSnapshotHelpers;

    public function __construct(
        private readonly GithubIssueRepository $issues,
    ) {}

    /**
     * @param array<string,mixed> $event A row from `satisfiend_events`
     *                                   (needs at least payload,
     *                                   event_type, action, received_at).
     * @return bool True if a new issue snapshot was inserted; false if
     *              the event carried no genuine `issue` key or nothing
     *              changed since the last recorded snapshot.
     */
    public function convert(array $event): bool
    {
        $decoded = json_decode((string) ($event['payload'] ?? ''));
        if (!is_object($decoded) || !isset($decoded->issue) || !is_object($decoded->issue)) {
            return false;
        }

        $issue = (new GithubIssueFactory())->createFromApiResponse($decoded->issue);
        if ($issue->isPullRequest) {
            return false;
        }

        [$owner, $name, $repositoryNodeId] = $this->extractRepository($decoded);

        $bodyJson = json_encode($decoded->issue);
        $receivedAt = (string) ($event['received_at'] ?? '');
        // gh_created_at / gh_updated_at are NOT NULL, so fall back to
        // the event's own received_at if GitHub's value is somehow
        // unparseable (see GithubWebhookSnapshotHelpers::toDatetime()).
        $ghUpdatedAt = $this->toDatetime($issue->updatedAt) ?? $receivedAt;

        $latest = $this->findLatest($issue, $owner, $name);
        if (!$this->isNewByUpdatedAt($latest, $ghUpdatedAt)) {
            return false;
        }

        $this->issues->insert([
            'observed_at' => $receivedAt,
            'origin' => 'webhook',
            'webhook_event' => (string) ($event['event_type'] ?? 'issues'),
            'webhook_action' => (string) ($event['action'] ?? ''),
            'node_id' => $issue->nodeId,
            'database_id' => $issue->id,
            'repository_node_id' => $repositoryNodeId,
            'repository_owner' => $owner,
            'repository_name' => $name,
            'number' => $issue->number,
            'state' => strtoupper($issue->state),
            'state_reason' => $issue->stateReason !== null ? strtoupper($issue->stateReason) : null,
            'title' => $issue->title,
            'author_login' => $issue->author->login,
            'author_node_id' => $issue->author->nodeId,
            'assignees' => json_encode(array_map(
                static fn(GithubUser $user): array => ['login' => $user->login, 'node_id' => $user->nodeId],
                $issue->assignees,
            )),
            'labels' => json_encode(array_map(
                static fn(GithubLabel $label): array => ['name' => $label->name, 'color' => $label->color],
                $issue->labels,
            )),
            'milestone_number' => $issue->milestone?->number,
            'milestone_node_id' => $issue->milestone?->nodeId,
            'milestone_title' => $issue->milestone?->title,
            'is_locked' => ($decoded->issue->locked ?? false) ? 1 : 0,
            'is_pinned' => isset($decoded->issue->pinned) ? ($decoded->issue->pinned ? 1 : 0) : null,
            'comments_count' => $issue->comments,
            'reactions_count' => $decoded->issue->reactions->total_count ?? null,
            'gh_created_at' => $this->toDatetime($issue->createdAt) ?? $receivedAt,
            'gh_updated_at' => $ghUpdatedAt,
            'gh_closed_at' => $this->toDatetime($issue->closedAt),
            'body_json' => $bodyJson,
        ]);

        return true;
    }

    /**
     * @return array<string,mixed>|null The most recently recorded
     *                                   snapshot for this issue, or null
     *                                   if none exists yet.
     */
    private function findLatest(GithubIssue $issue, string $owner, string $name): ?array
    {
        if ($issue->nodeId !== '') {
            return $this->issues->findLatestByNodeId($issue->nodeId);
        }

        if ($owner !== '' && $name !== '') {
            return $this->issues->findLatestByNumber($owner, $name, $issue->number);
        }

        // No stable identifier at all - treat as unknown so we do not
        // silently drop it.
        return null;
    }
}
