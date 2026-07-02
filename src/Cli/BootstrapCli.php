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

namespace Horde\Satisfiend\Cli;

use Horde\Core\DefaultInjectorBindings;
use Horde\Db\Adapter;
use Horde\Http\Client\Curl as CurlClient;
use Horde\Http\Client\Options as HttpOptions;
use Horde\Http\RequestFactory;
use Horde\Http\ResponseFactory;
use Horde\Http\StreamFactory;
use Horde\Injector\Injector;
use Horde\Injector\TopLevel;
use Horde\Satisfiend\Bootstrap;
use Horde\Satisfiend\Cli;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Horde_Injector;

/**
 * CLI composition root for `bin/satisfiend-cli`.
 *
 * Builds a fresh injector, applies the horde-wide default bindings,
 * wires satisfiend's runtime via {@see Bootstrap::wire()}, and returns
 * a {@see Cli} instance ready to `run($argv)`.
 *
 * Deliberately does not use `Horde_Registry::appInit`. The legacy
 * appInit path pulls in a large amount of session / theme /
 * authentication machinery that a CLI script does not need. Building
 * our own injector keeps the CLI startup path minimal and mirrors the
 * modern web entry point ({@see \Horde\Core\RampageBootstrap}).
 *
 * `HORDE_BASE` and `HORDE_CONFIG_BASE` are defined by satisfiend's own
 * auto-generated `config/horde.local.php`, which every entry point
 * includes on startup. That file is written by the horde-installer
 * plugin at composer install/update time and points at the running
 * install's paths; nothing else in this class needs to know where
 * those directories are.
 */
final class BootstrapCli
{
    public static function build(): Cli
    {
        self::defineHordePaths();
        self::seedServerVarsForCli();

        $injector = new Injector(new TopLevel());
        $injector->setInstance(Injector::class, $injector);
        // Legacy Horde_Injector alias for anything that still asks by
        // the underscored name.
        $injector->setInstance(Horde_Injector::class, $injector);

        (new DefaultInjectorBindings())->register($injector);

        // HTTP client wiring: `debug send` needs a PSR-18 client. The
        // web request path gets one via HordeCore middleware; here we
        // wire a Curl-backed stack directly because there is no
        // middleware chain in a CLI.
        if (!$injector->has(ClientInterface::class)) {
            $streamFactory = new StreamFactory();
            $responseFactory = new ResponseFactory();
            $injector->setInstance(
                ClientInterface::class,
                new CurlClient($responseFactory, $streamFactory, new HttpOptions()),
            );
            $injector->setInstance(RequestFactoryInterface::class, new RequestFactory());
            $injector->setInstance(StreamFactoryInterface::class, $streamFactory);
        }

        // Satisfiend-specific bindings and listener subscription. Same
        // helper the HTTP middleware calls.
        Bootstrap::wire($injector);

        return new Cli(
            stdout: STDOUT,
            stderr: STDERR,
            dispatcher: $injector->getInstance(EventDispatcherInterface::class),
            db: $injector->getInstance(Adapter::class),
            httpClient: $injector->getInstance(ClientInterface::class),
            requestFactory: $injector->getInstance(RequestFactoryInterface::class),
            streamFactory: $injector->getInstance(StreamFactoryInterface::class),
        );
    }

    /**
     * Include satisfiend's auto-generated horde.local.php, which
     * defines HORDE_BASE and HORDE_CONFIG_BASE (and any
     * satisfiend-specific paths). Written by the horde-installer
     * plugin at composer install/update time.
     */
    private static function defineHordePaths(): void
    {
        if (defined('HORDE_CONFIG_BASE') && defined('HORDE_BASE')) {
            return;
        }
        $localFile = dirname(__DIR__, 2) . '/config/horde.local.php';
        if (!is_file($localFile)) {
            throw new RuntimeException(sprintf(
                'satisfiend-cli: expected auto-generated %s to define HORDE_BASE / HORDE_CONFIG_BASE. '
                    . 'This file is written by the horde-installer plugin - is satisfiend actually installed?',
                $localFile,
            ));
        }
        require $localFile;
    }

    /**
     * The horde-wide `var/config/horde/conf.php` reads a handful of
     * `$_SERVER[...]` keys (SERVER_NAME, HTTP_HOST, ...) that are set
     * for web requests but unset in CLI. Pre-populate the ones the
     * shared config reads so PHP does not emit an "undefined array
     * key" warning during config load. The specific values do not
     * matter for our CLI use case - they land in `$conf['server']`
     * but nothing in the CLI path reads that.
     */
    private static function seedServerVarsForCli(): void
    {
        $_SERVER['SERVER_NAME'] ??= 'localhost';
        $_SERVER['HTTP_HOST'] ??= 'localhost';
        $_SERVER['REQUEST_URI'] ??= '/';
    }
}
