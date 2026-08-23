<?php
declare(strict_types=1);

namespace Domains\Sales\Crm\Contract;

use Domains\Sales\Crm\CreateTaskCommand;
use Domains\Sales\Crm\ExternalResult;

interface CrmGatewayInterface
{
    public function createTask(CreateTaskCommand $command): ExternalResult;
}
