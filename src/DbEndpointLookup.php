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

use Horde\Db\Adapter;

class DbEndpointLookup implements EndpointLookupInterface
{
    public function __construct(
        private readonly Adapter $db,
    ) {}

    public function findBySlug(string $slug): ?Endpoint
    {
        $row = $this->db->selectOne(
            'SELECT slug, provider_type, secret, active, created_by, config_json'
                . ' FROM satisfiend_endpoints WHERE slug = ?',
            [$slug]
        );

        if ($row === false) {
            return null;
        }

        return new Endpoint(
            slug: $row['slug'],
            providerType: $row['provider_type'],
            secret: $row['secret'],
            active: (bool) $row['active'],
            createdBy: $row['created_by'],
            configJson: $row['config_json'],
        );
    }
}
