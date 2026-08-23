<?php
declare(strict_types=1);

namespace Kernel\Action\Contract;

use Kernel\Action\Action;
use Kernel\Action\ExecutionResult;

interface ActionHandlerInterface
{
    public function supports(string $actionType): bool;
    public function execute(Action $action): ExecutionResult;
}
