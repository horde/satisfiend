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

use Horde\Db\Adapter;
use Horde\GithubApiClient\GithubApiClient;
use Horde\GithubApiClient\GithubApiConfig;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;
use InvalidArgumentException;

/**
 * Command dispatcher for `bin/satisfiend-cli`.
 *
 * Kept as a small, testable class rather than a monolithic script so
 * argv parsing and business logic can be exercised without booting the
 * whole Horde registry from inside a test.
 *
 * Subcommands:
 *   debug inject     In-process synthesised event (bypasses HTTP + HMAC).
 *   debug send       Signed HTTP POST to a local webhook endpoint.
 *   convert-events   Backfill satisfiend_gh_issues, satisfiend_gh_pull_requests,
 *                    satisfiend_gh_repositories and satisfiend_github_releases
 *                    from event history.
 *   poll-repositories
 *                    Poll the GitHub REST API for every publicly visible
 *                    repository in an organization and snapshot the new
 *                    or changed ones into satisfiend_gh_repositories.
 *   help             Print usage.
 */
class Cli
{
    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        private $stdout,
        private $stderr,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly Adapter $db,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly GithubReleaseRepository $releaseRepository,
        private readonly GithubIssueRepository $issueRepository,
        private readonly GithubPullRequestRepository $pullRequestRepository,
        private readonly GithubRepositoryRepository $repositoryRepository,
    ) {}

    /**
     * @param list<string> $argv Full argv, including the script name at index 0.
     */
    public function run(array $argv): int
    {
        $args = array_slice($argv, 1);

        if ($args === [] || in_array($args[0], ['help', '-h', '--help'], true)) {
            $this->printUsage();

            return 0;
        }

        if ($args[0] === 'convert-events') {
            try {
                return $this->cmdConvertEvents($this->parseOptions(array_slice($args, 1)));
            } catch (Throwable $e) {
                $this->err('Error: ' . $e->getMessage());

                return 1;
            }
        }

        if ($args[0] === 'poll-repositories') {
            $org = $args[1] ?? '';
            if ($org === '') {
                $this->err('Usage: satisfiend-cli poll-repositories ORG [--token=TOKEN]');

                return 2;
            }
            try {
                return $this->cmdPollRepositories($org, $this->parseOptions(array_slice($args, 2)));
            } catch (Throwable $e) {
                $this->err('Error: ' . $e->getMessage());

                return 1;
            }
        }

        if ($args[0] !== 'debug') {
            $this->err(sprintf('Unknown command: %s', $args[0]));
            $this->printUsage();

            return 2;
        }

        $sub = $args[1] ?? '';
        $rest = array_slice($args, 2);

        try {
            return match ($sub) {
                'inject' => $this->cmdInject($this->parseOptions($rest)),
                'send' => $this->cmdSend($this->parseOptions($rest)),
                default => $this->unknownDebugSubcommand($sub),
            };
        } catch (Throwable $e) {
            $this->err('Error: ' . $e->getMessage());

            return 1;
        }
    }

    /**
     * @param array<string, string> $opts
     */
    private function cmdInject(array $opts): int
    {
        $missing = $this->missing($opts, ['slug', 'event-type', 'fixture']);
        if ($missing !== []) {
            $this->err('Missing required option(s): ' . implode(', ', $missing));
            $this->err('Usage: satisfiend-cli debug inject --slug=SLUG --event-type=TYPE --fixture=PATH [--action=ACTION] [--provider=PROVIDER] [--delivery-id=ID]');

            return 2;
        }

        $service = new DebugInjectionService($this->dispatcher);
        $event = $service->inject(
            slug: $opts['slug'],
            providerType: $opts['provider'] ?? 'github',
            eventType: $opts['event-type'],
            fixturePath: $opts['fixture'],
            actionOverride: $opts['action'] ?? null,
            deliveryId: $opts['delivery-id'] ?? '',
        );

        $this->out(sprintf(
            'Injected: slug=%s event_type=%s action=%s repo=%s delivery_id=%s debug=true',
            $event->slug,
            $event->eventType,
            $event->action === '' ? '-' : $event->action,
            $event->repository === '' ? '-' : $event->repository,
            $event->deliveryId,
        ));

        return 0;
    }

    /**
     * @param array<string, string> $opts
     */
    private function cmdSend(array $opts): int
    {
        $missing = $this->missing($opts, ['slug', 'event-type', 'fixture']);
        if ($missing !== []) {
            $this->err('Missing required option(s): ' . implode(', ', $missing));
            $this->err('Usage: satisfiend-cli debug send --slug=SLUG --event-type=TYPE --fixture=PATH [--url=URL] [--secret=SECRET] [--delivery-id=ID]');

            return 2;
        }

        $secret = $opts['secret'] ?? $this->lookupSecret($opts['slug']);
        if ($secret === null) {
            $this->err(sprintf(
                'No secret supplied and endpoint %s not found in satisfiend_endpoints. Pass --secret=… explicitly.',
                $opts['slug']
            ));

            return 2;
        }

        $url = $opts['url'] ?? sprintf('http://localhost/satisfiend/webhook/%s', $opts['slug']);

        $sender = new DebugHttpSender(
            $this->httpClient,
            $this->requestFactory,
            $this->streamFactory,
        );

        $response = $sender->send(
            url: $url,
            secret: $secret,
            eventType: $opts['event-type'],
            fixturePath: $opts['fixture'],
            deliveryId: $opts['delivery-id'] ?? null,
        );

        $this->out(sprintf('POST %s -> %d %s', $url, $response->getStatusCode(), $response->getReasonPhrase()));
        $bodyPreview = (string) $response->getBody();
        if ($bodyPreview !== '') {
            $this->out('Body: ' . $bodyPreview);
        }

        return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300 ? 0 : 1;
    }

    /**
     * Pages through `satisfiend_events` oldest-to-newest (keyset
     * pagination on `event_id` and runs every converter over each row. Each converter
     * independently checks for its own top-level payload key
     * (`release`, `issue`, `pull_request`, `repository`) and inserts a
     * new snapshot only if the entity is new or has changed since the
     * last recorded observation.
     *
     * @param array<string, string> $opts
     */
    private function cmdConvertEvents(array $opts): int
    {
        $batchSize = isset($opts['batch-size']) ? max(1, (int) $opts['batch-size']) : 500;
        $converters = [
            'release' => new ReleaseEventConverter($this->releaseRepository),
            'issue' => new IssueEventConverter($this->issueRepository),
            'pull request' => new PullRequestEventConverter($this->pullRequestRepository),
            'repository' => new RepositoryEventConverter($this->repositoryRepository),
        ];

        $lastId = 0;
        $scanned = 0;
        $inserted = array_fill_keys(array_keys($converters), 0);

        while (true) {
            $rows = $this->db->selectAll(
                'SELECT event_id, event_type, action, payload, received_at'
                    . ' FROM satisfiend_events WHERE event_id > ? ORDER BY event_id ASC LIMIT ' . $batchSize,
                [$lastId]
            );
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $scanned++;
                $lastId = (int) $row['event_id'];
                foreach ($converters as $label => $converter) {
                    if ($converter->convert($row)) {
                        $inserted[$label]++;
                    }
                }
            }
        }

        $this->out(sprintf(
            'Scanned %d event(s), inserted %d release, %d issue, %d pull request, %d repository snapshot(s).',
            $scanned,
            $inserted['release'],
            $inserted['issue'],
            $inserted['pull request'],
            $inserted['repository'],
        ));

        return 0;
    }

    /**
     * Polls the GitHub REST API for every publicly visible repository
     * in an organization via {@see OrganizationRepositoryPoller} and
     * records a snapshot for each repository that is new or has changed since
     * the last recorded observation
     *
     * This should gracefully interact with repositories unwrapped from webhook events.
     *
     * @param array<string, string> $opts
     */
    private function cmdPollRepositories(string $org, array $opts): int
    {
        // TODO: Let a factory handle this and potentially support Github Apps authentication with short-lived tokens
        $config = new GithubApiConfig(accessToken: $opts['token'] ?? '');
        $client = new GithubApiClient($this->httpClient, $this->requestFactory, $config, $this->streamFactory);
        $poller = new OrganizationRepositoryPoller(
            $client,
            new RepositoryEventConverter($this->repositoryRepository),
        );

        $result = $poller->poll($org);

        $this->out(sprintf(
            'Scanned %d repository(ies) in %s, inserted %d snapshot(s).',
            $result['scanned'],
            $org,
            $result['inserted'],
        ));

        return 0;
    }

    private function unknownDebugSubcommand(string $sub): int
    {
        if ($sub === '') {
            $this->err('debug: missing subcommand');
        } else {
            $this->err(sprintf('debug: unknown subcommand: %s', $sub));
        }
        $this->err('Available: inject, send');

        return 2;
    }

    /**
     * `--key=value` and `--key value` parser. No positional
     * args; every option is long-form.
     *
     * @TODO: Migrate to horde/argv
     *
     * @param list<string> $args
     * @return array<string, string>
     */
    private function parseOptions(array $args): array
    {
        $out = [];
        $count = count($args);
        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];
            if (!str_starts_with($arg, '--')) {
                throw new InvalidArgumentException(sprintf('Unexpected positional argument: %s', $arg));
            }
            $arg = substr($arg, 2);
            if (str_contains($arg, '=')) {
                [$k, $v] = explode('=', $arg, 2);
                $out[$k] = $v;
                continue;
            }
            // Boolean flags aren't used yet; every long option currently takes
            // a value. Consume the next token.
            if ($i + 1 >= $count) {
                throw new InvalidArgumentException(sprintf('Option --%s requires a value', $arg));
            }
            $out[$arg] = $args[++$i];
        }

        return $out;
    }

    /**
     * @param array<string, string> $opts
     * @param list<string> $required
     * @return list<string>
     */
    private function missing(array $opts, array $required): array
    {
        return array_values(array_filter(
            $required,
            static fn(string $k): bool => !array_key_exists($k, $opts) || $opts[$k] === '',
        ));
    }

    private function lookupSecret(string $slug): ?string
    {
        $secret = $this->db->selectValue(
            'SELECT secret FROM satisfiend_endpoints WHERE slug = ?',
            [$slug]
        );

        return $secret === null || $secret === false ? null : (string) $secret;
    }

    private function out(string $line): void
    {
        fwrite($this->stdout, $line . PHP_EOL);
    }

    private function err(string $line): void
    {
        fwrite($this->stderr, $line . PHP_EOL);
    }

    private function printUsage(): void
    {
        $this->out(<<<'TXT'
            Usage: satisfiend-cli <command> [<subcommand>] [options]

            Commands:
              debug inject  Synthesise a WebhookReceivedEvent in-process from a
                            fixture file and dispatch it to the shared event
                            dispatcher. Bypasses HTTP and HMAC verification.
                            Rows land in satisfiend_events with debug=true.

              debug send    Compute an HMAC signature over a fixture file and POST
                            it to a Satisfiend webhook endpoint. Exercises the full
                            receive path just as github.com would. Rows land with
                            debug=false.

              convert-events
                            Backfill the GitHub snapshot tables from event history:

                            If the event payload has a top-level "release",
                            "issue", "pull_request" or "repository" key, records a
                            snapshot in the matching item repository.

              poll-repositories ORG
                            Poll the GitHub REST API for every publicly visible
                            repository in organization ORG and record a
                            satisfiend_gh_repositories snapshot for each one that
                            is new or has changed since the last observation.

              help          This help text.

            Common options (both `inject` and `send`):
              --slug=SLUG          Endpoint slug (required)
              --event-type=TYPE    e.g. push, pull_request, issues (required)
              --fixture=PATH       Path to a JSON payload file (required)
              --provider=PROVIDER  Provider identifier (inject only; default: github)
              --action=ACTION      Override the fixture's `action` field
              --delivery-id=ID     Override the delivery id

            `debug send` only:
              --url=URL            Override the endpoint URL
                                   (default: http://localhost/satisfiend/webhook/SLUG)
              --secret=SECRET      Override the endpoint's HMAC secret
                                   (default: read from satisfiend_endpoints)

            convert-events:
              Pages through satisfiend_events oldest-to-newest; for every event
              whose payload has a top-level "release", "issue", "pull_request"
              or "repository" key, inserts a snapshot into the matching table
              (skipped if the entity is unchanged since its last recorded
              snapshot).
              --batch-size=N       Rows fetched per page (default: 500)

            poll-repositories ORG:
              Calls GET /orgs/ORG/repos (paginated) and records a
              snapshot for every repository that is
              new or has changed (by node_id + updated_at) since the last
              recorded observation. Unauthenticated by default, which only
              sees ORG's publicly visible repositories.
              --token=TOKEN        GitHub access token (raises the rate limit;
                                   still only sees public repos unless it has
                                   org membership/read scope)
            TXT);
    }
}
