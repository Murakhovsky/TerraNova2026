<?php
declare(strict_types=1);

namespace App\Application\Property\Command;

use App\Application\Property\Service\PropertyWriteService;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class UpdatePropertyCommandHandler implements CommandHandlerInterface
{
    public function __construct(private PropertyWriteService $writes) {}
    public function __invoke(UpdatePropertyCommand $command):array
    {
        return $this->writes->update($command->organizationId,$command->actorId,$command->correlationId,$command->reference,$command->input);
    }
}
