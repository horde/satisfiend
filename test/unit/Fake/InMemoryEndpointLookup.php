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

use Horde\Satisfiend\Endpoint;
use Horde\Satisfiend\EndpointLookupInterface;

final class InMemoryEndpointLookup implements EndpointLookupInterface
{
    /**
     * @param array<string,Endpoint> $endpoints keyed by slug
     */
    public function __construct(private array $endpoints = []) {}

    public function findBySlug(string $slug): ?Endpoint
    {
        return $this->endpoints[$slug] ?? null;
    }

    public function add(Endpoint $endpoint): void
    {
        $this->endpoints[$endpoint->slug] = $endpoint;
    }
}
