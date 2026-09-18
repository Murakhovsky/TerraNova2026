<?php
declare(strict_types=1);

namespace App\Application\Property\Command;

use App\Application\Property\Service\PropertyWriteService;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class ChangeInventoryStatusCommandHandler implements CommandHandlerInterface
{
    public function __construct(private PropertyWriteService $writes) {}
    public function __invoke(ChangeInventoryStatusCommand $command):array
    {
        return $this->writes->changeInventoryStatus(
            $command->organizationId,$command->actorId,$command->correlationId,$command->inventoryId,$command->status,$command->reason,
        );
    }
}
