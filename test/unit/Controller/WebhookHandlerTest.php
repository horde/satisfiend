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

namespace Horde\Satisfiend\Test\Unit\Controller;

use Horde\Http\RequestFactory;
use Horde\Http\ResponseFactory;
use Horde\Http\StreamFactory;
use Horde\Satisfiend\Controller\WebhookHandler;
use Horde\Satisfiend\Endpoint;
use Horde\Satisfiend\Event\WebhookReceivedEvent;
use Horde\Satisfiend\Test\Unit\Fake\InMemoryEndpointLookup;
use Horde\Satisfiend\Test\Unit\Fake\RecordingEventDispatcher;
use Horde\Satisfiend\Test\Unit\Fake\ToggleVerifierFactory;
use Horde\Satisfiend\WebhookVerifierFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(WebhookHandler::class)]
final class WebhookHandlerTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../../fixtures/webhooks';

    private function makeRequest(
        string $slug,
        string $eventType = 'push',
        string $deliveryId = 'test-delivery-1',
        string $body = '{"repository":{"full_name":"horde/example"},"sender":{"login":"octocat"}}',
    ): ServerRequestInterface {
        $requestFactory = new RequestFactory();
        $streamFactory = new StreamFactory();

        return $requestFactory
            ->createServerRequest('POST', 'http://localhost/satisfiend/webhook/' . $slug)
            ->withAttribute('route', ['slug' => $slug])
            ->withHeader('X-GitHub-Event', $eventType)
            ->withHeader('X-GitHub-Delivery', $deliveryId)
            ->withBody($streamFactory->createStream($body));
    }

    private function makeHandler(
        InMemoryEndpointLookup $lookup,
        WebhookVerifierFactory $verifierFactory,
        RecordingEventDispatcher $dispatcher,
    ): WebhookHandler {
        return new WebhookHandler(
            $lookup,
            $verifierFactory,
            $dispatcher,
            new ResponseFactory(),
        );
    }

    private function activeEndpoint(string $slug = 'github', string $provider = 'github'): Endpoint
    {
        return new Endpoint(
            slug: $slug,
            providerType: $provider,
            secret: 'unit-test-secret',
            active: true,
            createdBy: 'unit-test',
        );
    }

    public function testHappyPath(): void
    {
        $lookup = new InMemoryEndpointLookup([
            'github' => $this->activeEndpoint(),
        ]);
        $dispatcher = new RecordingEventDispatcher();
        $handler = $this->makeHandler($lookup, new ToggleVerifierFactory(true), $dispatcher);

        $response = $handler->handle($this->makeRequest('github'));

        $this->assertSame(202, $response->getStatusCode());
        $this->assertCount(1, $dispatcher->dispatched);
        $event = $dispatcher->dispatched[0];
        $this->assertInstanceOf(WebhookReceivedEvent::class, $event);
        $this->assertSame('github', $event->slug);
        $this->assertSame('github', $event->providerType);
        $this->assertSame('push', $event->eventType);
        $this->assertSame('horde/example', $event->repository);
        $this->assertSame('octocat', $event->actor);
        $this->assertSame('test-delivery-1', $event->deliveryId);
        $this->assertFalse($event->debug, 'HTTP-path events are never marked as debug');
    }

    public function testMissingSlugAttributeReturns404(): void
    {
        $lookup = new InMemoryEndpointLookup();
        $dispatcher = new RecordingEventDispatcher();
        $handler = $this->makeHandler($lookup, new ToggleVerifierFactory(true), $dispatcher);

        $request = (new RequestFactory())
            ->createServerRequest('POST', 'http://localhost/satisfiend/webhook/');
        // Deliberately no `route` attribute at all.

        $response = $handler->handle($request);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertCount(0, $dispatcher->dispatched);
    }

    public function testUnknownSlugReturns404(): void
    {
        $lookup = new InMemoryEndpointLookup(); // empty
        $dispatcher = new RecordingEventDispatcher();
        $handler = $this->makeHandler($lookup, new ToggleVerifierFactory(true), $dispatcher);

        $response = $handler->handle($this->makeRequest('does-not-exist'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertCount(0, $dispatcher->dispatched);
    }

    public function testInactiveEndpointReturns404(): void
    {
        $lookup = new InMemoryEndpointLookup([
            'github' => new Endpoint(
                slug: 'github',
                providerType: 'github',
                secret: 's',
                active: false,
                createdBy: 'unit-test',
            ),
        ]);
        $dispatcher = new RecordingEventDispatcher();
        $handler = $this->makeHandler($lookup, new ToggleVerifierFactory(true), $dispatcher);

        $response = $handler->handle($this->makeRequest('github'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertCount(0, $dispatcher->dispatched);
    }

    public function testBadSignatureReturns403(): void
    {
        $lookup = new InMemoryEndpointLookup([
            'github' => $this->activeEndpoint(),
        ]);
        $dispatcher = new RecordingEventDispatcher();
        $handler = $this->makeHandler($lookup, new ToggleVerifierFactory(false), $dispatcher);

        $response = $handler->handle($this->makeRequest('github'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertCount(0, $dispatcher->dispatched);
    }

    public function testMalformedJsonReturns400(): void
    {
        $lookup = new InMemoryEndpointLookup([
            'github' => $this->activeEndpoint(),
        ]);
        $dispatcher = new RecordingEventDispatcher();
        $handler = $this->makeHandler($lookup, new ToggleVerifierFactory(true), $dispatcher);

        $request = $this->makeRequest('github', body: '{not json');
        $response = $handler->handle($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertCount(0, $dispatcher->dispatched);
    }

    public function testPushFixtureRoundTripsThroughHandler(): void
    {
        $lookup = new InMemoryEndpointLookup([
            'github' => $this->activeEndpoint(),
        ]);
        $dispatcher = new RecordingEventDispatcher();
        $handler = $this->makeHandler($lookup, new ToggleVerifierFactory(true), $dispatcher);

        $fixture = file_get_contents(self::FIXTURE_DIR . '/push.json');
        $this->assertNotFalse($fixture);
        $request = $this->makeRequest('github', body: $fixture);

        $response = $handler->handle($request);

        $this->assertSame(202, $response->getStatusCode());
        $event = $dispatcher->dispatched[0];
        $this->assertSame('horde/example', $event->repository);
        $this->assertSame('octocat', $event->actor);
        $this->assertSame($fixture, $event->payload);
    }
}
