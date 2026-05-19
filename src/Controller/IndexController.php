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

use Horde;
use Horde_Db_Adapter;
use Horde_Page_Output;
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
        private readonly Horde_Db_Adapter $db,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $page_output = $GLOBALS['page_output'];
        $page_output->header(['title' => _("Satisfiend")]);

        $html = '<h1 class="header">' . _("Webhook Dashboard") . '</h1>';

        $count = (int) $this->db->selectValue(
            'SELECT COUNT(*) FROM satisfiend_events'
        );
        $endpoints = $this->db->selectAll(
            'SELECT slug, provider_type, active FROM satisfiend_endpoints ORDER BY slug'
        );

        $html .= '<div class="horde-content">';
        $html .= '<p>' . sprintf(_("%d events received."), $count) . '</p>';

        if (!empty($endpoints)) {
            $html .= '<h2>' . _("Endpoints") . '</h2>';
            $html .= '<table class="horde-table" style="width:100%">'
                . '<thead><tr>'
                . '<th>' . _("Slug") . '</th>'
                . '<th>' . _("Provider") . '</th>'
                . '<th>' . _("Active") . '</th>'
                . '</tr></thead><tbody>';
            foreach ($endpoints as $ep) {
                $html .= '<tr>'
                    . '<td>' . htmlspecialchars($ep['slug']) . '</td>'
                    . '<td>' . htmlspecialchars($ep['provider_type']) . '</td>'
                    . '<td>' . ($ep['active'] ? _("Yes") : _("No")) . '</td>'
                    . '</tr>';
            }
            $html .= '</tbody></table>';
        } else {
            $html .= '<p><em>' . _("No endpoints configured.") . '</em></p>';
        }

        $html .= '</div>';

        $page_output->footer();

        $body = $this->streamFactory->createStream($html);

        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withBody($body);
    }
}
