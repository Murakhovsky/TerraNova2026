<?php
declare(strict_types=1);

namespace App\Application\Property\Command;

use App\Application\Property\Service\PropertyWriteService;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class CreatePropertyCommandHandler implements CommandHandlerInterface
{
    public function __construct(private PropertyWriteService $writes) {}
    public function __invoke(CreatePropertyCommand $command):array
    {
        return $this->writes->create($command->organizationId,$command->actorId,$command->correlationId,$command->idempotencyKey,$command->input);
    }
}
