<?php
declare(strict_types=1);

namespace App\Application\Service\Command;

use Domains\Service\Application\Contract\ServiceApplicationBoundary;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class AssignServiceTicketCommandHandler implements CommandHandlerInterface
{
    public function __construct(private ServiceApplicationBoundary $service) {}
    public function __invoke(AssignServiceTicketCommand $command):array
    {
        return $this->service->assignTicket(
            $command->organizationId->value(),$command->actorId,$command->correlationId,
            $command->ticketId,$command->assigneeId,$command->idempotencyKey,
        );
    }
}
