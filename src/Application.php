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
use Horde\Satisfiend\Listener\PersistEventListener;
use Horde_Db_Adapter;
use Horde_Registry_Application;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\Http\Message\ResponseFactoryInterface;

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

        $injector->bindClosure(
            EndpointLookupInterface::class,
            function ($injector) {
                return new DbEndpointLookup(
                    $injector->getInstance(Horde_Db_Adapter::class),
                );
            },
        );

        $injector->bindClosure(
            WebhookVerifierFactory::class,
            function () {
                return new WebhookVerifierFactory();
            },
        );

        $injector->bindClosure(
            PersistEventListener::class,
            function ($injector) {
                return new PersistEventListener(
                    $injector->getInstance(Horde_Db_Adapter::class),
                );
            },
        );

        $injector->bindClosure(
            WebhookHandler::class,
            function ($injector) {
                return new WebhookHandler(
                    $injector->getInstance(EndpointLookupInterface::class),
                    $injector->getInstance(WebhookVerifierFactory::class),
                    $injector->getInstance(EventDispatcherInterface::class),
                    $injector->getInstance(ResponseFactoryInterface::class),
                );
            },
        );

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
