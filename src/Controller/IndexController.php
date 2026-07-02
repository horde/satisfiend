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
use Horde\Db\Adapter;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

class IndexController implements RequestHandlerInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly Adapter $db,
        private readonly RoutesProvider $routes,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $page_output = $GLOBALS['page_output'];
        $page_output->header(['title' => _("Satisfiend")]);

        $html = '<h1 class="header">' . _("Webhook Dashboard") . '</h1>';

        $counts = $this->db->selectOne(
            'SELECT'
            . ' COUNT(*) AS total,'
            . ' COALESCE(SUM(CASE WHEN debug = 0 THEN 1 ELSE 0 END), 0) AS real_count,'
            . ' COALESCE(SUM(CASE WHEN debug = 1 THEN 1 ELSE 0 END), 0) AS debug_count,'
            . ' MAX(received_at) AS last_received'
            . ' FROM satisfiend_events'
        );
        $total = (int) ($counts['total'] ?? 0);
        $realCount = (int) ($counts['real_count'] ?? 0);
        $debugCount = (int) ($counts['debug_count'] ?? 0);
        $lastReceived = $counts['last_received'] ?? null;

        $endpoints = $this->db->selectAll(
            'SELECT slug, provider_type, active FROM satisfiend_endpoints ORDER BY slug'
        );

        $eventsUrl = $this->routes->generateNamedPath('SatisfiendEventList')
            ?? '/satisfiend/events';

        $html .= '<div class="horde-content">';

        // Event summary. Numbers link into the list view with the
        // appropriate filter pre-applied so "5 events received" becomes
        // an actual navigation affordance instead of a dead-end.
        $html .= '<h2>' . _("Events") . '</h2>';
        $html .= '<p>'
            . '<a href="' . htmlspecialchars($eventsUrl) . '">'
            . sprintf(_("%d total"), $total) . '</a>'
            . ' - '
            . '<a href="' . htmlspecialchars($eventsUrl) . '?debug=0">'
            . sprintf(_("%d real"), $realCount) . '</a>'
            . ', '
            . '<a href="' . htmlspecialchars($eventsUrl) . '?debug=1">'
            . sprintf(_("%d debug-injected"), $debugCount) . '</a>'
            . '</p>';

        if ($lastReceived !== null) {
            $html .= '<p>' . sprintf(_("Last received: %s"), htmlspecialchars((string) $lastReceived)) . '</p>';
        } else {
            $html .= '<p><em>' . _("No events received yet.") . '</em></p>';
        }

        // Endpoints. Rendered as a table just like before, but with a
        // resilient truthy check on `active` (the DB driver may return
        // the value as int, string, or bool depending on backend).
        $html .= '<h2>' . _("Endpoints") . '</h2>';
        if (empty($endpoints)) {
            $html .= '<p><em>' . _("No endpoints configured. Insert one into satisfiend_endpoints or use the admin CLI (phase 2).") . '</em></p>';
        } else {
            $html .= '<table class="horde-table" style="width:100%">'
                . '<thead><tr>'
                . '<th>' . _("Slug") . '</th>'
                . '<th>' . _("Provider") . '</th>'
                . '<th>' . _("Active") . '</th>'
                . '</tr></thead><tbody>';
            foreach ($endpoints as $ep) {
                $isActive = (int) ($ep['active'] ?? 0) === 1;
                $html .= '<tr>'
                    . '<td>' . htmlspecialchars((string) $ep['slug']) . '</td>'
                    . '<td>' . htmlspecialchars((string) $ep['provider_type']) . '</td>'
                    . '<td>' . ($isActive ? _("Yes") : _("No")) . '</td>'
                    . '</tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= '</div>';

        $page_output->footer();

        $body = $this->streamFactory->createStream($html);

        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withBody($body);
    }
}
