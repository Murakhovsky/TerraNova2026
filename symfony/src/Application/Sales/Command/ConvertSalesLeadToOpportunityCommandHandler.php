<?php
declare(strict_types=1);

namespace App\Application\Sales\Command;

use Domains\Sales\Application\Contract\SalesWriteServiceFactoryInterface;
use Domains\Sales\Application\DTO\ClientCaseCommandResult;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class ConvertSalesLeadToOpportunityCommandHandler implements CommandHandlerInterface
{
    public function __construct(private SalesWriteServiceFactoryInterface $writes)
    {
    }

    public function __invoke(ConvertSalesLeadToOpportunityCommand $command): ClientCaseCommandResult
    {
        return $this->writes->forOrganization($command->organizationId->value())->convertLeadToOpportunity(
            $command->leadId,
            $command->input,
            $command->actorId,
            $command->correlationId,
        );
    }
}
