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
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * Command dispatcher for `bin/satisfiend-cli`.
 *
 * Kept as a small, testable class rather than a monolithic script so
 * argv parsing and business logic can be exercised without booting the
 * whole Horde registry from inside a test.
 *
 * Subcommands:
 *   debug inject   In-process synthesised event (bypasses HTTP + HMAC).
 *   debug send     Signed HTTP POST to a local webhook endpoint.
 *   help           Print usage.
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
     * Very small `--key=value` and `--key value` parser. No positional
     * args; every option is long-form. Kept intentionally minimal so
     * tests can trust the exact behaviour without pulling in argv/getopt.
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
                throw new \InvalidArgumentException(sprintf('Unexpected positional argument: %s', $arg));
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
                throw new \InvalidArgumentException(sprintf('Option --%s requires a value', $arg));
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
            static fn (string $k): bool => !array_key_exists($k, $opts) || $opts[$k] === '',
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
TXT);
    }
}
