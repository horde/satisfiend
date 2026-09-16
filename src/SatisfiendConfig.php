<?php

declare(strict_types=1);
/**
 * Satisfiend configuration class
 *
 * Provides access to the Satisfiend configuration settings.
 *
 * Old pattern: globals $conf; $somethingDetail = $conf['something']['detail']; *
 * New pattern: $config = $injector->get(SatisfiendConfig::class); $somethingDetail = $config->get('something.detail');
 *
 * Prefer DI over instantiating $config in your code.
 */

namespace Horde\Satisfiend;

use Horde\Core\Config\State;
use Horde\Injector\Attribute\Factory;

#[Factory(factory: SatisfiendConfigFactory::class, method: 'create')]
class SatisfiendConfig extends State {}
