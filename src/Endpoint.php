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

final readonly class Endpoint
{
    public function __construct(
        public string $slug,
        public string $providerType,
        public string $secret,
        public bool $active,
        public string $createdBy,
        public ?string $configJson = null,
    ) {}
}
