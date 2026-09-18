<?php
declare(strict_types=1);

namespace App\Application\Property\Command;

use App\Application\Property\Service\PropertyWriteService;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class ReserveInventoryCommandHandler implements CommandHandlerInterface
{
    public function __construct(private PropertyWriteService $writes) {}
    public function __invoke(ReserveInventoryCommand $command):array
    {
        return $this->writes->reserveInventory(
            $command->organizationId,$command->actorId,$command->correlationId,$command->inventoryId,$command->idempotencyKey,$command->input,
        );
    }
}
