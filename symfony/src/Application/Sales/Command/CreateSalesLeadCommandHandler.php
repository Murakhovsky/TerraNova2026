<?php
declare(strict_types=1);

namespace App\Application\Sales\Command;

use Domains\Sales\Application\Contract\SalesWriteServiceFactoryInterface;
use Domains\Sales\Application\DTO\ClientCaseCommandResult;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class CreateSalesLeadCommandHandler implements CommandHandlerInterface
{
    public function __construct(private SalesWriteServiceFactoryInterface $writes)
    {
    }

    public function __invoke(CreateSalesLeadCommand $command): ClientCaseCommandResult
    {
        return $this->writes->forOrganization($command->organizationId->value())->createLead(
            $command->input,
            $command->actorId,
            $command->correlationId,
            $command->idempotencyKey,
        );
    }
}
