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

use Horde\Core\Config\ConfigLoader;
use Horde\Db\Adapter;
use Horde\EventDispatcher\SimpleListenerProvider;
use Horde\Injector\Injector;
use Horde\Satisfiend\Factory\DbAdapterFromServiceFactory;
use Horde\Satisfiend\Factory\EndpointLookupFactory;
use Horde\Satisfiend\Listener\PersistEventListener;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Satisfiend's runtime wiring. Both entry points (HTTP middleware and
 * the CLI script) call {@see wire()} to install:
 *
 *   - `EndpointLookupInterface` -> `DbEndpointLookup` (via factory)
 *   - `Horde\Db\Adapter` -> `HordeDbService` proxy (via factory that
 *     avoids the legacy `Horde::getDriverConfig` globals)
 *   - `PersistEventListener` subscribed to the shared PSR-14 provider
 *   - Installation-local listeners named in
 *     `$conf['listeners']['classes']` (via {@see ListenerLoader})
 *
 * This class has no dependency on `Horde_Registry::appInit` or any
 * `$GLOBALS[...]` state. It only reads from the injector it is given.
 * Consequently the same wiring works identically in a full HTTP
 * request, a CLI tool, or a future test harness that constructs its
 * own injector.
 *
 * `wire()` is idempotent: called twice on the same injector, it does
 * not double-register bindings or listeners.
 */
final class Bootstrap
{
    public static function wire(Injector $injector): void
    {
        // The horde/db adapter binding: route Adapter::class through
        // HordeDbService so we do not hit the legacy DbAdapterFactory
        // (which expects Horde::getDriverConfig() and a fully-booted
        // registry). We *always* override the binding here, even when
        // DefaultInjectorBindings has already bound Adapter::class to
        // the legacy factory - because that legacy factory is exactly
        // the thing we want to avoid.
        $injector->bindFactory(
            Adapter::class,
            DbAdapterFromServiceFactory::class,
            'create',
        );

        // Interface -> concrete binding for the endpoint lookup.
        // Idempotent guard: rebinding an already-bound interface is
        // fine, but skipping when possible avoids re-running the
        // binder's setup.
        if (!$injector->has(EndpointLookupInterface::class)) {
            $injector->bindFactory(
                EndpointLookupInterface::class,
                EndpointLookupFactory::class,
                'create',
            );
        }

        // Listener subscription: the shared PSR-14 provider must exist
        // (DefaultInjectorBindings binds it); satisfiend refuses to
        // wire event listeners if it does not.
        if (!$injector->has(ListenerProviderInterface::class)) {
            return;
        }
        $provider = $injector->getInstance(ListenerProviderInterface::class);
        if (!$provider instanceof SimpleListenerProvider) {
            return;
        }

        if (!self::persistListenerAlreadyRegistered($provider)) {
            $provider->addListener(
                $injector->getInstance(PersistEventListener::class),
            );
        }

        // Installation-local listeners named in satisfiend's config.
        // Config is loaded via the modern ConfigLoader, not
        // $GLOBALS['conf']; a missing conf.php yields an empty state
        // and the loader becomes a no-op.
        $classes = $injector->getInstance(ConfigLoader::class)
            ->load('satisfiend')
            ->get('listeners.classes', []);
        (new ListenerLoader(
            $injector,
            $injector->getInstance(LoggerInterface::class),
        ))->register($provider, is_array($classes) ? array_values($classes) : []);
    }

    private static function persistListenerAlreadyRegistered(SimpleListenerProvider $provider): bool
    {
        $probe = new Event\WebhookReceivedEvent(
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
