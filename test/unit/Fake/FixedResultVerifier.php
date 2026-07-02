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

namespace Horde\Satisfiend\Test\Unit\Fake;

use Horde\Satisfiend\WebhookVerifierInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A verifier whose result is fixed at construction. Used by
 * {@see ToggleVerifierFactory} to substitute for the real
 * HMAC-based verifier in tests that focus on request-handling logic.
 */
final class FixedResultVerifier implements WebhookVerifierInterface
{
    public function __construct(private readonly bool $result) {}

    public function verify(ServerRequestInterface $request): bool
    {
        return $this->result;
    }
}
