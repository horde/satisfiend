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

use Horde\GithubApiClient\Webhook\WebhookSignatureVerifier;
use Psr\Http\Message\ServerRequestInterface;

class GithubWebhookVerifier implements WebhookVerifierInterface
{
    private readonly WebhookSignatureVerifier $verifier;

    public function __construct(string $secret)
    {
        $this->verifier = new WebhookSignatureVerifier($secret);
    }

    public function verify(ServerRequestInterface $request): bool
    {
        $signature = $request->getHeaderLine('X-Hub-Signature-256');
        $body = (string) $request->getBody();

        return $this->verifier->verify($body, $signature);
    }
}
