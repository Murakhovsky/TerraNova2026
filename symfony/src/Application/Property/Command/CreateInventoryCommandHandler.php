<?php
declare(strict_types=1);

namespace App\Application\Property\Command;

use App\Application\Property\Service\PropertyWriteService;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class CreateInventoryCommandHandler implements CommandHandlerInterface
{
    public function __construct(private PropertyWriteService $writes) {}
    public function __invoke(CreateInventoryCommand $command):array
    {
        return $this->writes->createInventory($command->organizationId,$command->actorId,$command->correlationId,$command->propertyReference,$command->idempotencyKey,$command->input);
    }
}
