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

use InvalidArgumentException;

class WebhookVerifierFactory
{
    public function create(string $providerType, string $secret): WebhookVerifierInterface
    {
        return match ($providerType) {
            'github', 'gitea' => new GithubWebhookVerifier($secret),
            default => throw new InvalidArgumentException("Unknown provider type: $providerType"),
        };
    }
}
