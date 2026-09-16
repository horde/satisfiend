<?php

declare(strict_types=1);
/**
 * Satisfiend configuration class factory
 *
 * Creates instances of the SatisfiendConfig class.
 *
 * Old pattern: globals $conf; $somethingDetail = $conf['something']['detail']; *
 * New pattern: $config = $injector->get(SatisfiendConfig::class); $somethingDetail = $config->get('something.detail');
 *
 * Prefer DI over instantiating $config in your code.
 */

namespace Horde\Satisfiend;

use Horde\Core\Config\ConfigLoader;
use Horde\Injector\Injector;

class SatisfiendConfigFactory
{
    public function __construct(private Injector $injector) {}

    public function create(): SatisfiendConfig
    {
        $state = $this->injector->get(ConfigLoader::class)->load('satisfiend');
        return new SatisfiendConfig($state->toArray());
    }
}
