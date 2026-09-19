<?php
declare(strict_types=1);

namespace App\Application\Service\Command;

use Domains\Service\Application\Contract\ServiceApplicationBoundary;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class SetServiceSlaCommandHandler implements CommandHandlerInterface
{
    public function __construct(private ServiceApplicationBoundary $service) {}
    public function __invoke(SetServiceSlaCommand $command):array
    {
        return $this->service->setSla(
            $command->organizationId->value(),$command->actorId,$command->correlationId,
            $command->ticketId,$command->idempotencyKey,$command->input,
        );
    }
}
