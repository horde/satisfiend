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

namespace Horde\Satisfiend\Block;

use Horde;
use Horde\Core\Uri\RoutesProvider;
use Horde\Db\Adapter;
use Horde_Core_Block;

class RecentEvents extends Horde_Core_Block
{
    public $updateable = true;

    public function __construct($app, $params = [])
    {
        parent::__construct($app, $params);
        $this->_name = _("Recent Webhook Events");
    }

    protected function _params(): array
    {
        return [
            'limit' => [
                'type' => 'int',
                'name' => _("Number of events to show"),
                'default' => 10,
            ],
            'event_type' => [
                'type' => 'enum',
                'name' => _("Event type"),
                'default' => '',
                'values' => [
                    '' => _("All"),
                    'push' => _("Pushes"),
                    'pull_request' => _("Pull Requests"),
                    'issues' => _("Issues"),
                    'issue_comment' => _("Comments"),
                    'release' => _("Releases"),
                    'create' => _("Branch/Tag Created"),
                    'delete' => _("Branch/Tag Deleted"),
                ],
            ],
            'provider_type' => [
                'type' => 'enum',
                'name' => _("Provider"),
                'default' => '',
                'values' => [
                    '' => _("All"),
                    'github' => _("GitHub"),
                    'gitea' => _("Gitea"),
                    'gitlab' => _("GitLab"),
                ],
            ],
        ];
    }

    protected function _title(): string
    {
        $title = _("Recent Webhook Events");
        if (!empty($this->_params['event_type'])) {
            $title = sprintf(_("Recent %s Events"), ucfirst(str_replace('_', ' ', $this->_params['event_type'])));
        }

        return $title;
    }

    protected function _content(): string
    {
        $injector = $GLOBALS['injector'];
        $db = $injector->getInstance(Adapter::class);
        $routes = $injector->getInstance(RoutesProvider::class);
        $limit = max(1, min(50, (int) ($this->_params['limit'] ?? 10)));

        $where = [];
        $bind = [];

        if (!empty($this->_params['event_type'])) {
            $where[] = 'e.event_type = ?';
            $bind[] = $this->_params['event_type'];
        }

        if (!empty($this->_params['provider_type'])) {
            $where[] = 'ep.provider_type = ?';
            $bind[] = $this->_params['provider_type'];
        }

        $sql = 'SELECT e.delivery_id, e.event_type, e.action, e.repository, e.actor, e.received_at, e.debug'
            . ' FROM satisfiend_events e'
            . ' JOIN satisfiend_endpoints ep ON e.slug = ep.slug';

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY e.received_at DESC LIMIT ' . $limit;

        $rows = $db->selectAll($sql, $bind);

        if (empty($rows)) {
            return '<p><em>' . _("No events found.") . '</em></p>';
        }

        $html = '<table class="horde-table sortable" style="width:100%">'
            . '<thead><tr>'
            . '<th>' . _("Type") . '</th>'
            . '<th>' . _("Repository") . '</th>'
            . '<th>' . _("Actor") . '</th>'
            . '<th>' . _("When") . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $type = htmlspecialchars($row['event_type']);
            if (!empty($row['action'])) {
                $type .= '/' . htmlspecialchars($row['action']);
            }
            if (!empty($row['debug'])) {
                $type .= ' <span style="background:#fff3cd;padding:1px 4px;border-radius:3px;font-size:0.8em">'
                    . _("debug") . '</span>';
            }

            $deliveryId = (string) ($row['delivery_id'] ?? '');
            if ($deliveryId !== '') {
                $detailUrl = $routes->generateNamedPath(
                    'SatisfiendEventDetail',
                    ['delivery_id' => $deliveryId]
                ) ?? ('/satisfiend/events/' . rawurlencode($deliveryId));
                $typeCell = '<a href="' . htmlspecialchars($detailUrl) . '">' . $type . '</a>';
            } else {
                // Delivery-id-less rows are unaddressable; render as
                // plain text rather than dangling links.
                $typeCell = $type;
            }

            $html .= '<tr>'
                . '<td>' . $typeCell . '</td>'
                . '<td>' . htmlspecialchars($row['repository'] ?? '') . '</td>'
                . '<td>' . htmlspecialchars($row['actor'] ?? '') . '</td>'
                . '<td>' . htmlspecialchars($row['received_at']) . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }
}
