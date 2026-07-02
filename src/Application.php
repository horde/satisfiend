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

use Horde_Registry_Application;

if (!defined('SATISFIEND_BASE')) {
    define('SATISFIEND_BASE', realpath(__DIR__ . '/..'));
}

if (!defined('HORDE_BASE')) {
    if (file_exists(SATISFIEND_BASE . '/config/horde.local.php')) {
        include SATISFIEND_BASE . '/config/horde.local.php';
    } else {
        define('HORDE_BASE', realpath(SATISFIEND_BASE . '/..'));
    }
}

require_once HORDE_BASE . '/lib/core.php';

/**
 * Legacy `type: horde-application` shell class.
 *
 * Retained so `Horde_Registry` can discover satisfiend as a registered
 * app (registry snippets reference this class), but satisfiend's
 * runtime wiring lives entirely in
 * {@see Bootstrap::wire()}. The web path invokes it
 * through {@see Middleware\SatisfiendBootstrap}; the
 * CLI script invokes it through
 * {@see Cli\BootstrapCli}. Neither calls
 * `Horde_Registry::appInit`, so `_bootstrap()` is intentionally a
 * no-op here.
 *
 * If a legacy `appInit`-based caller ever needs satisfiend wiring, it
 * can call `Bootstrap::wire($injector)` directly from its own path
 * without reviving stateful code in this method.
 */
class Application extends Horde_Registry_Application
{
    public $version = '1.0.0-alpha1';

    protected function _bootstrap(): void
    {
        // Deliberately empty. See class docblock.
    }
}
