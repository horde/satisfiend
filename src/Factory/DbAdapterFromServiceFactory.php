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

use Horde\Core\Service\HordeDbService;
use Horde\Db\Adapter;
use Horde\Injector\Injector;
use Horde_Core_Factory_Injector;

/**
 * Resolves {@see Adapter} via the modern {@see HordeDbService}, avoiding
 * the legacy {@see \Horde\Core\Factory\DbAdapterFactory} that requires a
 * fully-booted Horde registry (globals populated by
 * `Horde_Registry::appInit`). Satisfiend's webhook path deliberately
 * skips that boot step, so it must reach the DB through the modern
 * config-driven service instead.
 */
class DbAdapterFromServiceFactory extends Horde_Core_Factory_Injector
{
    public function create(Injector $injector): Adapter
    {
        return $injector->getInstance(HordeDbService::class)->getAdapter();
    }
}
