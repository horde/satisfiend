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
use Horde\GithubApiClient\GithubApiClient;
use Horde\GithubApiClient\GithubOrganizationId;

/**
 * Polls the GitHub REST API for every publicly visible repository in an
 * organization and feeds each one through {@see RepositoryEventConverter}
 * as if it were an unwrapped webhook event.
 *
 * {@see GithubApiClient::listRepositoriesInOrganization()} already
 * handles pagination; each entry it returns is a
 * {@see \Horde\GithubApiClient\GithubRepository} built via
 * `fromApiArray()`, which preserves the raw API response as
 * `jsonBody`. That raw JSON is exactly what
 * {@see RepositoryEventConverter} needs (it reads schema columns -
 * visibility, star counts, topics, … - directly off the decoded
 * payload, since `GithubRepository` only models a handful of identity
 * fields), so a polled repository is wrapped as `{"repository": …}`
 * and passed straight through the same converter the webhook path
 * uses, with `origin: 'rest'` instead of `'webhook'`.
 */
final class OrganizationRepositoryPoller
{
    public function __construct(
        private readonly GithubApiClient $client,
        private readonly RepositoryEventConverter $converter,
    ) {}

    /**
     * @return array{scanned:int,inserted:int}
     */
    public function poll(string $org): array
    {
        $repositories = $this->client->listRepositoriesInOrganization(new GithubOrganizationId($org));
        $observedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $scanned = 0;
        $inserted = 0;

        foreach ($repositories as $repository) {
            $scanned++;
            $jsonBody = $repository->getJsonBody();
            if ($jsonBody === null) {
                // Every entry from listRepositoriesInOrganization() is
                // built via fromApiArray(), which always sets
                // jsonBody; this is just defensive.
                continue;
            }

            $event = [
                'event_type' => 'poll',
                'action' => 'organization_repositories',
                'payload' => json_encode(['repository' => json_decode($jsonBody)]),
                'received_at' => $observedAt,
            ];

            if ($this->converter->convert($event, origin: 'rest')) {
                $inserted++;
            }
        }

        return ['scanned' => $scanned, 'inserted' => $inserted];
    }
}
