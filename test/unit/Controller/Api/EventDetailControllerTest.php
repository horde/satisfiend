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

namespace Horde\Satisfiend\Test\Unit\Controller\Api;

use Horde\Core\Uri\RoutesProvider;
use Horde\Http\RequestFactory;
use Horde\Http\ResponseFactory;
use Horde\Http\StreamFactory;
use Horde\Satisfiend\Controller\Api\ApiResponse;
use Horde\Satisfiend\Controller\Api\EventDetailController;
use Horde\Satisfiend\Controller\Api\EventJsonPresenter;
use Horde\Satisfiend\EventRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventDetailController::class)]
#[CoversClass(ApiResponse::class)]
#[CoversClass(EventJsonPresenter::class)]
final class EventDetailControllerTest extends TestCase
{
    /**
     * Build a controller wired to a repository mock. Call-count
     * expectations for `findByDeliveryId` are declared per test with
     * $expectedRepoCalls so each test pins the collaborator contract
     * explicitly rather than leaving the mock unconfigured.
     */
    private function makeController(?array $row, InvocationOrder $expectedRepoCalls): EventDetailController
    {
        $repo = $this->createMock(EventRepository::class);
        $repo->expects($expectedRepoCalls)
            ->method('findByDeliveryId')
            ->willReturn($row);

        $routes = $this->createStub(RoutesProvider::class);
        $routes->method('generateNamedPath')->willReturn(null);

        return new EventDetailController(
            $repo,
            new EventJsonPresenter($routes),
            new ApiResponse(new ResponseFactory(), new StreamFactory()),
        );
    }

    private function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    public function testMissingDeliveryIdRoute404(): void
    {
        // No `route` attribute means the handler must short-circuit
        // before touching the repository.
        $controller = $this->makeController(null, $this->never());
        $request = (new RequestFactory())->createServerRequest('GET', 'http://localhost/');

        $response = $controller->handle($request);

        $this->assertSame(404, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('not_found', $body['error']['code']);
    }

    public function testUnknownDeliveryIdReturns404(): void
    {
        $controller = $this->makeController(null, $this->once());
        $request = (new RequestFactory())
            ->createServerRequest('GET', 'http://localhost/')
            ->withAttribute('route', ['delivery_id' => 'nope']);

        $response = $controller->handle($request);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testKnownRowReturnsDetailJson(): void
    {
        $row = [
            'event_id' => 1,
            'slug' => 'github',
            'delivery_id' => 'abc-123',
            'node_id' => null,
            'event_type' => 'push',
            'action' => null,
            'repository' => 'horde/example',
            'ref' => null,
            'sha' => null,
            'actor' => 'octocat',
            'payload' => '{"repository":{"full_name":"horde/example"}}',
            'status' => 'pending',
            'retry_count' => 0,
            'received_at' => '2026-07-02 10:00:00',
            'processed_at' => null,
            'debug' => 0,
        ];
        $controller = $this->makeController($row, $this->once());
        $request = (new RequestFactory())
            ->createServerRequest('GET', 'http://localhost/')
            ->withAttribute('route', ['delivery_id' => 'abc-123']);

        $response = $controller->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('abc-123', $body['data']['delivery_id']);
        $this->assertSame('push', $body['data']['event_type']);
        $this->assertFalse($body['data']['debug'], 'debug column 0 must round-trip as boolean false');
        $this->assertIsArray($body['data']['payload'], 'payload must be parsed JSON, not a string');
        $this->assertSame('horde/example', $body['data']['payload']['repository']['full_name']);
        $this->assertArrayHasKey('self', $body['data']['links']);
        $this->assertArrayHasKey('payload', $body['data']['links']);
        $this->assertArrayHasKey('html', $body['data']['links']);
    }
}
