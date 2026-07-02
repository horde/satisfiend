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

use Horde\EventDispatcher\SimpleListenerProvider;
use Horde\Injector\Injector;
use Horde\Injector\TopLevel;
use Horde\Satisfiend\Event\WebhookReceivedEvent;
use Horde\Satisfiend\ListenerLoader;
use Horde\Satisfiend\Test\Unit\Fake\NotCallableListener;
use Horde\Satisfiend\Test\Unit\Fake\SecondSpyListener;
use Horde\Satisfiend\Test\Unit\Fake\SpyListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(ListenerLoader::class)]
final class ListenerLoaderTest extends TestCase
{
    private function makeInjector(): Injector
    {
        return new Injector(new TopLevel());
    }

    private function probe(): WebhookReceivedEvent
    {
        return new WebhookReceivedEvent(
            slug: '',
            providerType: '',
            eventType: '',
            action: '',
            repository: '',
            actor: '',
            nodeId: '',
            deliveryId: '',
            payload: '{}',
        );
    }

    public function testEmptyConfigIsNoOp(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method($this->anything());

        $provider = new SimpleListenerProvider();
        $loader = new ListenerLoader($this->makeInjector(), $logger);

        $subscribed = $loader->register($provider, []);

        $this->assertSame([], $subscribed);
        $this->assertSame([], iterator_to_array($provider->getListenersForEvent($this->probe()), false));
    }

    public function testUnknownClassIsLoggedAndSkipped(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('not autoloadable'));

        $provider = new SimpleListenerProvider();
        $loader = new ListenerLoader($this->makeInjector(), $logger);

        $subscribed = $loader->register($provider, ['My\Fictional\Listener']);

        $this->assertSame([], $subscribed);
    }

    public function testValidClassIsResolvedAndSubscribed(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method($this->anything());

        $provider = new SimpleListenerProvider();
        $loader = new ListenerLoader($this->makeInjector(), $logger);

        $subscribed = $loader->register($provider, [SpyListener::class]);

        $this->assertSame([SpyListener::class], $subscribed);

        // The provider must now yield exactly one listener, and that
        // listener must be the SpyListener we asked for.
        $listeners = iterator_to_array($provider->getListenersForEvent($this->probe()), false);
        $this->assertCount(1, $listeners);
        $this->assertInstanceOf(SpyListener::class, $listeners[0]);
    }

    public function testMultipleClassesSubscribedInDeclaredOrder(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method($this->anything());

        $provider = new SimpleListenerProvider();
        $loader = new ListenerLoader($this->makeInjector(), $logger);

        $subscribed = $loader->register($provider, [
            SpyListener::class,
            SecondSpyListener::class,
        ]);

        $this->assertSame([SpyListener::class, SecondSpyListener::class], $subscribed);

        $listeners = iterator_to_array($provider->getListenersForEvent($this->probe()), false);
        $this->assertCount(2, $listeners);
        // Order of registration must be preserved: first configured
        // class fires first when the dispatcher iterates.
        $this->assertInstanceOf(SpyListener::class, $listeners[0]);
        $this->assertInstanceOf(SecondSpyListener::class, $listeners[1]);
    }

    public function testNonCallableClassIsLoggedAndSkipped(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('is not callable'));

        $provider = new SimpleListenerProvider();
        $loader = new ListenerLoader($this->makeInjector(), $logger);

        $subscribed = $loader->register($provider, [NotCallableListener::class]);

        $this->assertSame([], $subscribed);
        $this->assertCount(
            0,
            iterator_to_array($provider->getListenersForEvent($this->probe()), false),
        );
    }

    public function testAlreadySubscribedClassIsSkippedSilently(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method($this->anything());

        $provider = new SimpleListenerProvider();
        $loader = new ListenerLoader($this->makeInjector(), $logger);

        // First pass subscribes the class.
        $loader->register($provider, [SpyListener::class]);
        $secondPass = $loader->register($provider, [SpyListener::class]);

        $this->assertSame([], $secondPass, 'second registration must be a no-op');
        $this->assertCount(
            1,
            iterator_to_array($provider->getListenersForEvent($this->probe()), false),
            'provider must still hold exactly one instance',
        );
    }

    public function testNonStringEntriesAreLoggedAndSkipped(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('non-string entry'));

        $provider = new SimpleListenerProvider();
        $loader = new ListenerLoader($this->makeInjector(), $logger);

        /** @phpstan-ignore-next-line - deliberately wrong-typed for the test */
        $subscribed = $loader->register($provider, [42]);

        $this->assertSame([], $subscribed);
    }

    public function testValidEntriesSurviveInterleavedInvalidEntries(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        // Two warnings expected: one for the unknown class, one for
        // the non-callable class.
        $logger->expects($this->exactly(2))->method('warning');

        $provider = new SimpleListenerProvider();
        $loader = new ListenerLoader($this->makeInjector(), $logger);

        $subscribed = $loader->register($provider, [
            'My\Fictional\Listener',
            SpyListener::class,
            NotCallableListener::class,
            SecondSpyListener::class,
        ]);

        // Only the two valid classes made it through; the two invalid
        // ones were logged and skipped without aborting the loop.
        $this->assertSame([SpyListener::class, SecondSpyListener::class], $subscribed);
    }
}
