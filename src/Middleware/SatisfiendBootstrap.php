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

use Horde\Injector\Injector;
use Horde\Satisfiend\Bootstrap;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware that installs satisfiend's runtime bindings on
 * the request-scoped injector. Delegates all wiring to
 * {@see Bootstrap::wire()} so that this HTTP path
 * and the CLI script share exactly one bootstrap definition.
 *
 * The webhook route composes this ahead of {@see \Horde\Core\Middleware\ErrorFilter}
 * so bindings are ready before the request-handler runs, and errors
 * from listener wiring are caught by ErrorFilter rather than reaching
 * the client raw.
 *
 * Deliberately does not include HordeCore. The webhook receiver
 * authenticates by HMAC signature, not a Horde session, so pulling in
 * the legacy Horde_Registry stack (auth, prefs, page output) buys
 * nothing and slows every delivery.
 */
class SatisfiendBootstrap implements MiddlewareInterface
{
    public function __construct(
        private readonly Injector $injector,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        Bootstrap::wire($this->injector);

        return $handler->handle($request);
    }
}
