<?php
declare(strict_types=1);

namespace App\Application\Service\Command;

use Domains\Service\Application\Contract\ServiceApplicationBoundary;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class EscalateServiceTicketCommandHandler implements CommandHandlerInterface
{
    public function __construct(private ServiceApplicationBoundary $service) {}
    public function __invoke(EscalateServiceTicketCommand $command):array
    {
        return $this->service->escalate(
            $command->organizationId->value(),$command->actorId,$command->correlationId,
            $command->ticketId,$command->reason,$command->idempotencyKey,
        );
    }
}
