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

use Horde\EventDispatcher\SimpleListenerProvider;
use Horde\Injector\Injector;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the installation-local list of listener class names from
 * satisfiend's config and subscribes each one to the horde-wide
 * PSR-14 listener provider.
 *
 * Each configured class is:
 *   - resolved via the injector, so listeners can declare typed
 *     constructor dependencies (Horde\Db\Adapter, LoggerInterface,
 *     anything bound in the container);
 *   - subscribed by identity to the shared SimpleListenerProvider, so
 *     it fires alongside Satisfiend's own PersistEventListener.
 *
 * Failures are logged and skipped. A missing class, a broken
 * constructor, or an object that is not callable does not take the
 * webhook receiver down; the admin sees the failure in the log,
 * fixes it, and the class starts firing on the next request.
 *
 * The loader also guards against re-registering the same class if
 * both entry points (Application::_bootstrap for CLI,
 * SatisfiendBootstrap middleware for HTTP) invoke it in the same
 * process. Idempotence is by class name against the concrete
 * SimpleListenerProvider instance.
 */
class ListenerLoader
{
    public function __construct(
        private readonly Injector $injector,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param list<string> $classNames Fully-qualified class names
     *                                 (typically from
     *                                 `$conf['listeners']['classes']`).
     * @return list<string>            Class names that were newly subscribed.
     */
    public function register(SimpleListenerProvider $provider, array $classNames): array
    {
        if ($classNames === []) {
            return [];
        }

        $alreadySubscribed = $this->existingClassNames($provider);
        $subscribed = [];

        foreach ($classNames as $className) {
            if (!is_string($className) || $className === '') {
                $this->logger->warning('satisfiend: ignoring non-string entry in listeners.classes');
                continue;
            }
            if (isset($alreadySubscribed[$className])) {
                // Already registered against this provider - probably by
                // an earlier bootstrap pass in the same process. Skip
                // silently; the admin doesn't want to see a warning for
                // something that's working correctly.
                continue;
            }
            if (!class_exists($className)) {
                $this->logger->warning(sprintf(
                    'satisfiend: configured listener class %s is not autoloadable; skipping. '
                        . 'Check the PSR-4 autoload mapping in the root bundle composer.json '
                        . 'and run `composer dump-autoload`.',
                    $className,
                ));
                continue;
            }

            try {
                $listener = $this->injector->getInstance($className);
            } catch (Throwable $e) {
                $this->logger->warning(sprintf(
                    'satisfiend: could not construct listener %s: %s. Skipping.',
                    $className,
                    $e->getMessage(),
                ));
                continue;
            }

            if (!is_callable($listener)) {
                $this->logger->warning(sprintf(
                    'satisfiend: listener %s is not callable (does it define __invoke?); skipping.',
                    $className,
                ));
                continue;
            }

            $provider->addListener($listener);
            $subscribed[] = $className;
            $alreadySubscribed[$className] = true;
        }

        return $subscribed;
    }

    /**
     * @return array<string,true>  Set of class names already registered
     *                             on the provider, keyed for O(1) lookup.
     */
    private function existingClassNames(SimpleListenerProvider $provider): array
    {
        // No PSR-14 event has been dispatched yet at bootstrap time,
        // but the provider still enumerates listeners against any event
        // instance we hand it. Use a synthetic WebhookReceivedEvent as
        // the probe so we get the full set of listeners registered for
        // that event type - which is all of them, in current shape.
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

        $names = [];
        foreach ($provider->getListenersForEvent($probe) as $listener) {
            if (is_object($listener)) {
                $names[$listener::class] = true;
            }
        }

        return $names;
    }
}
