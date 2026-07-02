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

namespace Horde\Satisfiend\Factory;

use Horde\Db\Adapter;
use Horde\Injector\Injector;
use Horde\Satisfiend\DbEndpointLookup;
use Horde\Satisfiend\EndpointLookupInterface;
use Horde_Core_Factory_Injector;

/**
 * Injector factory that binds {@see EndpointLookupInterface} to the
 * database-backed implementation.
 *
 * This is the only binding in the satisfiend registry that requires a
 * factory: it maps an interface to a concrete class the injector cannot
 * resolve on its own. Every other satisfiend service has a concrete
 * constructor whose typed parameters the injector resolves directly.
 */
class EndpointLookupFactory extends Horde_Core_Factory_Injector
{
    public function create(Injector $injector): EndpointLookupInterface
    {
        return new DbEndpointLookup(
            $injector->getInstance(Adapter::class),
        );
    }
}
