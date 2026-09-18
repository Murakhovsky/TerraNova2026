<?php
declare(strict_types=1);

namespace App\Application\Sales\Command;

use Domains\Sales\Application\Contract\SalesWriteServiceFactoryInterface;
use Domains\Sales\Application\DTO\ClientCaseCommandResult;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class UpdateSalesLeadCommandHandler implements CommandHandlerInterface
{
    public function __construct(private SalesWriteServiceFactoryInterface $writes)
    {
    }

    public function __invoke(UpdateSalesLeadCommand $command): ClientCaseCommandResult
    {
        return $this->writes->forOrganization($command->organizationId->value())->updateLead(
            $command->leadId,
            $command->input,
            $command->actorId,
        );
    }
}
