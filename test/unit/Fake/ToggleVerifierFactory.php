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

use Horde\Satisfiend\WebhookVerifierFactory;
use Horde\Satisfiend\WebhookVerifierInterface;

/**
 * Replaces {@see WebhookVerifierFactory} with one that always returns
 * a verifier whose `verify()` result is fixed at construction. Removes
 * HMAC computation from unit tests that care about the surrounding
 * request-handling logic rather than the signature check itself.
 */
final class ToggleVerifierFactory extends WebhookVerifierFactory
{
    public function __construct(private readonly bool $result) {}

    public function create(string $providerType, string $secret): WebhookVerifierInterface
    {
        return new FixedResultVerifier($this->result);
    }
}
