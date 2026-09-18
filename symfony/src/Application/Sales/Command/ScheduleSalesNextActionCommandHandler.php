<?php
declare(strict_types=1);

namespace App\Application\Sales\Command;

use Domains\Sales\Application\Contract\SalesWriteServiceFactoryInterface;
use Domains\Sales\Application\DTO\OperationResult;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class ScheduleSalesNextActionCommandHandler implements CommandHandlerInterface
{
    public function __construct(private SalesWriteServiceFactoryInterface $writes)
    {
    }

    public function __invoke(ScheduleSalesNextActionCommand $command): OperationResult
    {
        return $this->writes->forOrganization($command->organizationId->value())->scheduleNextAction(
            $command->opportunityId,
            $command->title,
            $command->body,
            $command->dueAt,
            $command->actorId,
            $command->correlationId,
            $command->idempotencyKey,
        );
    }
}
