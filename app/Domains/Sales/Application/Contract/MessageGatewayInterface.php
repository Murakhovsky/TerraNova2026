<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Application\DTO\SendMessageCommand;

interface MessageGatewayInterface
{
    public function send(SendMessageCommand $command): OperationResult;
}
