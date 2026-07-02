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
use Horde\Satisfiend\Factory\EndpointLookupFactory;
use Horde\Satisfiend\Listener\PersistEventListener;
use Horde_Registry_Application;
use Psr\EventDispatcher\ListenerProviderInterface;

if (!defined('SATISFIEND_BASE')) {
    define('SATISFIEND_BASE', realpath(__DIR__ . '/..'));
}

if (!defined('HORDE_BASE')) {
    if (file_exists(SATISFIEND_BASE . '/config/horde.local.php')) {
        include SATISFIEND_BASE . '/config/horde.local.php';
    } else {
        define('HORDE_BASE', realpath(SATISFIEND_BASE . '/..'));
    }
}

require_once HORDE_BASE . '/lib/core.php';

class Application extends Horde_Registry_Application
{
    public $version = '1.0.0-alpha1';

    protected function _bootstrap(): void
    {
        $injector = $GLOBALS['injector'];

        // Only interface -> concrete bindings need explicit factories.
        // Every other satisfiend service (WebhookHandler,
        // WebhookVerifierFactory, PersistEventListener, ...) has a
        // concrete constructor with typed parameters the injector
        // auto-resolves.
        $injector->bindFactory(
            EndpointLookupInterface::class,
            EndpointLookupFactory::class,
            'create',
        );

        // Register the PersistEventListener against the shared PSR-14
        // listener provider so every WebhookReceivedEvent is durably
        // stored regardless of which other consumers are listening.
        if ($injector->has(ListenerProviderInterface::class)) {
            $provider = $injector->getInstance(ListenerProviderInterface::class);
            if ($provider instanceof SimpleListenerProvider) {
                $provider->addListener(
                    $injector->getInstance(PersistEventListener::class),
                );
            }
        }
    }
}
