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

namespace Horde\Satisfiend\Controller;

use Horde\Core\Uri\RoutesProvider;
use Horde\Satisfiend\EventRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Renders `/satisfiend/events/:event_id` - one event's full detail
 * with the raw payload pretty-printed inline. Ticket 2 keeps the
 * payload view intentionally simple (server-rendered `<pre>` with
 * `JSON_PRETTY_PRINT`); an interactive JSON tree can layer on later
 * if this proves painful in practice.
 */
class EventDetailController implements RequestHandlerInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly EventRepository $repository,
        private readonly RoutesProvider $routes,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $routeParams = $request->getAttribute('route', []);
        $deliveryId = is_array($routeParams) ? (string) ($routeParams['delivery_id'] ?? '') : '';
        if ($deliveryId === '') {
            return $this->notFound();
        }

        $row = $this->repository->findByDeliveryId($deliveryId);
        if ($row === null) {
            return $this->notFound();
        }

        $pageOutput = $GLOBALS['page_output'];
        $pageOutput->header(['title' => sprintf(_("Satisfiend - Event %s"), $deliveryId)]);

        $listUrl = $this->routes->generateNamedPath('SatisfiendEventList')
            ?? '/satisfiend/events';
        $repoFilterUrl = $listUrl
            . (empty($row['repository']) ? '' : '?repository=' . rawurlencode((string) $row['repository']));

        $html = '<h1 class="header">'
            . _("Event") . ' <code>' . htmlspecialchars($deliveryId) . '</code>'
            . ($row['debug']
                ? ' <span style="background:#fff3cd;padding:2px 6px;border-radius:3px;font-size:0.6em;vertical-align:middle">' . _("debug") . '</span>'
                : '')
            . '</h1>';
        $html .= '<div class="horde-content">';
        $html .= '<p><a href="' . htmlspecialchars($listUrl) . '">' . _("« Back to list") . '</a>';
        if (!empty($row['repository'])) {
            $html .= ' - '
                . '<a href="' . htmlspecialchars($repoFilterUrl) . '">'
                . sprintf(_("More from %s"), htmlspecialchars((string) $row['repository']))
                . '</a>';
        }
        $html .= '</p>';

        $html .= $this->renderMetadataTable($row);
        $html .= $this->renderPayload((string) $row['payload']);

        $html .= '</div>';

        $pageOutput->footer();

        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withBody($this->streamFactory->createStream($html));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function renderMetadataTable(array $row): string
    {
        // Order fields "primary identity first, then relations, then
        // status metadata" - that's the reading order someone
        // debugging a delivery actually wants.
        $fields = [
            _("Delivery ID")    => (string) ($row['delivery_id'] ?? ''),
            _("Received at")    => (string) $row['received_at'],
            _("Slug")           => (string) $row['slug'],
            _("Event type")     => (string) $row['event_type'],
            _("Action")         => (string) ($row['action'] ?? ''),
            _("Repository")     => (string) ($row['repository'] ?? ''),
            _("Actor")          => (string) ($row['actor'] ?? ''),
            _("Ref")            => (string) ($row['ref'] ?? ''),
            _("SHA")            => (string) ($row['sha'] ?? ''),
            _("Node ID")        => (string) ($row['node_id'] ?? ''),
            _("Status")         => (string) $row['status'],
            _("Retry count")    => (string) $row['retry_count'],
            _("Processed at")   => (string) ($row['processed_at'] ?? ''),
            _("Debug")          => $row['debug'] ? _("Yes") : _("No"),
        ];

        $html = '<table class="horde-table" style="width:100%">'
            . '<tbody>';
        foreach ($fields as $label => $value) {
            $html .= '<tr>'
                . '<th style="width:14em;text-align:left">' . htmlspecialchars((string) $label) . '</th>'
                . '<td>' . ($value === '' ? '<em>-</em>' : htmlspecialchars($value)) . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * Payload rendered as pretty-printed JSON in a plain `<pre>` - no
     * JS dependencies. If json_decode fails (which shouldn't happen
     * because WebhookHandler validates before persisting) we fall back
     * to the raw bytes so the user can still see what came in.
     */
    private function renderPayload(string $payload): string
    {
        $decoded = json_decode($payload);
        $pretty = ($decoded !== null && json_last_error() === JSON_ERROR_NONE)
            ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $payload;

        return '<h2>' . _("Payload") . '</h2>'
            . '<pre style="background:#f7f7f7;padding:1em;overflow:auto;max-height:60em">'
            . htmlspecialchars((string) $pretty)
            . '</pre>';
    }

    private function notFound(): ResponseInterface
    {
        $body = $this->streamFactory->createStream(
            '<h1>' . _("Event not found") . '</h1>'
        );

        return $this->responseFactory->createResponse(404)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withBody($body);
    }
}
