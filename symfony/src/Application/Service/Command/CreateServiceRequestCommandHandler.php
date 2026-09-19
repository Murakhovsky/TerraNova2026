<?php
declare(strict_types=1);

namespace App\Application\Service\Command;

use Domains\Service\Application\Contract\ServiceApplicationBoundary;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class CreateServiceRequestCommandHandler implements CommandHandlerInterface
{
    public function __construct(private ServiceApplicationBoundary $service) {}
    public function __invoke(CreateServiceRequestCommand $command):array
    {
        return $this->service->createRequest(
            $command->organizationId->value(),$command->actorId,$command->correlationId,
            $command->idempotencyKey,$command->input,
        );
    }
}
