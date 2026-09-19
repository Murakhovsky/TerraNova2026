<?php
declare(strict_types=1);

namespace App\Application\Service\Command;

use Domains\Service\Application\Contract\ServiceApplicationBoundary;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class CreateServiceTicketCommandHandler implements CommandHandlerInterface
{
    public function __construct(private ServiceApplicationBoundary $service) {}
    public function __invoke(CreateServiceTicketCommand $command):array
    {
        return $this->service->createTicket(
            $command->organizationId->value(),$command->actorId,$command->correlationId,
            $command->requestId,$command->idempotencyKey,$command->input,
        );
    }
}
