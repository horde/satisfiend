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

use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

/**
 * Sends a signed webhook POST to a local (or arbitrary) Satisfiend
 * endpoint, imitating exactly what github.com would do to us.
 *
 * The receiver has no idea this request came from a debug tool; the
 * signature is computed with the endpoint's real HMAC secret and the
 * request is served by the real {@see WebhookHandler}. The resulting
 * row in `satisfiend_events` lands with `debug=false` because it *is*
 * a real delivery, merely one whose payload was crafted by hand.
 *
 * Use {@see DebugInjectionService} instead when you want events tagged
 * as synthetic (`debug=true`) or when HTTP is inconvenient during rapid
 * iteration.
 */
class DebugHttpSender
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * @param string      $url         Absolute URL of the Satisfiend webhook endpoint,
     *                                 e.g. http://localhost/satisfiend/webhook/github.
     * @param string      $secret      HMAC secret shared with the endpoint (matches
     *                                 the `secret` column of `satisfiend_endpoints`).
     * @param string      $eventType   Value for the `X-GitHub-Event` header.
     * @param string      $fixturePath Absolute path to a JSON payload file.
     * @param string|null $deliveryId  Value for `X-GitHub-Delivery`. Defaults to a
     *                                 UUID-like synthetic id so repeated invocations
     *                                 do not collide on the delivery_id unique index.
     */
    public function send(
        string $url,
        string $secret,
        string $eventType,
        string $fixturePath,
        ?string $deliveryId = null,
    ): ResponseInterface {
        if (!is_file($fixturePath) || !is_readable($fixturePath)) {
            throw new InvalidArgumentException(
                sprintf('Fixture not readable: %s', $fixturePath)
            );
        }

        $body = file_get_contents($fixturePath);
        if ($body === false || $body === '') {
            throw new RuntimeException(
                sprintf('Fixture empty or unreadable: %s', $fixturePath)
            );
        }

        // Validate that the body parses as JSON before we sign and send;
        // saves a round-trip when the caller pointed at the wrong file.
        if (json_decode($body) === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                sprintf('Fixture is not valid JSON: %s', $fixturePath)
            );
        }

        $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);
        $delivery = $deliveryId ?? $this->syntheticDeliveryId();

        $request = $this->requestFactory
            ->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-GitHub-Event', $eventType)
            ->withHeader('X-GitHub-Delivery', $delivery)
            ->withHeader('X-Hub-Signature-256', $signature)
            ->withHeader('User-Agent', 'satisfiend-debug-sender/1.0')
            ->withBody($this->streamFactory->createStream($body));

        return $this->httpClient->sendRequest($request);
    }

    private function syntheticDeliveryId(): string
    {
        // Not a real UUID; just something delivery_id-shaped and unique
        // enough to survive concurrent invocations on the unique index.
        $bytes = random_bytes(16);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
