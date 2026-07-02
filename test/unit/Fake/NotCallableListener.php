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

/**
 * Class that ListenerLoader must reject: not callable. Used to verify
 * the "log and skip" branch when a configured class exists and is
 * constructible but does not implement `__invoke` or any other
 * callable interface.
 */
class NotCallableListener {}
