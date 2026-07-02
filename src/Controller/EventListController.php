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
use Horde\Satisfiend\EventFilter;
use Horde\Satisfiend\EventRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Renders `/satisfiend/events` - a filterable, paginated table of
 * webhook events. Each row links to its detail view.
 *
 * All rendering is server-side HTML with the same `.horde-content` /
 * `.horde-table` classes the existing dashboard uses; no JavaScript
 * dependencies. Filters are expressed as GET params so links and
 * bookmarks are shareable.
 */
class EventListController implements RequestHandlerInterface
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly EventRepository $repository,
        private readonly RoutesProvider $routes,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $filter = EventFilter::fromQueryParams($params);
        $page = max(1, (int) ($params['page'] ?? 1));

        $total = $this->repository->count($filter);
        $rows = $this->repository->list($filter, $page, self::PER_PAGE);

        $pageOutput = $GLOBALS['page_output'];
        $pageOutput->header(['title' => _("Satisfiend - Events")]);

        $html = '<h1 class="header">' . _("Webhook Events") . '</h1>';
        $html .= '<div class="horde-content">';
        $html .= $this->renderFilterForm($filter);
        $html .= $this->renderResults($rows, $filter, $page, $total);
        $html .= '</div>';

        $pageOutput->footer();

        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withBody($this->streamFactory->createStream($html));
    }

    private function renderFilterForm(EventFilter $filter): string
    {
        $field = static function (string $name, string $label, ?string $value): string {
            return '<label style="display:inline-block;margin-right:1em">'
                . htmlspecialchars($label) . ' '
                . '<input type="text" name="' . htmlspecialchars($name) . '"'
                . ' value="' . htmlspecialchars((string) $value) . '" />'
                . '</label>';
        };

        $debugValue = $filter->debug === null ? '' : ($filter->debug ? '1' : '0');
        $debugSelect = '<label style="display:inline-block;margin-right:1em">'
            . _("Debug") . ' '
            . '<select name="debug">'
            . '<option value=""' . ($debugValue === '' ? ' selected' : '') . '>' . _("(any)") . '</option>'
            . '<option value="0"' . ($debugValue === '0' ? ' selected' : '') . '>' . _("Real only") . '</option>'
            . '<option value="1"' . ($debugValue === '1' ? ' selected' : '') . '>' . _("Debug only") . '</option>'
            . '</select></label>';

        return '<form method="get" action="" style="margin-bottom:1em">'
            . $field('q', _("Search"), $filter->q)
            . $field('slug', _("Slug"), $filter->slug)
            . $field('event_type', _("Event type"), $filter->eventType)
            . $field('action', _("Action"), $filter->action)
            . $field('repository', _("Repository"), $filter->repository)
            . $field('actor', _("Actor"), $filter->actor)
            . $field('status', _("Status"), $filter->status)
            . $debugSelect
            . ' <button type="submit">' . _("Filter") . '</button>'
            . ' <a href="?">' . _("Clear") . '</a>'
            . '</form>';
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function renderResults(array $rows, EventFilter $filter, int $page, int $total): string
    {
        if ($rows === []) {
            return '<p><em>' . sprintf(_("No events match. (%d total in table.)"), $total) . '</em></p>';
        }

        $html = '<p>' . sprintf(_("%d matching events."), $total) . '</p>';
        $html .= '<table class="horde-table" style="width:100%">'
            . '<thead><tr>'
            . '<th>' . _("Delivery") . '</th>'
            . '<th>' . _("When") . '</th>'
            . '<th>' . _("Slug") . '</th>'
            . '<th>' . _("Type") . '</th>'
            . '<th>' . _("Repository") . '</th>'
            . '<th>' . _("Actor") . '</th>'
            . '<th>' . _("Status") . '</th>'
            . '<th>' . _("Debug") . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $deliveryId = (string) ($row['delivery_id'] ?? '');
            $deliveryShort = $deliveryId === ''
                ? '<em>' . _("(none)") . '</em>'
                : htmlspecialchars(substr($deliveryId, 0, 8) . (strlen($deliveryId) > 8 ? '…' : ''));

            if ($deliveryId !== '') {
                $detailUrl = $this->routes->generateNamedPath(
                    'SatisfiendEventDetail',
                    ['delivery_id' => $deliveryId]
                ) ?? ('/satisfiend/events/' . rawurlencode($deliveryId));
                $deliveryCell = '<a href="' . htmlspecialchars($detailUrl)
                    . '" title="' . htmlspecialchars($deliveryId) . '">'
                    . $deliveryShort . '</a>';
            } else {
                // Row without a delivery id is unaddressable; that
                // should not happen in current code (both HTTP send and
                // debug inject always populate delivery_id), but we
                // render defensively.
                $deliveryCell = $deliveryShort;
            }

            $type = htmlspecialchars((string) $row['event_type']);
            if (!empty($row['action'])) {
                $type .= '/' . htmlspecialchars((string) $row['action']);
            }

            $debugBadge = $row['debug']
                ? '<span style="background:#fff3cd;padding:2px 6px;border-radius:3px">' . _("debug") . '</span>'
                : '';

            $html .= '<tr>'
                . '<td>' . $deliveryCell . '</td>'
                . '<td>' . htmlspecialchars((string) $row['received_at']) . '</td>'
                . '<td>' . htmlspecialchars((string) $row['slug']) . '</td>'
                . '<td>' . $type . '</td>'
                . '<td>' . htmlspecialchars((string) ($row['repository'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars((string) ($row['actor'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars((string) $row['status']) . '</td>'
                . '<td>' . $debugBadge . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= $this->renderPager($filter, $page, $total);

        return $html;
    }

    private function renderPager(EventFilter $filter, int $page, int $total): string
    {
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($totalPages <= 1) {
            return '';
        }

        $base = $filter->toQueryParams();

        $buildLink = static function (int $target, string $label) use ($base): string {
            $qs = http_build_query(['page' => $target] + $base);

            return '<a href="?' . htmlspecialchars($qs) . '">' . htmlspecialchars($label) . '</a>';
        };

        $html = '<div style="margin-top:1em">';
        if ($page > 1) {
            $html .= $buildLink($page - 1, _("« Previous")) . ' ';
        }
        $html .= sprintf(_("Page %d of %d"), $page, $totalPages);
        if ($page < $totalPages) {
            $html .= ' ' . $buildLink($page + 1, _("Next »"));
        }
        $html .= '</div>';

        return $html;
    }
}
