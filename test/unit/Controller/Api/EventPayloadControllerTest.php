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

use Horde\Http\RequestFactory;
use Horde\Http\ResponseFactory;
use Horde\Http\StreamFactory;
use Horde\Satisfiend\Controller\Api\ApiResponse;
use Horde\Satisfiend\Controller\Api\EventPayloadController;
use Horde\Satisfiend\EventRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventPayloadController::class)]
final class EventPayloadControllerTest extends TestCase
{
    /**
     * Build a controller wired to a repository mock. The
     * $expectedRepoCalls argument pins the exact call count for each
     * test so the mock's contract is never left implicit.
     */
    private function makeController(?array $row, InvocationOrder $expectedRepoCalls): EventPayloadController
    {
        $repo = $this->createMock(EventRepository::class);
        $repo->expects($expectedRepoCalls)
            ->method('findByDeliveryId')
            ->willReturn($row);

        return new EventPayloadController(
            $repo,
            new ApiResponse(new ResponseFactory(), new StreamFactory()),
            new ResponseFactory(),
            new StreamFactory(),
        );
    }

    public function testUnknownDeliveryIdReturns404Json(): void
    {
        $controller = $this->makeController(null, $this->once());
        $request = (new RequestFactory())
            ->createServerRequest('GET', 'http://localhost/')
            ->withAttribute('route', ['delivery_id' => 'nope']);

        $response = $controller->handle($request);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testMissingDeliveryIdReturns404(): void
    {
        // Handler short-circuits before touching the repository when
        // the route attribute is absent.
        $controller = $this->makeController(null, $this->never());
        $request = (new RequestFactory())->createServerRequest('GET', 'http://localhost/');

        $response = $controller->handle($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testKnownRowReturnsRawPayloadBytes(): void
    {
        $payloadBytes = "{\n  \"ref\": \"refs/heads/main\"\n}";
        $controller = $this->makeController(
            [
                'delivery_id' => 'abc-123',
                'payload' => $payloadBytes,
            ],
            $this->once(),
        );

        $request = (new RequestFactory())
            ->createServerRequest('GET', 'http://localhost/')
            ->withAttribute('route', ['delivery_id' => 'abc-123']);

        $response = $controller->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame(
            $payloadBytes,
            (string) $response->getBody(),
            'response body must be exactly the stored payload bytes'
        );
    }

    public function testContentDispositionUsesSanitisedFilename(): void
    {
        $controller = $this->makeController(
            [
                'delivery_id' => 'abc-123',
                'payload' => '{}',
            ],
            $this->once(),
        );
        $request = (new RequestFactory())
            ->createServerRequest('GET', 'http://localhost/')
            ->withAttribute('route', ['delivery_id' => 'abc-123']);

        $response = $controller->handle($request);
        $this->assertSame(
            'inline; filename="abc-123.json"',
            $response->getHeaderLine('Content-Disposition'),
        );
    }
}
