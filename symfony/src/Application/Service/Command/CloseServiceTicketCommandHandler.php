<?php
declare(strict_types=1);

namespace App\Application\Service\Command;

use Domains\Service\Application\Contract\ServiceApplicationBoundary;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class CloseServiceTicketCommandHandler implements CommandHandlerInterface
{
    public function __construct(private ServiceApplicationBoundary $service) {}
    public function __invoke(CloseServiceTicketCommand $command):array
    {
        return $this->service->close(
            $command->organizationId->value(),$command->actorId,$command->correlationId,
            $command->ticketId,$command->idempotencyKey,
        );
    }
}
