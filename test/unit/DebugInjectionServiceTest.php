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

namespace Horde\Satisfiend\Test\Unit;

use Horde\Satisfiend\DebugInjectionService;
use Horde\Satisfiend\Event\WebhookReceivedEvent;
use Horde\Satisfiend\Test\Unit\Fake\RecordingEventDispatcher;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DebugInjectionService::class)]
final class DebugInjectionServiceTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../fixtures/webhooks';

    public function testInjectsEventFromPushFixture(): void
    {
        $dispatcher = new RecordingEventDispatcher();
        $service = new DebugInjectionService($dispatcher);

        $event = $service->inject(
            slug: 'github',
            providerType: 'github',
            eventType: 'push',
            fixturePath: self::FIXTURE_DIR . '/push.json',
        );

        $this->assertCount(1, $dispatcher->dispatched);
        $dispatched = $dispatcher->dispatched[0];
        $this->assertInstanceOf(WebhookReceivedEvent::class, $dispatched);
        $this->assertSame($event, $dispatched);
        $this->assertTrue($event->debug);
        $this->assertSame('github', $event->slug);
        $this->assertSame('github', $event->providerType);
        $this->assertSame('push', $event->eventType);
        $this->assertSame('horde/example', $event->repository);
        $this->assertSame('octocat', $event->actor);
        $this->assertSame('', $event->action, 'push fixture has no top-level action field');
        $this->assertNotSame('', $event->deliveryId, 'delivery id should be synthesised');
        $this->assertStringStartsWith('debug-', $event->deliveryId);
    }

    public function testInjectsPullRequestFixtureWithActionAndNodeId(): void
    {
        $dispatcher = new RecordingEventDispatcher();
        $service = new DebugInjectionService($dispatcher);

        $event = $service->inject(
            slug: 'github',
            providerType: 'github',
            eventType: 'pull_request',
            fixturePath: self::FIXTURE_DIR . '/pull_request.opened.json',
        );

        $this->assertSame('opened', $event->action);
        $this->assertSame('PR_kwDOAAAAAA', $event->nodeId);
    }

    public function testActionOverrideTakesPrecedenceOverFixture(): void
    {
        $dispatcher = new RecordingEventDispatcher();
        $service = new DebugInjectionService($dispatcher);

        $event = $service->inject(
            slug: 'github',
            providerType: 'github',
            eventType: 'pull_request',
            fixturePath: self::FIXTURE_DIR . '/pull_request.opened.json',
            actionOverride: 'closed',
        );

        $this->assertSame('closed', $event->action);
    }

    public function testExplicitDeliveryIdIsRespected(): void
    {
        $dispatcher = new RecordingEventDispatcher();
        $service = new DebugInjectionService($dispatcher);

        $event = $service->inject(
            slug: 'github',
            providerType: 'github',
            eventType: 'push',
            fixturePath: self::FIXTURE_DIR . '/push.json',
            deliveryId: 'my-explicit-id',
        );

        $this->assertSame('my-explicit-id', $event->deliveryId);
    }

    public function testMissingFixtureRaises(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DebugInjectionService(new RecordingEventDispatcher()))->inject(
            slug: 'github',
            providerType: 'github',
            eventType: 'push',
            fixturePath: '/nonexistent/path/does/not/exist.json',
        );
    }

    public function testNonObjectFixtureRaises(): void
    {
        // A JSON array is not an object; PayloadExtractor expects an object.
        $tmp = tempnam(sys_get_temp_dir(), 'satisfiend-fixture-');
        file_put_contents($tmp, '[1, 2, 3]');
        try {
            $this->expectException(InvalidArgumentException::class);
            (new DebugInjectionService(new RecordingEventDispatcher()))->inject(
                slug: 'github',
                providerType: 'github',
                eventType: 'push',
                fixturePath: $tmp,
            );
        } finally {
            unlink($tmp);
        }
    }

    public function testEmptyFixtureRaises(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'satisfiend-fixture-');
        file_put_contents($tmp, '');
        try {
            $this->expectException(RuntimeException::class);
            (new DebugInjectionService(new RecordingEventDispatcher()))->inject(
                slug: 'github',
                providerType: 'github',
                eventType: 'push',
                fixturePath: $tmp,
            );
        } finally {
            unlink($tmp);
        }
    }
}
