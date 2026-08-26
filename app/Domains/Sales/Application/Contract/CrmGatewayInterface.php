<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

use Domains\Sales\Application\DTO\CreateTaskCommand;
use Domains\Sales\Application\DTO\OperationResult;

interface CrmGatewayInterface
{
    public function createTask(CreateTaskCommand $command): OperationResult;
}
