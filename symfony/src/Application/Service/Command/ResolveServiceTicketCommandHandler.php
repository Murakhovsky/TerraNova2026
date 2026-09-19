<?php
declare(strict_types=1);

namespace App\Application\Service\Command;

use Domains\Service\Application\Contract\ServiceApplicationBoundary;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class ResolveServiceTicketCommandHandler implements CommandHandlerInterface
{
    public function __construct(private ServiceApplicationBoundary $service) {}
    public function __invoke(ResolveServiceTicketCommand $command):array
    {
        return $this->service->resolve(
            $command->organizationId->value(),$command->actorId,$command->correlationId,
            $command->ticketId,$command->summary,$command->idempotencyKey,
        );
    }
}
