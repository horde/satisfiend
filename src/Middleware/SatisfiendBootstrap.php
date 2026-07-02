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

namespace Horde\Satisfiend\Middleware;

use Horde\Db\Adapter;
use Horde\EventDispatcher\SimpleListenerProvider;
use Horde\Injector\Injector;
use Horde\Satisfiend\EndpointLookupInterface;
use Horde\Satisfiend\Factory\DbAdapterFromServiceFactory;
use Horde\Satisfiend\Factory\EndpointLookupFactory;
use Horde\Satisfiend\Listener\PersistEventListener;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Wires satisfiend's app-specific DI bindings into the request-scoped
 * injector without booting the legacy Horde_Registry stack.
 *
 * The webhook route intentionally does not use {@see HordeCore} -
 * external HMAC-authenticated providers do not need the horde session,
 * registry, or auth pipeline, and pulling those in would slow every
 * delivery for no benefit. This middleware provides the minimal
 * satisfiend-specific bindings that the controller needs:
 *
 *   - {@see EndpointLookupInterface} bound to the DB-backed lookup.
 *   - {@see PersistEventListener} subscribed to the shared PSR-14
 *     provider so every {@see \Horde\Satisfiend\Event\WebhookReceivedEvent}
 *     is durably persisted before the response is returned.
 *
 * Idempotent - safe to run for every request. Second and later
 * invocations detect that the listener is already registered and skip.
 *
 * If satisfiend gains an admin/dashboard route that needs the horde
 * session, that route should compose {@see HordeCore} at the head of
 * its stack. This middleware is not a substitute for HordeCore; it is
 * a leaner alternative for routes that don't need the legacy
 * environment.
 */
class SatisfiendBootstrap implements MiddlewareInterface
{
    public function __construct(
        private readonly Injector $injector,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Route Adapter::class through HordeDbService so we don't hit
        // the legacy DbAdapterFactory that expects Horde::getDriverConfig()
        // and a fully-booted registry.
        $this->injector->bindFactory(
            Adapter::class,
            DbAdapterFromServiceFactory::class,
            'create',
        );

        if (!$this->injector->has(EndpointLookupInterface::class)) {
            $this->injector->bindFactory(
                EndpointLookupInterface::class,
                EndpointLookupFactory::class,
                'create',
            );
        }

        if ($this->injector->has(ListenerProviderInterface::class)) {
            $provider = $this->injector->getInstance(ListenerProviderInterface::class);
            if ($provider instanceof SimpleListenerProvider
                && !$this->listenerAlreadyRegistered($provider)
            ) {
                $provider->addListener(
                    $this->injector->getInstance(PersistEventListener::class),
                );
            }
        }

        return $handler->handle($request);
    }

    /**
     * Guard against re-subscribing the persist listener on subsequent
     * requests in the same process (long-running SAPIs, tests, or a
     * future PSR-15 harness that reuses the container across requests).
     *
     * SimpleListenerProvider exposes registered listeners; probe for
     * one that is an instance of PersistEventListener.
     */
    private function listenerAlreadyRegistered(SimpleListenerProvider $provider): bool
    {
        // No PSR-14 event has been dispatched yet at this point; use
        // an inert probe with the concrete event class so the provider
        // returns every listener registered for it.
        $probe = new \Horde\Satisfiend\Event\WebhookReceivedEvent(
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
        foreach ($provider->getListenersForEvent($probe) as $listener) {
            if ($listener instanceof PersistEventListener) {
                return true;
            }
        }

        return false;
    }
}
