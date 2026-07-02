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

namespace Horde\Satisfiend\Controller\Api;

use Horde\Core\Uri\RoutesProvider;

/**
 * Maps `satisfiend_events` DB rows to the JSON shape the API returns.
 *
 * Keeps three concerns in one place:
 *
 *  - Column selection: the list endpoint gets a compact projection,
 *    the detail endpoint gets everything including the parsed payload.
 *  - Type coercion: `debug` is a real bool in JSON, timestamps are
 *    ISO strings, integer counters are integers.
 *  - Link generation: every representation carries a `links` block so
 *    consumers can navigate without knowing how the API routes are
 *    shaped.
 */
final class EventJsonPresenter
{
    public function __construct(
        private readonly RoutesProvider $routes,
    ) {}

    /**
     * Compact JSON representation used in list responses. Excludes the
     * raw payload to keep the response size bounded; consumers who
     * want the payload GET the detail or payload endpoint.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function summary(array $row): array
    {
        $deliveryId = (string) ($row['delivery_id'] ?? '');

        return [
            'delivery_id'  => $deliveryId === '' ? null : $deliveryId,
            'slug'         => (string) $row['slug'],
            'event_type'   => (string) $row['event_type'],
            'action'       => $row['action'] === null ? null : (string) $row['action'],
            'repository'   => $row['repository'] === null ? null : (string) $row['repository'],
            'actor'        => $row['actor'] === null ? null : (string) $row['actor'],
            'status'       => (string) $row['status'],
            'received_at'  => (string) $row['received_at'],
            'debug'        => (bool) $row['debug'],
            'links'        => $this->linksFor($deliveryId),
        ];
    }

    /**
     * Full JSON representation used in detail responses. Includes every
     * indexed column plus the payload parsed back into structured JSON
     * (so consumers do not have to double-decode a string).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function detail(array $row): array
    {
        $deliveryId = (string) ($row['delivery_id'] ?? '');
        $payloadRaw = (string) ($row['payload'] ?? '');
        $payloadDecoded = $payloadRaw === '' ? null : json_decode($payloadRaw);
        // If decode fails (should not happen because WebhookHandler
        // validates before persisting), surface the raw bytes rather
        // than lie about JSON-ness.
        if ($payloadDecoded === null && json_last_error() !== JSON_ERROR_NONE) {
            $payloadDecoded = ['_raw' => $payloadRaw, '_error' => json_last_error_msg()];
        }

        return [
            'delivery_id'  => $deliveryId === '' ? null : $deliveryId,
            'slug'         => (string) $row['slug'],
            'event_type'   => (string) $row['event_type'],
            'action'       => $row['action'] === null ? null : (string) $row['action'],
            'repository'   => $row['repository'] === null ? null : (string) $row['repository'],
            'actor'        => $row['actor'] === null ? null : (string) $row['actor'],
            'node_id'      => $row['node_id'] === null ? null : (string) $row['node_id'],
            'ref'          => $row['ref'] === null ? null : (string) $row['ref'],
            'sha'          => $row['sha'] === null ? null : (string) $row['sha'],
            'status'       => (string) $row['status'],
            'retry_count'  => (int) $row['retry_count'],
            'received_at'  => (string) $row['received_at'],
            'processed_at' => $row['processed_at'] === null ? null : (string) $row['processed_at'],
            'debug'        => (bool) $row['debug'],
            'payload'      => $payloadDecoded,
            'links'        => $this->linksFor($deliveryId),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function linksFor(string $deliveryId): array
    {
        if ($deliveryId === '') {
            return [];
        }

        $self = $this->routes->generateNamedPath(
            'SatisfiendApiEventDetail',
            ['delivery_id' => $deliveryId],
        ) ?? ('/satisfiend/api/events/' . rawurlencode($deliveryId));

        $payload = $this->routes->generateNamedPath(
            'SatisfiendApiEventPayload',
            ['delivery_id' => $deliveryId],
        ) ?? ('/satisfiend/api/events/' . rawurlencode($deliveryId) . '/payload');

        // Web detail view (ticket 2). Included so an API consumer can
        // link a human back to a rendered version.
        $html = $this->routes->generateNamedPath(
            'SatisfiendEventDetail',
            ['delivery_id' => $deliveryId],
        ) ?? ('/satisfiend/events/' . rawurlencode($deliveryId));

        return [
            'self'    => $self,
            'payload' => $payload,
            'html'    => $html,
        ];
    }
}
