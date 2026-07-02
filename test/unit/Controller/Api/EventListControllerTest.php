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
use Horde\Satisfiend\Controller\Api\EventJsonPresenter;
use Horde\Satisfiend\Controller\Api\EventListController;
use Horde\Satisfiend\EventFilter;
use Horde\Satisfiend\EventRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventListController::class)]
#[CoversClass(ApiResponse::class)]
final class EventListControllerTest extends TestCase
{
    /**
     * All happy-path list requests call `list` once and `count` once
     * on the repository. Bake those expectations into the helper so
     * every test in this file pins the collaborator contract.
     */
    private function makeController(array $listRows, int $totalCount): EventListController
    {
        $repo = $this->createMock(EventRepository::class);
        $repo->expects($this->once())
            ->method('list')
            ->willReturn($listRows);
        $repo->expects($this->once())
            ->method('count')
            ->willReturn($totalCount);

        $routes = $this->createStub(RoutesProvider::class);
        $routes->method('generateNamedPath')->willReturn(null);

        return new EventListController(
            $repo,
            new EventJsonPresenter($routes),
            new ApiResponse(new ResponseFactory(), new StreamFactory()),
        );
    }

    private function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    public function testEmptyResultShape(): void
    {
        $controller = $this->makeController([], 0);
        $request = (new RequestFactory())->createServerRequest('GET', 'http://localhost/api/events');

        $response = $controller->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame([], $body['data']);
        $this->assertSame(0, $body['pagination']['total']);
        $this->assertSame(1, $body['pagination']['page']);
        $this->assertSame(50, $body['pagination']['per_page']);
        // Empty filter must serialise as object, not array. Enforced by
        // json_decode(..., true) returning [] for both, so check the raw.
        $this->assertStringContainsString('"filter":{}', (string) $response->getBody());
    }

    public function testDefaultPagination(): void
    {
        $repo = $this->createMock(EventRepository::class);
        $repo->expects($this->once())
            ->method('list')
            ->with($this->isInstanceOf(EventFilter::class), 1, 50)
            ->willReturn([]);
        $repo->expects($this->once())
            ->method('count')
            ->willReturn(0);
        $routes = $this->createStub(RoutesProvider::class);
        $controller = new EventListController(
            $repo,
            new EventJsonPresenter($routes),
            new ApiResponse(new ResponseFactory(), new StreamFactory()),
        );

        $request = (new RequestFactory())->createServerRequest('GET', 'http://localhost/api/events');
        $controller->handle($request);
    }

    public function testPerPageClampedToOneHundred(): void
    {
        $repo = $this->createMock(EventRepository::class);
        $repo->expects($this->once())
            ->method('list')
            ->with($this->anything(), 1, 100)
            ->willReturn([]);
        $repo->expects($this->once())
            ->method('count')
            ->willReturn(0);
        $routes = $this->createStub(RoutesProvider::class);
        $controller = new EventListController(
            $repo,
            new EventJsonPresenter($routes),
            new ApiResponse(new ResponseFactory(), new StreamFactory()),
        );

        $request = (new RequestFactory())
            ->createServerRequest('GET', 'http://localhost/api/events?per_page=1000')
            ->withQueryParams(['per_page' => '1000']);
        $controller->handle($request);
    }

    public function testFilterEchoIncludesAppliedParamsOnly(): void
    {
        $controller = $this->makeController([], 0);
        $request = (new RequestFactory())
            ->createServerRequest('GET', 'http://localhost/')
            ->withQueryParams(['slug' => 'github', 'event_type' => '']);

        $response = $controller->handle($request);
        $body = $this->decode($response);
        $this->assertSame(['slug' => 'github'], $body['filter']);
    }

    public function testSummaryHasExpectedShape(): void
    {
        $row = [
            'event_id' => 5,
            'slug' => 'github',
            'delivery_id' => 'xyz-999',
            'event_type' => 'issues',
            'action' => 'opened',
            'repository' => 'horde/example',
            'actor' => 'octocat',
            'status' => 'pending',
            'received_at' => '2026-07-02 12:00:00',
            'debug' => 1,
        ];
        $controller = $this->makeController([$row], 1);
        $request = (new RequestFactory())->createServerRequest('GET', 'http://localhost/');

        $response = $controller->handle($request);
        $body = $this->decode($response);
        $this->assertCount(1, $body['data']);
        $item = $body['data'][0];
        $this->assertSame('xyz-999', $item['delivery_id']);
        $this->assertSame('issues', $item['event_type']);
        $this->assertSame('opened', $item['action']);
        $this->assertTrue($item['debug'], 'debug column 1 must round-trip as boolean true');
        $this->assertArrayNotHasKey('payload', $item, 'summary must not include payload bytes');
    }
}
