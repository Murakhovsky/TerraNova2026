<?php
declare(strict_types=1);

namespace Kernel\Action\Contract;

use Kernel\Action\Action;

interface ActionExecutionGateInterface
{
    /** Throw when the action is not executable in the current runtime context. */
    public function assertExecutable(Action $action): void;
}
